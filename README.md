# Tainacan Index Manager

Plugin WordPress integrado ao [Tainacan](https://tainacan.org/) que oferece:

- Painel **Tainacan > Saúde da Busca** com indicadores de cluster, índice, cobertura e divergência.
- Monitoramento periódico (WP-Cron) de **Elasticsearch** e **OpenSearch**.
- Suspende o próprio roteamento (por segurança, não como recurso) enquanto o **ElasticPress** estiver ativo, com observação somente-leitura do estado dele.
- **Indexador próprio** com mappings/analyzers otimizados para português brasileiro, processamento em lote e controle pausar/retomar/cancelar.
- Roteamento da **listagem de itens e das facetas** do Tainacan para o índice, com **fallback automático para SQL** quando o ES falha.
- Sistema de **alertas** (painel + e-mail) e **logs** com tabela própria e retenção configurável.
- **REST API** protegida por nonce + cookie auth para todas as ações administrativas.

> Requisitos: WordPress 6.0+, PHP 7.4+, Tainacan (recomendado) e um cluster Elasticsearch 7.x/8.x ou OpenSearch 1.x/2.x.

---

## Instalação

1. Copie o diretório `tainacan-index-manager/` para `wp-content/plugins/`.
2. Ative o plugin em **Plugins** no admin do WordPress.
3. Abra **Tainacan > Configurações de Indexação** e preencha:
   - URL do Elasticsearch/OpenSearch (com protocolo e porta).
   - Usuário/senha (Basic Auth) **ou** API Key.
   - Nome do índice (padrão: `tainacan_items`).
4. Clique **Testar conexão**, depois **Criar índice**.
5. Clique **Indexar tudo** para popular.
6. Abra **Tainacan > Saúde da Busca**.

## Configuração

Todas as configurações ficam em uma única opção (`tainacan_index_manager_settings`, não autoload).

| Campo | Padrão | Observação |
|---|---|---|
| `engine` | `elasticsearch` | `elasticsearch` (consultas atendidas pelo índice) ou `sql` (sem indexação) |
| `es_url` | — | URL completa do cluster |
| `es_username` / `es_password` | — | Basic Auth |
| `es_api_key` | — | Alternativa ao Basic Auth (header `ApiKey ...`) |
| `index_name` | `tainacan_items` | Validado contra `[a-z0-9_-]` |
| `es_timeout` | 5 s | 1–60 |
| `batch_size` | 50 | 1–1000 |
| `batch_interval_seconds` | 1 | 0–600 |
| `auto_indexing_enabled` | false | Liga o cron de lote a cada minuto |
| `auto_check_frequency` | `hourly` | `tim_15min`, `tim_30min`, `hourly`, `tim_6hours`, `daily` |
| `divergence_threshold_pct` | 5 | 0–100 |
| `max_retries` | 3 | Por item antes de descartar |
| `alert_email_enabled` / `alert_email_address` | false / — | E-mail throttled (1 por código a cada hora) |
| `fallback_enabled` | true | Degrada para SQL quando ES falha |
| `route_item_lists` | true | Roteia listagem/navegação de coleção pelo índice |
| `route_facets` | true | Roteia as facetas pelo índice |
| `facet_max_terms` | 300 | Teto de buckets por faceta; acima disso, cai para SQL |
| `log_retention_days` | 30 | Cleanup diário |

Credenciais nunca são expostas pela REST (`__set__` indica "valor armazenado"). Logs aplicam scrub de chaves que contenham `password`, `secret`, `token`, `authorization`, `api_key`.

> **`Settings::update()` sempre relê antes de mesclar.** A opção é um único array
> serializado, e `update()` reescreve ele inteiro. Um objeto `Settings` de vida
> longa (um `Cron` que só grava `last_index_run_ts`, por exemplo) que gravasse a
> partir da cópia em memória feita na sua construção reverteria silenciosamente
> qualquer chave alterada por outro processo nesse meio-tempo — foi assim que
> `engine`/`index_name` voltavam sozinhos ao valor antigo minutos depois de
> salvos pelo painel, produzindo lentidão intermitente. `update()` chama
> `self::all()` (que lê a option de novo) antes de aplicar o `$partial`.

## Integração com o admin do Tainacan

A partir da versão 1.1.0 o plugin estende `\Tainacan\Pages` (introduzida no Tainacan 1.0.0) seguindo
o procedimento oficial documentado em
[creating-tainacan-admin-pages](https://tainacan.github.io/tainacan-wiki/#/dev/creating-tainacan-admin-pages):

- **Saúde da Busca** entra como item do menu raiz do Tainacan (posição 60) via `$this->tainacan_root_menu_slug`.
- **Configurações de Indexação** entra no submenu "Outros" (`$this->tainacan_other_links_slug`).
- Ícones via `Tainacan_Icon::svg()`, não o `$this->get_svg_icon()` do trait nativo:
  o trait chama `file_get_contents()` sem checar se o arquivo existe, e os ícones
  `chart`/`dashboard` não existem nem no Tainacan 1.0.3 nem no 1.1.0 — o warning
  saía impresso no meio do HTML do menu e o WordPress emendava com "headers
  already sent", derrubando o cabeçalho do admin inteiro logo após ativar o
  plugin. `Tainacan_Icon::svg()` resolve a mesma pasta que o trait (inclusive o
  filtro `tainacan-svg-icons-folder-path`), testa cada candidato com
  `is_readable()` e cai para `''` se nenhum existir; o painel pede `reports` e
  `settings`, com alternativas em ordem de preferência.
- Renderização dentro de `<div class="wrap tainacan-page-container-content">` + `<div class="tainacan-fixed-subheader"><h1 class="tainacan-page-title">…`, herdando sidebar, header e tema do Tainacan.
- `admin_enqueue_css()` / `admin_enqueue_js()` carregam Vue 3 (vendored) + admin.js só dentro das duas páginas.

Quando o Tainacan não está disponível (ou < 1.0.0) o plugin **detecta automaticamente** e cai num
modo standalone com um top-level menu próprio + admin notice de aviso. A interface continua funcionando
de forma idêntica; apenas a integração visual com a sidebar do Tainacan fica desabilitada.

## Arquitetura

```
includes/
├── class-autoloader.php             PSR-4-like autoload (TainacanIndexManager\*)
├── class-plugin.php                 Bootstrap + DI (singleton)
├── class-settings.php               Opções + sanitização
├── class-logger.php                 Tabela {prefix}tainacan_idxmgr_logs (dbDelta)
├── class-elasticsearch-client.php   Cliente HTTP (wp_remote_*) p/ ES/OS
├── class-opensearch-client.php      Subclasse (mesmo wire)
├── class-index-manager.php          Schema do índice (PT-BR analyzers, mappings)
├── class-indexer.php                Fila + bulk + retry + estados pausar/retomar/cancelar
├── class-indexer-metrics.php        Throughput / ETA / success rate / histórico de runs
├── class-health-service.php         Snapshot (cluster + índice + cobertura) com transient 60s
├── class-collections-monitor.php    Cobertura por coleção (transient 300s)
├── class-elasticpress-integration.php  Detecção e leitura do estado do EP
├── class-es-query-builder.php       WP_Query args → DSL do ES (tudo-ou-nada)
├── class-search-integration.php     posts_pre_query → ES, com fallback SQL
├── class-facets-integration.php     Facetas via agregação, com fallback SQL
├── class-alerts.php                 Painel (admin_notices) + e-mail throttled
├── class-cron.php                   Schedules + 3 ticks (health, index, cleanup)
├── class-rest-controller.php        Namespace tainacan-index-manager/v1
├── class-admin-page.php             Bootstrap das páginas + fallback standalone
├── class-tainacan-icon.php          Ícone SVG resiliente (is_readable() antes do file_get_contents())
└── tainacan-pages/
    ├── class-dashboard-page.php     \Tainacan\TIM_Dashboard_Page extends \Tainacan\Pages
    └── class-settings-page.php      \Tainacan\TIM_Settings_Page  extends \Tainacan\Pages

assets/
├── css/admin.css                    Estilos do painel (paleta Tainacan)
├── js/admin.js                      SPA Vue 3 (dashboard + settings)
└── vendor/vue/vue.global.prod.js    Vue 3.4.27 (bundled, sem CDN)

templates/                           (reservado para extensões via include)
languages/                           .pot/.po/.mo (textdomain: tainacan-index-manager)
uninstall.php                        Limpa opções, transients, tabela, hooks de cron
```

### Modelo de fila

A fila do indexador é uma lista de IDs em **uma única opção** (`tainacan_idxmgr_queue`). Cada batch:

1. Lê `batch_size` IDs do início.
2. Monta documentos via `Tainacan\Repositories\Items` + `Item_Metadata` quando disponível; faz fallback para `WP_Post`/postmeta/taxonomias.
3. Envia tudo num `POST /_bulk`.
4. Remove processados, recontabiliza falhas em `tainacan_idxmgr_failures`, recoloca na fila itens com falhas < `max_retries`.
5. Marca `last_index_run_ts`.

### Roteamento da listagem de itens

`Search_Integration` engancha em `posts_pre_query`, que curto-circuita a `WP_Query`
**antes** de qualquer SQL ser executado. O gargalo de uma coleção grande não é o
casamento textual, e sim os JOINs em `postmeta`/termos que sustentam a navegação e
os filtros de faceta — por isso o gancho não se limita mais a `is_search()`.

- Stand-down completo quando `engine` = `sql`, ou enquanto o plugin ElasticPress
  estiver ativo — este último por segurança, não por preferência: dois plugins
  reescrevendo a mesma `WP_Query` entregam o resultado de quem rodar primeiro.

> **Modos.** Até a 1.2.0 o campo oferecia `auto`, `own_indexer` e `elasticpress`,
> que descreviam *quem monta o índice* e não *quem responde a consulta*. Isso
> confundia: escolher `elasticpress` num site sem o plugin ElasticPress ativo
> significava, na prática, "ninguém cuida da busca" — e tudo caía em SQL com o
> índice parado ao lado. Restam dois modos, e valores antigos são migrados na
> leitura (`auto`/`own_indexer`/`elasticpress` → `elasticsearch`,
> `disabled` → `sql`), sem exigir reconfiguração.
- Atende navegação de coleção, filtros de faceta e busca textual.
- Filtra `post_status` (padrão `publish`). Sem isso, documentos defasados no índice
  — itens já excluídos, ainda marcados como `draft` — podiam aparecer publicamente.
- Publica `found_posts`/`max_num_pages` a partir do total do ES, então a paginação
  fica correta. A implementação anterior usava `post__in` com teto de 200 hits, o
  que quebrava a paginação a partir da primeira página. O total fica associado à
  instância exata da `WP_Query` (não só ao seu `spl_object_id()`, que o PHP
  reaproveita entre objetos) — sem isso, uma consulta sem relação nenhuma podia
  herdar o total de outra, o que na prática derrubava listas inteiras do admin
  de forma intermitente.
- Nunca responde a uma busca por post específico (`is_singular()`, `name`,
  `pagename`, `p`/`page_id`, `post_name__in`): o índice não conhece esses
  filtros, só `post__in`. Sem essa exclusão, todo permalink de item
  (`/{coleção}/{slug}/`) caía no roteamento, o índice devolvia a listagem da
  coleção inteira e o WordPress ficava com o primeiro resultado — todo
  permalink da coleção abria o mesmo item, e slugs inexistentes deixavam de
  dar 404.
- Reentrância protegida: montar a lista de post types de coleção roda um
  `WP_Query` próprio (`Collections::fetch()`), que dispara `posts_pre_query`
  de volta nesta mesma classe. Sem uma trava, isso é recursão infinita até
  estourar o limite de memória do PHP — `Search_Integration::without_routing()`
  suspende o roteamento enquanto essas consultas internas rodam, servindo-as
  sempre por SQL.
- Em qualquer falha do ES: devolve `null`, o SQL roda intacto, marca
  `tainacan_idxmgr_fallback_active` (transient 1h) e dispara alerta.

#### Tradução das consultas (`ES_Query_Builder`)

Converte `post_type`, `post_status`, `s`, `meta_query`, `tax_query`, `author`,
`post__in`/`post__not_in` e a ordenação para DSL do Elasticsearch.

A regra é **fidelidade acima de cobertura**: o conjunto devolvido tem de ser
exatamente o mesmo que o SQL devolveria. Operador de comparação desconhecido,
ordenação por dado que o índice não guarda (`rand`, `meta_value`), status fora do
índice, paginação além de `max_result_window`, `posts_per_page = -1` — tudo isso
faz o builder devolver `null` e o SQL assume. Um resultado rápido e errado é pior
que um lento e correto.

### Roteamento das facetas

`Facets_Integration` responde ao filtro `tainacan-fetch-all-metadatum-values` com
**uma única** agregação `nested` + `reverse_nested` (para contar itens, não linhas
de metadado).

Sem isso, o Tainacan monta cada faceta com um `SELECT DISTINCT meta_value` sobre
toda a `postmeta` e, **para cada valor encontrado**, dispara um `Items::fetch()`
completo só para ler `found_posts` — uma consulta com JOIN pesado por opção de
faceta, a cada carregamento de página.

Escopo: metadados cujo valor é gravado literalmente (Text, Textarea, Numeric, Date,
Selectbox e os core de título/descrição). Taxonomy, Relationship, User e Control
resolvem rótulos em outras tabelas e carregam semântica de hierarquia — esses
continuam com o Tainacan.

Guardas que devolvem o controle ao SQL: `hideempty=0` (o índice só conhece valores
presentes em itens), uso de `include`, janela maior que `facet_max_terms`, e
qualquer chave em `items_filter` que o builder não modele — esta última é essencial,
porque ignorar um filtro silenciosamente inflaria as contagens.

**Ordenação idêntica ao SQL, via ICU.** `wp_postmeta.meta_value` é ordenado pelo
MySQL sob `utf8mb4_unicode_520_ci` (case/acento-insensível); uma agregação
`terms` do ES ordena por valor de byte UTF-8 puro — os dois divergem de verdade
(confirmado com dados reais: o topo alfabético de 20 valores era um conjunto
diferente, não só uma ordem diferente). Como isso só importa onde a lista é
cortada, a agregação pede **todos** os valores distintos (até `facet_max_terms`)
e a ordenação final é feita em PHP com `Collator` (extensão `intl`), que
implementa o mesmo algoritmo em que aquela collation se baseia. Sem `intl`
disponível, `Facets_Integration` se recusa a rotear — nunca aproxima. Metadados
com mais valores distintos que `facet_max_terms` (ex.: um número de registro,
praticamente único por item) ficam memorizados por 1h como "grandes demais" e
pulam direto para o SQL, sem pagar a agregação cujo resultado já se sabe
inutilizável.

### Mappings PT-BR

Analyzer `tnc_pt_br` combina `standard` + `lowercase` + `asciifolding (preserve_original)` + stopwords `_brazilian_` + stemmer `brazilian`. Aplicado em `title`, `description`, `content`, `metadata.value_text`.

### Campos que sustentam filtros e facetas

O Tainacan endereça metadados por **ID**, não por slug, e filtra taxonomia por
`term_id`. O documento carrega esses identificadores:

| Campo | Para quê |
|---|---|
| `metadata.metadatum_id` | Chave usada no `meta_query` do Tainacan |
| `metadata.value_keyword` | **Todos** os valores (array), para filtro exato e agregação |
| `metadata.value_ids` | Entidades por trás de Taxonomy/Relationship |
| `taxonomies.term_ids` | Alvo do `tax_query` |

> `value_keyword` guardava apenas o primeiro valor (`$flat[0]`) até a 1.2.0, então
> metadados multivalorados perdiam silenciosamente todos os demais em filtros e
> facetas. Valores vazios deixaram de ser indexados — um item sem valor para um
> metadatum não deve constar como tendo valor `""`.

**Mudar esses campos exige reindexação completa.**

### REST endpoints

Todos sob `tainacan-index-manager/v1`, exigem `manage_options` + nonce REST:

```
GET    /health
GET    /collections
GET    /settings
PUT    /settings
POST   /test-connection
POST   /index/create | /index/delete | /index/recreate
POST   /index/reindex-all
POST   /index/reindex-collection      (args.collection_id)
POST   /index/enqueue-pending
POST   /index/purge-orphans                (args.batch; remove docs de itens inexistentes)
POST   /index/process-batch
GET    /index/state
POST   /index/pause | /index/resume | /index/cancel
GET    /metrics                         (args.window = N runs para média móvel)
POST   /metrics/reset
GET    /logs                            (args: page, per_page, level, channel)
POST   /logs/clear
GET    /logs/export
GET    /alerts
POST   /alerts/clear
GET    /elasticpress
POST   /elasticpress/sync               (WP-CLI required)
```

### Indicadores de monitoramento da indexação

A `Indexer_Metrics` registra cada batch e expõe:

| Indicador | Fonte / cálculo |
|---|---|
| **Throughput (itens/s)** | `sum(indexed em N runs recentes) / (max_ts - min_ts)` na janela, com fallback para `sum(duration_ms)` |
| **ETA** | `queue_size / throughput_ips`, formatado em PT-BR (`s` / `min` / `h` / `d`) |
| **Taxa de sucesso** | `indexed / (indexed + failed)` na janela; colore o card: ≥95% verde, ≥80% amarelo, <80% vermelho |
| **Lote médio (ms)** | `avg(duration_ms)` na janela |
| **Tamanho médio do lote** | `avg(built)` na janela |
| **Total lifetime** | Indexados / Falhas / Skipped / Dropped / Lotes desde a primeira run |
| **Pico de fila observado** | `max(queue_before)` ao longo do histórico |
| **Distribuição (stacked bar)** | Indexados / Falhas / Dropped / Skipped em % |
| **Sparklines (últimas 50 runs)** | Indexados, falhas, duração, tamanho da fila |
| **Top N falhas (item_id → count)** | Ordenado decrescente, com link `post.php?action=edit` |
| **Polling em tempo real** | Frontend faz `GET /metrics` a cada 7s |


### Hooks WordPress utilizados

| Hook | Uso |
|---|---|
| `plugins_loaded` | Bootstrap |
| `init` | Garantir ticks de cron |
| `cron_schedules` | Recurrences `tim_15min`, `tim_30min`, `tim_6hours`, `tim_minute` |
| `save_post` | Enfileira reindex incremental quando item Tainacan muda |
| `before_delete_post` | Apaga doc do índice (404 é sucesso, não erro — o doc já não estava lá) |
| `posts_pre_query` | Responde listagem/facetas/busca pelo índice, com fallback SQL |
| `tainacan-fetch-all-metadatum-values` | Responde facetas via agregação, com fallback SQL |
| `found_posts_query` / `found_posts` | Substituem o `SELECT FOUND_ROWS()` pelo total que o ES já devolveu |
| `rest_api_init` | Registra rotas |
| `admin_menu` | Submenus em Tainacan (ou top-level fallback) |
| `admin_enqueue_scripts` | Carrega Vue + admin.js + admin.css |
| `admin_notices` | Renderiza alertas |

### Integração com Tainacan

- Usa `Tainacan\Repositories\Items`, `Tainacan\Repositories\Collections`, `Tainacan\Repositories\Item_Metadata` quando disponíveis.
- Detecta o post type de cada coleção via `Collection::get_db_identifier()`.
- Registra submenus sob o slug do Tainacan (`tainacan_admin`, com fallback para `tainacan` / `tainacan-admin`).
- Não modifica o núcleo do Tainacan, não sobrescreve hooks oficiais, não duplica capabilities.

### Integração com ElasticPress

- Detecta via `EP_VERSION` / `\ElasticPress\Elasticsearch`.
- Lê estado via `Indexables::factory()->get_all()` e opções públicas (`ep_last_sync`, `ep_index_meta`).
- Aciona sync via `WP_CLI::runcommand('elasticpress sync')` quando WP-CLI estiver disponível.
- **Nunca** modifica configurações, índices ou mappings do EP.

### Segurança

- Acesso direto bloqueado (`defined('ABSPATH') || exit`).
- Capability `manage_options` em todas as ações.
- Cookie auth + `X-WP-Nonce` em todas as rotas REST.
- Sanitização por tipo no `Settings::sanitize_value` (whitelists para enums e regex para identificadores).
- Escape no ponto de saída (`esc_html`, `esc_attr`, `esc_url`).
- Logs com scrub automático de chaves contendo `password|secret|token|authorization|api_key`.
- Credenciais nunca trafegam pela REST (representadas como `__set__`).
- Throttle de e-mails (1 por código por hora) para evitar abuso.

### Performance

- Snapshot principal cacheado 60s (transient).
- Relatório por coleção cacheado 300s.
- Indexação 100% por `_bulk` em lote (default 50; ajustável até 1000).
- Sem consultas pesadas em tempo real no front: a busca executa só um `multi_match`.
- Indexação incremental: `save_post` / `before_delete_post` ajustam o índice item a item.
- Limpeza diária de logs antigos (retenção configurável).

## Testes manuais sugeridos

1. Sem ES configurado → painel acusa "Elasticsearch não configurado", fallback ativo.
2. ES configurado mas offline → painel acusa "Indisponível", alerta crítico, busca em SQL.
3. ES OK + índice inexistente → "Crie o índice" no painel; ação **Criar índice** funciona.
4. Reindexar tudo → cron drena fila em batches, painel mostra progresso.
5. Apagar 1 item Tainacan → contagem do índice cai em 1 na próxima verificação.
6. Editar 1 item → reindex incremental via `save_post`.
7. Filtrar busca no front → resultados vêm na ordem do `multi_match`; abrir o permalink de um item continua resolvendo o item certo (não a listagem da coleção).
8. Forçar erro ES (parar serviço durante uma busca) → fallback SQL automático, log + alerta.
9. EP ativo → roteamento deste plugin fica suspenso (segurança, não escolha do usuário) e a rota `/elasticpress` retorna snapshot de observação.

## Limitações conhecidas

- A integração com ElasticPress hoje é **observação + trigger**; o plugin não estende facets/aggregations do EP.
- Facetas de metadados **Taxonomy, Relationship, User e Control** continuam em SQL: resolvem rótulos em outras tabelas e, no caso de taxonomia, dependem de hierarquia (`parent`, `total_children`, `hierarchy_path`) que o índice não modela.
- Ordenação por metadado (`meta_value`, `meta_value_num`) e `orderby=rand` caem para SQL.
- Paginação além de `index.max_result_window` (10.000 por padrão) cai para SQL.
- Consultas sem paginação (`posts_per_page = -1` / `nopaging`), típicas de exportação, caem para SQL de propósito.
- A descoberta de post types de coleções é cacheada por request; se uma coleção for criada no meio de uma request, talvez não apareça imediatamente.
- Documentos órfãos (itens excluídos enquanto o ES estava fora do ar) só somem ao rodar `POST /index/purge-orphans`.
- Item movido para a lixeira (`wp_trash_post`) não é removido do índice: o hook `before_delete_post` só dispara em exclusão permanente. Enquanto estiver na lixeira, o item continua indexado sob o `post_status` que tinha antes.
- Metadados com mais valores distintos que `facet_max_terms` nunca são servidos pelo índice (ver seção de facetas) — a faceta correspondente sempre roda em SQL.
- O acionamento de `elasticpress sync` exige WP-CLI; sem WP-CLI, o admin precisa rodar sync pela própria UI do EP.

## Melhorias futuras recomendadas

- Estender o roteamento de facetas para metadados Taxonomy (exige indexar a hierarquia de termos).
- Suporte a ordenação por metadado no índice (campo dedicado por metadatum ordenável).
- Mapping configurável por coleção (analyzers/boosts por campo).
- Dashboard com gráficos de séries temporais (response time histórico, falhas por dia).
- Indexação distribuída via Action Scheduler para clusters maiores.
- Templates de e-mail HTML para alertas.
- Suporte a múltiplos índices (um por coleção) com aliases.
- Comandos WP-CLI (`wp tainacan-idx reindex`, `wp tainacan-idx status`).

## Licença

GPL-2.0-or-later.
