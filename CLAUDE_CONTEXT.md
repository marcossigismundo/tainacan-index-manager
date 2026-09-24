# Tainacan Index Manager - Contexto do Projeto

## O que é o plugin

Plugin WordPress que acelera o Tainacan em coleções grandes sem depender de plugins de indexação de terceiros: indexa os itens no próprio Elasticsearch/OpenSearch e roteia listagem, facetas e busca do Tainacan pelo índice, com fallback automático para SQL sempre que a tradução não puder ser feita com fidelidade total ou o ES falhar. Traz também um painel com semáforo (`Tainacan > Outros > Gestão da Indexação`), o vocabulário da busca (sinônimos etc.) e um indexador próprio com fila, retry e métricas.

O README cobre a arquitetura em detalhe (schema do índice, tradução de queries, endpoints REST). Este arquivo registra o que não está no código nem no README: o estado real em produção, decisões tomadas sob pressão de um diagnóstico ao vivo, e os bugs que só apareceram rodando contra dados reais.

## Versão atual e onde está o trabalho

**v1.3.1** — branch `main` (o `feat/es-routing-facetas` foi mesclado em 24/09/2026). As seções do fim deste arquivo descrevem a 1.3.0 (semáforo, menu, vocabulário), as listas geradas e a 1.3.1 (busca nos metadados e completar palavras). Repositório: `github.com/marcossigismundo/tainacan-index-manager`.

Este branch é o resultado de uma sessão de diagnóstico + implementação ao vivo em produção (`agregador.museus.gov.br`, ~37 mil itens, 1 coleção principal). Antes dele, o plugin tinha índice populado e **nunca usado** — ver seção "Como chegamos aqui".

O plugin roda hoje em **duas instalações**: o agregador (produção) e o `brasiliana3` (servidor de testes, índice `brasiliana3_items_v1`, ~74 mil itens). O painel de saúde foi corrigido em 22/09/2026 com o brasiliana3 como caso de teste — ver "O Elasticsearch é compartilhado".

## Como chegamos aqui: o diagnóstico original

O pedido foi "por que o Tainacan continua lento mesmo com o plugin instalado". A causa raiz tinha duas camadas:

1. **Configuração**: `engine` estava num valor que delegava a busca a um plugin de indexação de terceiros, que **não estava ativo** no site. O código antigo de `Search_Integration` via esse valor e se desligava por completo ("o outro plugin vai cuidar disso") — só que ninguém cuidava. Toda busca caía em SQL puro, com o índice ES parado ao lado, populado e inútil.
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

10. **O painel julgava o cluster inteiro, não o índice do plugin.** O `Health_Service` lia `_cluster/health` global: bastava um índice de outro sistema no mesmo Elasticsearch ter shard não alocado para o painel reportar "Cluster em estado YELLOW" e **rebaixar o `overall_status` do site inteiro**, com a busca do Tainacan perfeita. Foi o que aconteceu no brasiliana3 em 22/09/2026. Pior: o alerta subia como `SEV_WARNING` sem olhar `number_of_nodes`, então **instalação de nó único nunca ficava verde** — e o `Diagnostics` já tratava esse mesmo caso como `info` desde sempre, ou seja, as duas classes discordavam entre si dentro do plugin. Corrigido com `Elasticsearch_Client::index_health()` (`_cluster/health/<índice>`) e `Health_Service::shard_status()`, que prefere o índice próprio e desconta o amarelo de nó único; cluster pior que o nosso índice virou achado informativo em vez de aviso. **Lição**: num cluster compartilhado, status global é informação de vizinho — perguntar sempre pelo recurso que é seu.

## Simplificação do `engine`: `elasticsearch` / `sql`

O campo tinha quatro valores que descreviam **quem monta o índice**, não **quem responde a consulta** — daí a confusão real do usuário do plugin (`own_indexer` já *é* Elasticsearch, e o valor que delegava a outro plugin parecia "o Elasticsearch de verdade"). Reduzido a dois: `elasticsearch` (índice deste plugin responde) e `sql` (nada é roteado). Na 1.3.0 saiu também a detecção de plugin de terceiros (a pedido do Marcos, sem nenhuma menção no código): a única proteção contra dois plugins reescrevendo a mesma `WP_Query` é a checagem genérica `null !== $posts` no início de `posts_pre_query`.

