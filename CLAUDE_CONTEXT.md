# Tainacan Index Manager - Contexto do Projeto

## O que é o plugin

Plugin WordPress que substitui o ElasticPress como camada de aceleração do Tainacan em coleções grandes, sem depender dele: indexa os itens no próprio Elasticsearch/OpenSearch e roteia listagem, facetas e busca do Tainacan pelo índice, com fallback automático para SQL sempre que a tradução não puder ser feita com fidelidade total ou o ES falhar. Traz também um painel de saúde (`Tainacan > Saúde da Busca`) e um indexador próprio com fila, retry e métricas.

O README cobre a arquitetura em detalhe (schema do índice, tradução de queries, endpoints REST). Este arquivo registra o que não está no código nem no README: o estado real em produção, decisões tomadas sob pressão de um diagnóstico ao vivo, e os bugs que só apareceram rodando contra dados reais.

## Versão atual e onde está o trabalho

**v1.2.0** — branch `feat/es-routing-facetas` (ainda não mesclado em `main`), 9 commits. Repositório: `github.com/marcossigismundo/tainacan-index-manager`.

Este branch é o resultado de uma sessão de diagnóstico + implementação ao vivo em produção (`agregador.museus.gov.br`, ~37 mil itens, 1 coleção principal). Antes dele, o plugin tinha índice populado e **nunca usado** — ver seção "Como chegamos aqui".

## Como chegamos aqui: o diagnóstico original

O pedido foi "por que o Tainacan continua lento mesmo com o plugin instalado". A causa raiz tinha duas camadas:

1. **Configuração**: `engine` estava em `elasticpress`, mas o plugin ElasticPress **não estava ativo** no site. O código antigo de `Search_Integration` via esse valor e se desligava por completo ("ElasticPress vai cuidar disso") — só que ninguém cuidava. Toda busca caía em SQL puro, com o índice ES parado ao lado, populado e inútil.
2. **Arquitetural**: mesmo corrigindo o `engine`, o roteamento antigo só interceptava `is_search()` com `s` não vazio — ou seja, só a caixa de busca por palavra-chave. A navegação de coleção (abrir a coleção, aplicar facetas) nunca passava por `is_search()`, então o gargalo real — os JOINs de `postmeta`/termos por trás de facetas e listagem — nunca era tocado.

Achados secundários no mesmo diagnóstico: o índice tinha ~71 mil documentos para ~28 mil itens reais (documentos órfãos de itens já excluídos, nunca removidos porque a exclusão falhava silenciosamente), `_stats` reportava contagem de documentos Lucene (inclui um por sub-objeto `nested`) em vez de documentos reais, e o indexador rodava de forma agressiva sem que nada disso acelerasse coisa alguma.

## Reescrita: o que mudou de fato

- **`posts_pre_query`** no lugar de `pre_get_posts`+`post__in`: curto-circuita a `WP_Query` antes de qualquer SQL, cobre listagem/facetas/busca, e permite publicar `found_posts`/`max_num_pages` corretos (a versão antiga limitava a 200 hits via `post__in`, quebrando paginação).
- **`ES_Query_Builder`**: traduz `WP_Query` para DSL do ES com a regra "fidelidade acima de cobertura" — qualquer coisa que não saiba reproduzir exatamente (operador desconhecido, ordenação que o índice não sustenta, paginação além de `max_result_window`) devolve `null` e deixa o SQL rodar. Um resultado rápido e errado é pior que um lento e correto; essa regra pagou dividendos várias vezes durante a sessão (ver bugs abaixo).
- **`Facets_Integration`**: substitui o `SELECT DISTINCT meta_value` + um `Items::fetch()` completo por valor (o N+1 que o Tainacan roda nativamente) por uma agregação `nested`+`reverse_nested`, com ordenação final via ICU `Collator` para reproduzir a collation `utf8mb4_unicode_520_ci` do MySQL — ver "O problema de ordenação" abaixo.
- **Schema do índice ampliado**: `taxonomies.term_ids`, `metadata.metadatum_id`, `metadata.value_ids` (Tainacan filtra por ID, não por slug/nome) e `metadata.value_keyword` passou a guardar **todos** os valores de um metadado multivalorado — antes guardava só `$flat[0]`, perdendo os demais silenciosamente em filtros e facetas.
- **`own_indexer` só é possível reindexando do zero.** O índice não pode ser migrado no lugar quando o schema muda.

## Bugs encontrados só em produção (nenhum pego por `php -l` ou revisão estática)

Vale registrar estes porque o padrão se repetiu: cada um só apareceu ao medir de verdade contra o site real, nunca em revisão de código isolada.

