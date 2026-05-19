# Tainacan Index Manager

Plugin WordPress integrado ao [Tainacan](https://tainacan.org/) que oferece:

- Painel **Tainacan > Saúde da Busca** com indicadores de cluster, índice, cobertura e divergência.
- Monitoramento periódico (WP-Cron) de **Elasticsearch** e **OpenSearch**.
- Integração somente-leitura com **ElasticPress** quando ele está ativo.
- **Indexador próprio** com mappings/analyzers otimizados para português brasileiro, processamento em lote e controle pausar/retomar/cancelar.
- Reescrita opcional da busca para usar o índice, com **fallback automático para SQL** quando o ES falha.
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
| `engine` | `auto` | `auto`, `elasticpress`, `own_indexer` ou `disabled` |
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
| `log_retention_days` | 30 | Cleanup diário |

Credenciais nunca são expostas pela REST (`__set__` indica "valor armazenado"). Logs aplicam scrub de chaves que contenham `password`, `secret`, `token`, `authorization`, `api_key`.

## Integração com o admin do Tainacan

A partir da versão 1.1.0 o plugin estende `\Tainacan\Pages` (introduzida no Tainacan 1.0.0) seguindo
o procedimento oficial documentado em
[creating-tainacan-admin-pages](https://tainacan.github.io/tainacan-wiki/#/dev/creating-tainacan-admin-pages):

- **Saúde da Busca** entra como item do menu raiz do Tainacan (posição 60) via `$this->tainacan_root_menu_slug`.
- **Configurações de Indexação** entra no submenu "Outros" (`$this->tainacan_other_links_slug`).
- Ícones SVG nativos do Tainacan via `$this->get_svg_icon('chart' | 'settings')`.
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
├── class-search-integration.php     pre_get_posts → ES, com fallback SQL
├── class-alerts.php                 Painel (admin_notices) + e-mail throttled
├── class-cron.php                   Schedules + 3 ticks (health, index, cleanup)
├── class-rest-controller.php        Namespace tainacan-index-manager/v1
├── class-admin-page.php             Bootstrap das páginas + fallback standalone
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

### Roteamento da busca

`Search_Integration` engancha em `pre_get_posts` (front + AJAX):

- Stand-down completo quando `engine` ∈ {`elasticpress`} ou (`auto` e EP ativo).
- Quando ativo, executa `multi_match` (title^3, description^2, content, metadata.value_text, taxonomies.terms), filtra por `post_type`, reescreve `post__in` na ordem dos hits.
- Em qualquer falha do ES: limpa `s`, deixa o SQL natural rodar, marca `tainacan_idxmgr_fallback_active` (transient 1h) e dispara alerta.

### Mappings PT-BR

Analyzer `tnc_pt_br` combina `standard` + `lowercase` + `asciifolding (preserve_original)` + stopwords `_brazilian_` + stemmer `brazilian`. Aplicado em `title`, `description`, `content`, `metadata.value_text`.

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
| `before_delete_post` | Apaga doc do índice |
| `pre_get_posts` | Reescreve busca front-end para usar ES |
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
7. Filtrar busca no front → resultados vêm na ordem do `multi_match`.
8. Forçar erro ES (parar serviço durante uma busca) → fallback SQL automático, log + alerta.
9. EP ativo → plugin entra em modo read-only do EP; rota `/elasticpress` retorna snapshot.

## Limitações conhecidas

- A integração com ElasticPress hoje é **observação + trigger**; o plugin não estende facets/aggregations do EP.
- O roteamento de busca não cobre todos os parâmetros avançados do Tainacan (facets, ranges); para essas cargas, prefira o ElasticPress.
- A descoberta de post types de coleções é cacheada por request; se uma coleção for criada no meio de uma request, talvez não apareça imediatamente.
- O acionamento de `elasticpress sync` exige WP-CLI; sem WP-CLI, o admin precisa rodar sync pela própria UI do EP.

## Melhorias futuras recomendadas

- Suporte a aggregations/facets nativas para listagens Tainacan.
- Mapping configurável por coleção (analyzers/boosts por campo).
- Dashboard com gráficos de séries temporais (response time histórico, falhas por dia).
- Indexação distribuída via Action Scheduler para clusters maiores.
- Templates de e-mail HTML para alertas.
- Suporte a múltiplos índices (um por coleção) com aliases.
- Comandos WP-CLI (`wp tainacan-idx reindex`, `wp tainacan-idx status`).

## Licença

GPL-2.0-or-later.
