<?php
/**
 * OpenSearch client. Shares wire protocol with Elasticsearch for everything
 * this plugin actually invokes (cluster/_health, _stats, _doc, _bulk, _search,
 * _count, _refresh). Exists as a distinct class so future divergences can be
 * isolated here without touching the rest of the codebase.
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

class OpenSearch_Client extends Elasticsearch_Client {}
