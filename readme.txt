=== Tainacan Index Manager ===
Contributors: marcossigismundo
Tags: tainacan, elasticsearch, opensearch, elasticpress, search, indexing
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Painel de saúde da busca, monitoramento de Elasticsearch/OpenSearch, integração com ElasticPress e indexador próprio para repositórios Tainacan.

== Description ==

O Tainacan Index Manager integra-se ao Tainacan e oferece um painel completo para acompanhar a saúde da busca em grandes repositórios digitais.

Recursos:

* Painel "Tainacan > Saúde da Busca" com cards de status, indicadores e tabela de cobertura por coleção.
* Cliente seguro para Elasticsearch/OpenSearch baseado em wp_remote_* (Basic Auth ou API Key).
* Monitoramento periódico (WP-Cron) de cluster_health, índices e divergência entre Tainacan e o índice.
* Detecção automática do ElasticPress; quando ativo, o plugin opera em modo somente leitura sobre ele.
* Indexador próprio em fallback, com mappings/analyzers otimizados para português brasileiro, processamento em lote, pausar/retomar/cancelar.
* Reescrita opcional da busca do WordPress/Tainacan para usar o índice, com fallback automático para SQL quando o Elasticsearch falhar.
* Sistema de alertas (painel + e-mail) com classificação informativo/atenção/crítico.
* Tabela própria de logs (dbDelta), com retenção configurável.
* REST API protegida por nonce e cookie auth.

== Installation ==

1. Faça upload da pasta `tainacan-index-manager` para `wp-content/plugins/`.
2. Ative o plugin no painel de Plugins do WordPress.
3. Acesse **Tainacan > Configurações de Indexação** e informe URL/credenciais do seu Elasticsearch ou OpenSearch.
4. Clique em **Testar conexão** e depois em **Criar índice**.
5. Use **Indexar tudo** para popular o índice e abra **Tainacan > Saúde da Busca** para acompanhar.

== Frequently Asked Questions ==

= O plugin funciona com Elasticsearch e OpenSearch? =

Sim. Os endpoints utilizados (`/_cluster/health`, `/_stats`, `/_doc`, `/_bulk`, `/_search`, `/_count`, `/_refresh`) são compatíveis com ambos.

= Preciso do ElasticPress? =

Não. O plugin pode operar com o indexador próprio. Se o ElasticPress estiver ativo, o plugin se integra a ele em modo somente leitura.

= O que acontece se o Elasticsearch ficar offline? =

A busca degrada automaticamente para SQL, um alerta é levantado e o evento é registrado nos logs.

== Changelog ==

= 1.1.8 =
* Sem mais "logs zumbis" do tipo `Erro HTTP retornado por Elasticsearch` para cada coleção quando o
  índice ainda não foi criado. `Collections_Monitor` agora faz uma única verificação `index_exists()`
  no início do relatório; se o índice ainda não existe, todas as coleções recebem `indexed=0`
  silenciosamente, sem chamadas extra ao `_count` e sem entradas de log.
* Mensagens de erro do cliente Elasticsearch agora carregam o `error.type` + `error.reason` extraídos
  do body JSON do ES (com drill em `caused_by.reason` quando presente). No log isso aparece no título
  da entrada (ex.: `Elasticsearch HTTP 404 em /tainacan_items/_count — index_not_found_exception`)
  e no `WP_Error::get_error_message()` retornado a quem chamou o cliente.

= 1.1.7 =
* Renomeada a página principal de "Saúde da Busca" para **"Gestão da Indexação"** — escopo melhor descrito,
  tanto no menu do Tainacan quanto no fallback standalone, no header da página e no link de ação do plugin.
* Painel reorganizado em **abas**: Visão geral, Indexação, Coleções, Alertas, Logs, Integrações.
  A caixa de diagnóstico fica acima das abas como resumo permanente. A aba Alertas exibe um *badge* com
  a contagem atual de alertas ativos. Trocas de aba destroem/recriam o conteúdo via `v-if` para garantir
  que sparklines (canvas) recalculem dimensões ao aparecer.