1. **`orderby` em array recusado silenciosamente.** O Tainacan lista itens com `orderby => ['date' => 'DESC', 'ID' => 'DESC']` (forma associativa do WP), e a primeira versão do `build_sort()` só aceitava string. Resultado: **nenhuma** listagem de itens jamais foi roteada, em nenhum teste — mas sem erro nenhum, porque cair em SQL é o comportamento correto do fallback. Só foi pego comparando o contador `_stats/search.query_total` do próprio ES antes/depois de uma requisição: não subia, nunca. **Lição**: medir "está mais rápido" não prova que o ES foi consultado; sempre confirmar pelo contador do lado do servidor.
2. **Recursão infinita → HTTP 500 (memória esgotada) por ~1 minuto em produção.** `answer()` chama `collect_tainacan_post_types()`, que roda `Collections::fetch()` — um `WP_Query` que dispara `posts_pre_query` de volta na mesma classe. O código antigo nunca sofria disso porque o gate `is_search()` excluía consultas de coleção. Corrigido com `Search_Integration::without_routing()`, uma trava de reentrância estática.
3. **`$tolerate_codes` lido num método que não o recebia.** A tolerância a 404 (documento já ausente na exclusão) foi implementada em `parse_response()`, mas `request()` não repassava o parâmetro — `TypeError` em toda resposta de erro do ES, fatal dentro do hook de exclusão e engolido pelo `try/catch` do `Plugin::boot()` em outros pontos, desregistrando os hooks sem aviso nenhum.
4. **Ordenação de facetas divergia do MySQL.** Confirmado com dados reais: o topo alfabético de 20 valores de uma faceta era um **conjunto diferente** entre ES (`_key: asc`, ordem de byte UTF-8) e SQL (collation `utf8mb4_unicode_520_ci`, case/acento-insensível). Resolvido pedindo todos os valores distintos e ordenando em PHP com `Collator` (ICU) — só vale enquanto a lista cabe inteira na agregação (`sum_other_doc_count == 0`); do contrário, refuse e cai pro SQL.
5. **`perm=readable` rejeitado pelo modo estrito do builder.** O Tainacan manda esse parâmetro em `items_filter` das facetas; o builder em modo estrito recusava qualquer chave não modelada — isso sozinho mantinha **todas** as facetas em SQL mesmo com o roteamento ligado. Corrigido traduzindo `perm` para os `post_status` que o usuário pode ver (com um caso — "vê só os próprios itens privados" — que fica de propósito em SQL, por ser condição de autoria, não de status).
6. **`spl_object_id()` reciclado entre `WP_Query`s.** O total do ES ficava associado só ao id do objeto; como esses ids são reaproveitados pelo PHP e a entrada nunca era limpa para o `fields` padrão (WP não chama `set_found_posts()` após o curto-circuito), uma consulta nova podia herdar o total de outra sem relação — o sintoma no admin era "às vezes a lista aparece, às vezes fica vazia", porque o admin roda dezenas de `WP_Query` por requisição. Corrigido guardando a referência ao objeto junto com o total e conferindo identidade (`===`), não só o id.
7. **Permalinks de item quebrados.** `should_handle()` não distinguia uma listagem de uma busca por post específico. Todo permalink `/{coleção}/{slug}/` batia em `posts_pre_query`, o `ES_Query_Builder` ignorava `name`/`post_name` (só traduz `post__in`), e o índice devolvia a listagem inteira da coleção — o WordPress ficava com o primeiro resultado. Na prática: **todo** permalink de item na coleção abria o mesmo item (o mais recente indexado), com HTTP 200, e slugs inexistentes paravam de dar 404. Corrigido com `is_single_post_lookup()` excluindo `is_singular()`/`name`/`pagename`/`p`/`page_id`/`post_name__in` do roteamento.
8. **`Settings::update()` revertia configurações sozinho.** A opção é um array serializado único, e `update()` reescrevia ele inteiro a partir do cache em memória do objeto `Settings`. Um objeto de vida mais longa (o `Cron`, que grava `last_index_run_ts` a cada tick) regravava `engine`/`index_name` com o valor que tinham no momento em que **aquele** objeto foi construído — revertendo silenciosamente qualquer mudança feita por outro processo nesse meio-tempo. Foi a causa real da "lentidão intermitente" relatada depois do primeiro deploy: o roteamento se desligava e religava sozinho. Corrigido relendo a opção (`self::all()`) antes de mesclar o `$partial`.
9. **Ícone ausente derrubava o admin.** `\Tainacan\Traits\SVG_Icon::get_svg_icon()` chama `file_get_contents()` sem checar existência; o painel pedia o ícone `chart`, que não existe nem no Tainacan 1.0.3 nem no 1.1.0. O warning saía no meio do HTML do menu, e o WordPress emendava com "headers already sent" — cabeçalho do admin quebrado logo após ativar o plugin. Corrigido com `Tainacan_Icon::svg()` própria, que testa `is_readable()` antes de ler e usa ícones (`reports`, `settings`) confirmados presentes nas duas versões.