Valores antigos são migrados **na leitura** (`Settings::normalize_engine()`), não só ao salvar — uma instalação existente continua funcionando com o valor legado no banco, sem exigir reconfiguração nem esperar a próxima gravação.

## O Elasticsearch é compartilhado (e o que isso implica)

Levantado em 22/09/2026 investigando o alerta YELLOW no brasiliana3. Um único Elasticsearch serve o parque inteiro do IBRAM: `elasticsearch.tainacan.svc.cluster.local:9200`, namespace `tainacan`, acessível por `crictl exec` em qualquer pod WordPress do nó `172.30.11.99`.

**É um cluster de um nó só** (`number_of_nodes: 1`). Consequência estrutural: qualquer índice criado com `number_of_replicas >= 1` deixa todas as réplicas `UNASSIGNED` para sempre, porque não existe segundo nó onde alocá-las. O cluster fica **YELLOW permanente, com a busca 100% funcional**. Vale gravar a definição, porque ela é o que autoriza tratar isso como benigno: `YELLOW` significa que **todos os shards primários estão alocados**; num nó único, portanto, só pode estar faltando réplica. `RED` é que é perda real.

Dos 19 índices vivos no cluster, **três** são deste plugin (`brasiliana3_items_v1`, `tainacan_items`, `tainacan_items_v2`) — todos criados com `number_of_replicas: 0` em `Index_Manager::index_definition()`, portanto sempre green. Os demais são de outros sistemas: `*-post-1` é a convenção de nome de um plugin de indexação de terceiros usado por outros sites (de `agregadormuseusgovbr`, `mhnacervosmuseusgovbr`, `brasilianahmuseusgovbr`, `wptwordpressmuseusgovbr`), `atom_*` é do AtoM, e `brasiliana_lod_vetores` é do plugin brasiliana-lod.

Os 10 shards não alocados que disparavam o alerta eram réplicas de dois índices `*-post-1` (`agregadormuseusgovbr-post-1`, vazio desde 07/05/2026, e `mhnacervosmuseusgovbr-post-1`, 1,5 M docs e 5,1 M buscas). Nenhum pod do nó tinha o plugin que os criou ativo — são resíduo de sites que o largaram. Resolvido com `PUT _settings {"number_of_replicas":0}` nos dois: cluster foi a GREEN, e zerar réplica **não apaga nada**, porque um shard `UNASSIGNED` não existe em disco — é só a expectativa de uma segunda cópia que nunca pôde ser criada. Se esse plugin voltar a ser ativado e recriar os índices do zero, ele pede réplica de novo; correção permanente seria um index template no cluster.

**Armadilha de contagem:** `_cat/indices` e `_stats` reportam docs do Lucene, que inclui um documento por sub-objeto `nested`. O `brasiliana3_items_v1` aparece com 1.627.832 docs para ~74 mil itens reais. O `index_doc_count` do snapshot não cai nessa: ele vem de `_count`, que conta só documentos-raiz (74.401). Qualquer conferência feita direto no `_cat` ou no `_stats` engana — foi o mesmo erro que fazia a cobertura ler 5000%+ antes da 1.2.0.

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

Dois detalhes práticos confirmados em 22/09/2026:
- **Autenticação SSH por senha é recusada** no nó; só a chave PuTTY (`KeyPair-ibram 3.ppk`) funciona, via `plink`/`pscp`.
- **`wp eval` com PHP inline por dentro de `crictl exec` é inviável** — três níveis de escape (shell local → plink → shell do contêiner) tornam o quoting intratável. Enviar o PHP como arquivo (`cat script.php | plink ... "crictl exec -i CID sh -c 'cat > /tmp/x.php'"`) e rodar `wp eval-file /tmp/x.php`.

## Estado do site de referência (agregador.museus.gov.br)

- `engine=elasticsearch`, `index_name=tainacan_items_v2`, `route_item_lists=true`, `route_facets=true`.
- Índice novo criado do zero (não migrado do antigo) com o schema ampliado; reindexação completa sem falhas.
- Índice antigo `tainacan_items` (95,9 MB, com os documentos órfãos) mantido como rollback — ainda não removido; decisão de apagá-lo é do Marcos.
- Ganhos medidos e confirmados por `query_total`: listagem de coleção de ~3,5s para ~0,3-0,5s; facetas pequenas de ~5-8s para ~0,2-0,3s; busca textual de ~6-8s para ~0,3-0,6s. Facetas de altíssima cardinalidade (dezenas de milhares de valores distintos, ex. número de registro) permanecem em SQL, sem regressão.
- Busca textual muda o **recall**, não só a velocidade: SQL usa `LIKE '%termo%'` (substring em qualquer lugar), ES usa análise com stemming PT-BR. As contagens de resultado divergem entre os dois — esperado ao trocar de motor, mas vale validar com a equipe de acervo antes de considerar definitivo.