* O link de ação na listagem de plugins agora aponta para o slug correto da página
  (`tainacan_idxmgr_dashboard`) — antes apontava para um slug obsoleto.

= 1.1.6 =
* Aceita URLs com credenciais embutidas no formato `http://user:senha@host:porta` — inclusive senhas
  que contenham `@`, como `http://elastic:Elastic@Tainacan@elasticsearch.tainacan.svc.cluster.local:9200`.
  O parser usa regex greedy até o último `@` antes do host, evitando a ambiguidade que faz `parse_url`
  retornar lixo em senhas com `@`.
* Ao salvar Configurações, o plugin agora **extrai automaticamente** as credenciais embutidas da URL
  e migra para os campos "Usuário" e "Senha". A URL salva fica limpa. O painel exibe mensagem confirmando
  a migração. Importante porque `wp_remote_*` não traduz userinfo da URL para header `Authorization` —
  sem esta migração, o plugin ia silenciosamente fazer requests sem credenciais.
* O cliente HTTP também tem fallback inline: mesmo sem a migração, requisições continuam autenticadas
  se a URL trouxer userinfo.
* "Testar conexão" passa a informar **URL testada** (sem userinfo) e **método de autenticação detectado**
  (API Key / Basic Auth campos próprios / Basic Auth inline / sem autenticação), facilitando diagnóstico.
* Diagnóstico ganhou regras específicas para falha de conexão:
  - HTTP 401/403 → "Elasticsearch recusou as credenciais"
  - DNS / `getaddrinfo` → "Host do Elasticsearch não resolve" (com dica de Kubernetes)
  - Timeout / connection refused → "Inalcançável — verificar serviço/firewall"

= 1.1.5 =
* Nova caixa de **Diagnóstico** no topo do painel: avalia conectividade, índice, cluster, latência,
  cobertura, fila, taxa de sucesso e estado do indexador e produz um texto por finding, em PT-BR,
  com a ação recomendada (e link para "Configurações" quando aplicável).
* A severidade global é o pior achado: "Tudo certo", "Informativo", "Atenção", ou "Ação imediata".
* Em instalação com 1 nó (single-node), Cluster YELLOW agora é classificado como `info` ("é esperado")
  em vez de `warning` — ajusta o tom para ambientes de desenvolvimento e institucionais menores.
* Refeita a seção ElasticPress: quando o plugin não está ativo, a mensagem deixa de soar como problema
  e explica que o cenário é suportado (o indexador próprio está em operação).
* Nova rota REST `/diagnostics` (read-only, requer `manage_options`).

= 1.1.4 =
* Diagnóstico de falhas do `_bulk`: o `Indexer` agora **extrai e exibe o motivo real** retornado pelo
  Elasticsearch (campo `error.type` + `error.reason`, drillando em `caused_by.reason` quando presente).
  Agrupa por tipo de erro, gera uma entrada de log `error` por tipo por lote (volume limitado) e expõe um
  resumo na resposta de `/metrics`. O painel mostra uma nova seção "Motivo das falhas no último lote" com
  contagem por tipo e o detalhe completo do ES em `<details>`.
* Corrige falha sistemática de indexação causada por valores `false`/`null` em campos tipados do mapping
  (`permalink`, `thumbnail` como `keyword`; `date_created`, `date_modified` como `date`). Posts em rascunho
  ou sem thumbnail faziam `get_permalink()` / `get_the_post_thumbnail_url()` retornar `false`, e o ES
  rejeitava o documento inteiro com `mapper_parsing_exception`. Agora:
  - strings vazias substituem `false` em `permalink` e `thumbnail`;
  - campos de data são **omitidos** do documento quando WP não consegue produzir uma string ISO 8601
    válida (ES aceita ausência, mas rejeita `false`/`null` em campo `date`).

