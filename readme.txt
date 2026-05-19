=== Tainacan Index Manager ===
Contributors: marcossigismundo
Tags: tainacan, elasticsearch, opensearch, elasticpress, search, indexing
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.1
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