## Estado do servidor de testes (brasiliana3)

Conferido em 22/09/2026, com o painel corrigido já em pé (deploy manual dos quatro arquivos, md5 conferido, `php -l` no servidor, `touch` no arquivo principal):

- `engine=elasticsearch`, `index_name=brasiliana3_items_v1`, `effective_engine=elasticsearch` — o roteamento está de fato respondendo.
- `index_status=green`, `cluster_status=green`, `single_node_cluster=true`, `overall_status=ok`, **zero alertas**.
- 74.401 docs no índice para 74.123 itens no Tainacan → cobertura 100,38%. Os **278 documentos a mais são órfãos**: mesmo padrão já registrado no agregador (item excluído cuja remoção do índice falhou). A `divergence_pct` é calculada como `max(0, 100 - coverage)`, então excesso **não** dispara alerta nenhum — divergência por sobra é invisível no painel, de propósito ou não. Vale decidir.
- Backup dos arquivos substituídos em `/tmp/tim-bkp-20260922/` dentro do contêiner `wp-brasili-3` (volátil, some no próximo restart do pod).

**Validação do `shard_status()` foi por teste unitário, não em cluster amarelo de verdade.** Depois de zerar as réplicas, o cenário de falha deixou de existir, e forçar o amarelo de volta significava mexer em recurso compartilhado. A decisão foi exercitada por reflexão sobre snapshots sintéticos — 10 casos (índice green + cluster yellow, índice yellow com 1 nó, índice yellow com 2+ nós, RED com 1 nó, snapshot antigo sem as chaves novas), todos passando. O que **não** foi executado é o achado informativo "Cluster compartilhado com outros sistemas" do `Diagnostics`: a condição é a mesma testada no `Health_Service`, mas ele só aparece com o cluster pior que o nosso índice, estado que acabou de ser eliminado. O repo não tem suíte de testes; esse script vale versionar quando houver.

## Pendências conhecidas

- Item em `trash` não é removido do índice (`before_delete_post` só dispara em exclusão permanente) — gap documentado no README, não corrigido.
- Índice antigo `tainacan_items` ainda não removido.
- Divergência de recall na busca textual (acima) não validada com a equipe de acervo.
- ~~PR do branch para `main`~~: mesclado diretamente em `main` em 24/09/2026, a pedido do Marcos (merge `--no-ff`, sem PR).
- **Correção do painel só está no brasiliana3.** O agregador (produção) continua com o `Health_Service` antigo — como o cluster agora está GREEN, ele não mostra o alerta, mas volta a mostrar se qualquer vizinho ficar amarelo de novo.
- **278 documentos órfãos no `brasiliana3_items_v1`** e divergência por excesso invisível no painel (ver seção acima).
- `agregadormuseusgovbr-post-1` continua no cluster, vazio desde 07/05/2026 — candidato a remoção, decisão do Marcos.
- Sem index template no cluster: índice novo criado por outro sistema com réplica traz o YELLOW de volta (agora sem afetar o painel deste plugin, que passou a olhar o próprio índice).
- Trabalho em aberto, **não commitado e guardado no `git stash`** ("WIP status probe", desde 24/09/2026, para não entrar no commit da 1.3.0 — recuperar com `git stash pop`): `ES_Query_Builder` ganhou o docblock e as constantes `STATUS_PROBE_TTL`/`STATUS_PROBE_PREFIX` do teste "nenhum post usa estes status", mas **o método que as usa ainda não existe** — as constantes estão órfãs. Motivação: na coleção de 74 mil itens a listagem do admin pede `pending` junto com os outros status, e desistir por causa dele jogava tudo no SQL (24 s para 12 itens, lista aparecendo vazia).

## 1.3.0: semáforo, menu em Outros, vocabulário da busca (24/09/2026)

Pedido do Marcos: semáforo "bonito e funcional" do Elasticsearch; o plugin como subitem de **Outros** no menu do Tainacan, como padrão; nenhuma menção a plugin de indexação de terceiros no código; upload de sinônimos, parônimos e afins muito bem explicado; instalar no brasiliana3; commit e push.