= 1.1.3 =
* Corrige flood de logs do canal `alert`. Desde 1.1.2 o painel passou a chamar `reevaluate_alerts()`
  em todo polling (a cada ~7s), e `Alerts::raise()` gerava log + tentativa de e-mail a cada chamada,
  mesmo quando nada havia mudado. Agora `raise()` é idempotente: só registra log e tenta enviar
  e-mail em *transições* (alerta novo, mudança de severidade ou de mensagem); para condições
  persistentes apenas atualiza `last_seen`. `count` passa a refletir transições, não chamadas.
* `Cron::run_health_tick()` agora produz uma única entrada de log por execução (canal `health`)
  em vez de duas, reduzindo ruído.

= 1.1.2 =
* Corrige "alerta zombie": `es_not_configured` (ou similares) deixava de ser limpo após o usuário configurar o ES.
  Causa: o painel disparava `/health?refresh=1` e `/alerts` em paralelo via `Promise.all`, e `/alerts` retornava
  antes de `evaluate_alerts` rodar. Solução: o painel agora sequencia `/health` antes do resto, e o endpoint
  `/alerts` força `Health_Service::reevaluate_alerts()` antes de serializar a resposta.
* Corrige "Pico da fila = 0" quando havia 29 mil itens enfileirados: o pico só era atualizado em `record_run()`
  (após processar um lote). Agora `Indexer::enqueue/enqueue_all/enqueue_collection` chamam
  `Indexer_Metrics::observe_queue_size()`. O card também mostra `max(atual, pico_registrado)`, eliminando o
  caso "0 com fila enorme".
* Polling do painel passa a atualizar `/health`, `/alerts`, `/metrics` e `/index/state` a cada 7s (antes só
  `/metrics`). O custo no servidor é absorvido pelo cache transient de 60s do snapshot de saúde.
* Painel exibe aviso quando há fila pendente e nenhum lote rodou ainda, sugerindo "Processar lote" ou ativar
  o processamento automático.
* Coluna "Indexado" da tabela de coleções agora mostra "erro" (com tooltip do motivo) quando o `_count` falha,
  em vez de "—" silencioso. Falhas também viram entradas no log (canal `health`, nível warning).

= 1.1.1 =
* HOTFIX CRÍTICO: corrige fatal error "Access level to TIM_*_Page::init() must be public" introduzido em 1.1.0.
  As subclasses de `\Tainacan\Pages` declaravam `init()` como `protected`, mas o método na classe pai é `public`,
  o que é uma redução de visibilidade — fatal em PHP. Agora declaradas como `public`.
* `Plugin::boot()` agora captura qualquer `\Throwable` durante a inicialização e mostra um admin notice
  em vez de derrubar o WordPress.
* `Admin_Page::tainacan_pages_available()` agora verifica via Reflection que `\Tainacan\Pages::init` é
  realmente público antes de tentar a integração nativa, caindo em fallback standalone se a visibilidade
  mudar no futuro.
* `Admin_Page::register()` tenta carregar as Tainacan Pages em try/catch; em qualquer falha cai em fallback.

= 1.1.0 =
* Integração nativa com o admin do Tainacan via `\Tainacan\Pages` (Tainacan 1.0.0+).
* "Saúde da Busca" agora aparece como item próprio do menu raiz do Tainacan.
* "Configurações de Indexação" entra no submenu "Outros" do Tainacan.
* Modo standalone automático quando o Tainacan não está disponível.
* Novo módulo `Indexer_Metrics`: throughput (itens/s), ETA, taxa de sucesso, lote médio, sparklines das últimas 50 runs, distribuição lifetime (indexado/falha/dropped/skipped), top N de itens com mais falhas, pico de fila.
* Novas rotas REST `/metrics` (com janela móvel configurável) e `/metrics/reset`.
* Polling automático das métricas a cada 7 segundos no painel.

= 1.0.0 =
* Versão inicial: painel de saúde, indexador próprio, integração com ElasticPress, alertas, logs, fallback SQL.