## Simplificação do `engine`: `elasticsearch` / `sql`

O campo tinha quatro valores (`auto`, `elasticpress`, `own_indexer`, `disabled`) que descreviam **quem monta o índice**, não **quem responde a consulta** — daí a confusão real do usuário do plugin ("por que não estou usando o ElasticPress?" quando `own_indexer` já *é* Elasticsearch, só que sem depender do plugin ElasticPress). Reduzido a dois: `elasticsearch` (índice deste plugin responde) e `sql` (nada é roteado). Suspender o roteamento enquanto o ElasticPress estiver ativo continua existindo, mas como segurança interna automática (dois plugins reescrevendo a mesma `WP_Query` entregam o resultado de quem rodar primeiro), não como opção que o usuário escolhe.

Valores antigos são migrados **na leitura** (`Settings::normalize_engine()`), não só ao salvar — uma instalação existente continua funcionando com o valor legado no banco, sem exigir reconfiguração nem esperar a próxima gravação.

## Metodologia de medição que funcionou (e a que não)

**Não confiar em tempo de resposta sozinho.** A primeira rodada de benchmarks "ES vs SQL" foi inteiramente inválida — o bug #1 acima (orderby em array) fazia tudo cair em SQL dos dois lados, e os tempos pareciam corroborar a comparação por coincidência de cache. A prova real é o contador `_stats/search.query_total` do próprio índice, checado antes/depois de cada requisição — só um delta positivo confirma que o ES respondeu.

**Validação de fidelidade byte a byte.** Toda mudança no roteamento foi validada comparando a resposta JSON completa (não só a contagem) entre SQL e ES para o mesmo filtro, antes de considerar a mudança pronta.

## Deploy: não há CI/CD, é tudo manual via SSH

Não existe pipeline. O ciclo de deploy usado nesta sessão:
1. Editar localmente em `C:\xampp82\htdocs\wordpress\wp-content\plugins\tainacan-index-manager` (mesmo caminho de sempre, mas os arquivos vão para produção via `cat arquivo | ssh ... "cat > destino"`, não hospedado ali).
2. `php -l` local antes de qualquer envio.
3. Conferir md5 local vs remoto após cada envio.
4. **Tocar (`touch`) o arquivo principal do plugin após qualquer deploy** — o OPcache do nó de produção não revalida timestamps de forma confiável entre chamadas próximas; sem isso, o PHP-FPM continuava servindo bytecode da versão anterior por vários segundos/minutos, o que já causou falsos negativos de diagnóstico mais de uma vez nesta sessão.
5. Nunca fazer `DELETE` em índice ou dado em produção sem antes confirmar rollback — o índice antigo (`tainacan_items`, com os órfãos) foi mantido ao lado do novo (`tainacan_items_v2`) exatamente por isso.

Peculiaridades do acesso ao cluster (fora deste repo, ver memória do agente — `reference_producao_ibram_k8s.md`): usar `crictl`, não `kubectl` (não há kubeconfig no nó); `wp eval`, não `wp db query`.

## Estado do site de referência (agregador.museus.gov.br)

- `engine=elasticsearch`, `index_name=tainacan_items_v2`, `route_item_lists=true`, `route_facets=true`.
- Índice novo criado do zero (não migrado do antigo) com o schema ampliado; reindexação completa sem falhas.
- Índice antigo `tainacan_items` (95,9 MB, com os documentos órfãos) mantido como rollback — ainda não removido; decisão de apagá-lo é do Marcos.
- Ganhos medidos e confirmados por `query_total`: listagem de coleção de ~3,5s para ~0,3-0,5s; facetas pequenas de ~5-8s para ~0,2-0,3s; busca textual de ~6-8s para ~0,3-0,6s. Facetas de altíssima cardinalidade (dezenas de milhares de valores distintos, ex. número de registro) permanecem em SQL, sem regressão.
- Busca textual muda o **recall**, não só a velocidade: SQL usa `LIKE '%termo%'` (substring em qualquer lugar), ES usa análise com stemming PT-BR. As contagens de resultado divergem entre os dois — esperado ao trocar de motor, mas vale validar com a equipe de acervo antes de considerar definitivo.

## Pendências conhecidas

- Item em `trash` não é removido do índice (`before_delete_post` só dispara em exclusão permanente) — gap documentado no README, não corrigido.
- Índice antigo `tainacan_items` ainda não removido.
- Divergência de recall na busca textual (acima) não validada com a equipe de acervo.
- PR do branch `feat/es-routing-facetas` para `main` ainda não aberto.