- **Semáforo** (`Traffic_Light`): uma classe só decide a cor para a tela, o cron e o ponto do menu, então os três nunca discordam. O menu lê a cor guardada na option `tainacan_idxmgr_light` — montar o menu nunca chama o ES. Fallback para SQL nos últimos 15 min pinta amarelo; o vocabulário é só informativo.
- **Menu**: as três páginas vão para `tainacan_other_links` por padrão; `menu_location=root` leva para o menu raiz.
- **Vocabulário** (`Search_Vocabulary`): o ES do IBRAM é 8.6, **sem** a API de sinônimos (8.10+). Caminho escolhido: sinônimos inline no analisador de busca + fechar/`PUT _settings`/reabrir. Funciona em qualquer ES e no OpenSearch, e não exige reindexar porque o analisador de indexação não muda.
- A ordem dos filtros foi decidida **medindo** num índice de laboratório (`tim_vocab_lab`, apagado depois), não por leitura de documentação. Achados:
  - sinônimo antes do stemmer não pega plural;
  - `asciifolding_preserve` antes do sinônimo invalida regras;
  - palavra vazia dentro de um termo ("rio de janeiro") faz o ES **descartar a regra em silêncio** com `lenient: true`;
  - sem `auto_generate_synonyms_phrase_query: false`, a expansão vira frase e perde documentos.
  Tudo isso está no docblock da classe e no README.
- Antes de tocar o índice real, `apply()` ensaia a definição estrita (`lenient: false`) num índice temporário `<índice>-vocab-check` e o apaga. O índice real é reaberto sempre, mesmo se o `PUT` falhar.
- **Medido no brasiliana3**: o índice fica fechado **4,7 s**, e a aplicação inteira leva ~13 s (a maior parte é o ensaio). A busca pública seguiu pelo ES (`query_total` +1 por consulta, sem fallback), ~0,3 s. Efeito real: "photographia" 10 → 1.494 itens, "Brazil" 972 → 5.409, "estampa" 677 → 852, "rio de janeiro" 1.124 → 1.268. A busca aproximada ("fotgrafia" → 1.554) foi testada ligada e **deixada desligada** (padrão).
- **Estado do brasiliana3 após a instalação:** a 1.3.0 está ativa, com um vocabulário de exemplo aplicado (8 regras):
  - gravura/estampa;
  - aquarela/aguarela;
  - quadro/pintura/tela;
  - photographia/fotografia;
  - pharmacia/farmácia;
  - Brazil/Brasil;
  - rio de janeiro/rj;
  - previlégio ⇒ privilégio.

  Cabe à equipe de acervo revisar ou apagar essas regras. O backup dos arquivos da 1.2.0 está em `/root/tim-bkp-20260924-155002/` no nó 172.30.11.99.
- A tela de admin não foi vista logada: foi renderizada localmente, no Edge headless, com respostas reais da API do brasiliana3. Os templates Vue foram compilados com `@vue/compiler-dom@3.4.27`, sem erro.
- `uninstall.php` foi corrigido de passagem: o autoloader dependia de `TAINACAN_INDEX_MANAGER_DIR`, que não existe na desinstalação.

## Listas de vocabulário geradas do acervo do brasiliana3 (24/09/2026)

Pedido do Marcos: estimar quantas regras cada lista comporta a partir do banco e depois gerar os quatro arquivos, prontos para subir pela tela. Arquivos em `docs/vocabulario-brasiliana3/`; como foram gerados está em `tools/vocabulario/` e no README ("Gerar listas a partir do acervo").

**Ainda não aplicados.** O Marcos vai subir os arquivos pela tela, aba por aba, com "Substituir por arquivo" — isso troca as 8 regras de exemplo que estão em uso. O ensaio foi feito com as etapas internas do plugin, por reflexão, num índice temporário `-vocab-dryrun` já apagado, **sem fechar o índice real**.

**Base e resultado.**
- Base: 74.124 itens publicados, 8,3 M palavras, 57.325 palavras distintas.
- Resultado: 1.641 regras ativas, que viram 1.629 depois da normalização; 82 termos com palavras vazias foram ajustados.
- Parse sem erro. Ensaio estrito aceito em 6 s.

**Calibração que importou.**
- A primeira estimativa, sem dicionário, tinha cerca de 63% de acerto nas grafias e 40% nos erros.
- Com o dicionário IME-USP e a lista de nomes próprios, as amostras ficaram quase limpas.
- A proporção de maiúsculas **engana**: "tesouro" é 98% maiúscula por causa dos títulos, então nome próprio é quem está na lista de nomes do dicionário.
- Pares que o cruzamento com o dicionário eliminou: lazer/laser, hera/era, Villa/vila, Penna/pena, captador/catador, Goya/Goiás.

**Bug encontrado pelo ensaio e corrigido.**
- O ensaio com 1.629 regras estourou o `es_timeout` de 5 s na criação do índice temporário. Com os arquivos subidos como estavam, a aplicação teria falhado nessa etapa, antes de tocar o índice real.
- Agora criar, fechar, abrir e configurar índice usam `Elasticsearch_Client::ANALYSIS_TIMEOUT` (120 s).
- `apply()` chama `ignore_user_abort(true)` e `set_time_limit(0)`, para que o PHP não morra com o índice fechado.
- A correção está instalada no brasiliana3.

**Limitação conhecida das correções.**
- A forma errada passa pelo stemmer, e o radical dela pode colidir com o de outra palavra. Exemplo: "formaro" vira "formar", então a busca por "formato" traz também itens com "formar": de 5.714 para 5.813 itens, com parte desse ganho sendo ruído.
- O efeito é pequeno, mas um filtro que rejeite formas erradas cujo radical coincida com o de uma palavra frequente diferente seria a melhoria natural do `gerar-listas.js` (exige rodar `_analyze` nas formas).

**Não mensurável pelo banco.**
- Os erros que o **público** comete ao buscar não aparecem no acervo. Para isso é preciso o registro das buscas do site: `ibram-analytics-collector` e `statify` estão ativos no brasiliana3, e valeria ver se algum deles guarda os termos buscados.
- Siglas sem forma por extenso no acervo e sinônimos do tesauro dependem de curadoria. Os 58 grupos do tesauro foram escritos à mão e filtrados por presença no acervo.

## 1.3.1: busca nos metadados e completar palavras (24/09/2026)

Pergunta do Marcos: "buscar fotogr deveria trazer fotografia?" Medido: não trazia — 0 itens sem a busca aproximada. Com ela ligada, trazia 1.551 itens: 74% dos de "fotografia" e mais 61 sem relação.

**Defeito antigo descoberto no caminho:** o `multi_match` listava `metadata.value_text` e `taxonomies.terms` no nível de cima, mas são `nested`. Então **a busca por texto nunca olhou os metadados**, desde a reescrita da 1.2.0. Consertado em `ES_Query_Builder::text_match()`. Efeito no brasiliana3:
- "fotografia": 2.010 → 5.330 itens;
- "aquarela": 61 → 363;
- "arte": 551 → 7.449.

**Completar palavras**: como decidido e medido está no README, seção "Busca por texto".
- Três estratégias testadas no índice real. "Prefixo sempre" inflava palavras completas: "arte" chegava a 6.337, "rio" a 9.414.
- Ficou o prefixo só quando a última palavra não existe no índice, ou quando a busca inteira não acha nada.
- A sonda roda **sem** busca aproximada; do contrário, "fotogr" "existiria" por semelhança e o prefixo nunca entraria.

**Busca aproximada** foi para `AUTO:5,8`. Com metadados no jogo, o `AUTO` puro fazia "casa" casar com caso, cada, cara e cama.
- O Marcos ligou a busca aproximada pela tela, e ela continua ligada no brasiliana3.
- Medido depois do ajuste: "fotgrafia" 4.116 itens, "pintrua" 3.604, "arte" 7.449 (exato).

**Estado do brasiliana3:**
- 1.3.1 instalada (backup da 1.3.0 em `/root/tim-bkp-20260924-171641/`).
- `search_prefix=true` e `search_typo_tolerance=true`.
- O vocabulário que o Marcos subiu (as listas de `docs/vocabulario-brasiliana3/`) está em uso.
- Todas as buscas medidas foram respondidas pelo ES (`query_total` sobe 2 a 3 por busca: a busca e as sondas), sem fallback, em ~0,3 s.

**Ponto a observar:** com os metadados incluídos, buscas de palavras comuns trazem bem mais itens do que antes ("casa" 15.550, "rio" 9.357). As ocorrências são reais, mas a busca do Tainacan ordena por data, não por relevância, então o que o público vê primeiro não é necessariamente o mais pertinente. Vale avaliar com a equipe se a busca textual deve ordenar por relevância.
