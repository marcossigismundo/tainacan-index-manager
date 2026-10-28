<?php
/**
 * Index manager: create/delete the plugin's index with proper mappings/analyzers.
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * Responsible for the lifecycle of the plugin's own search index:
 * - create with PT-BR analyzers and mappings for Tainacan-shaped docs
 * - delete
 * - recreate
 *
 * Indexing operations live in Indexer; this class is the schema owner.
 */
final class Index_Manager {

	/**
	 * Version of the document shape produced by {@see Indexer} and described by
	 * {@see index_definition()}. Sibling plugins that query the index directly
	 * (field names such as `metadata.value_keyword`, `taxonomies.term_ids`,
	 * `post_status`) compare against this before trusting their own queries —
	 * a renamed field returns empty buckets, not an error, so without a version
	 * they would silently show nothing. Bump it whenever a field is renamed,
	 * removed or changes type.
	 */
	public const MAPPING_VERSION = 1;

	/**
	 * Fields this plugin added for its own queries, on top of MAPPING_VERSION.
	 * 2 = `title_sort` (1.5.0). Adding a field does not break the siblings, so
	 * MAPPING_VERSION stays; what changes is whether THIS plugin may sort on it,
	 * which only holds for an index created with the field in its mapping.
	 */
	public const INDEX_SCHEMA = 2;

	/**
	 * What the index was created with: `index` (name), `schema` and `icu`
	 * (INTL_ICU_VERSION of the PHP that created it). Absent = an index older
	 * than 1.5.0, or one created without `intl`.
	 */
	public const INDEX_SCHEMA_OPTION = 'tainacan_idxmgr_index_schema';

	private Settings $settings;
	private Logger $logger;
	private Elasticsearch_Client $client;

	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->client   = new Elasticsearch_Client( $settings, $logger );
	}

	public function client(): Elasticsearch_Client {
		return $this->client;
	}

	/**
	 * Build the index body (settings + mappings) tuned for PT-BR Tainacan items.
	 */
	public function index_definition(): array {
		// The search-side analyzer carries the vocabulary (synonyms, variants,
		// corrections, ignored words) last applied from "Vocabulário da busca", so a
		// recreated index keeps it. The index-side analyzer never changes with it:
		// that is what lets new vocabulary take effect without reindexing.
		$vocabulary = Search_Vocabulary::search_analysis( Search_Vocabulary::applied() );

		return array(
			'settings' => array(
				'number_of_shards'   => 1,
				'number_of_replicas' => 0,
				'analysis'           => array(
					'filter'   => array_merge( self::base_filters(), $vocabulary['filter'] ),
					'analyzer' => array_merge(
						array(
							'tnc_pt_br' => array(
								'tokenizer' => 'standard',
								'filter'    => self::base_chain(),
							),
						),
						$vocabulary['analyzer']
					),
				),
			),
			'mappings' => array(
				'dynamic'    => 'true',
				'properties' => array(
					'item_id'         => array( 'type' => 'long' ),
					'collection_id'   => array( 'type' => 'long' ),
					'collection_name' => array( 'type' => 'keyword' ),
					'post_type'       => array( 'type' => 'keyword' ),
					'post_status'     => array( 'type' => 'keyword' ),
					'title'           => array(
						'type'            => 'text',
						'analyzer'        => 'tnc_pt_br',
						'search_analyzer' => 'tnc_pt_br_search',
						'fields'          => array(
							'raw' => array( 'type' => 'keyword' ),
						),
					),
					// ICU sort key of the title, hex-encoded (see Indexer::title_sort_key()):
					// byte order of the key = collation order, which `title.raw` is not.
					'title_sort'      => array(
						'type'  => 'keyword',
						'index' => false,
					),
					'description'     => array(
						'type'            => 'text',
						'analyzer'        => 'tnc_pt_br',
						'search_analyzer' => 'tnc_pt_br_search',
					),
					'content'         => array(
						'type'            => 'text',
						'analyzer'        => 'tnc_pt_br',
						'search_analyzer' => 'tnc_pt_br_search',
					),
					'author_id'       => array( 'type' => 'long' ),
					'author_name'     => array( 'type' => 'keyword' ),
					'permalink'       => array( 'type' => 'keyword' ),
					'thumbnail'       => array( 'type' => 'keyword' ),
					'date_created'    => array( 'type' => 'date' ),
					'date_modified'   => array( 'type' => 'date' ),
					'taxonomies'      => array(
						'type'       => 'nested',
						'properties' => array(
							'slug'     => array( 'type' => 'keyword' ),
							// Term IDs are what Tainacan's tax_query filters on;
							// without them facet/filter translation is impossible.
							'term_ids' => array( 'type' => 'long' ),
							'terms'    => array(
								'type'   => 'text',
								'fields' => array(
									'raw' => array( 'type' => 'keyword' ),
								),
							),
						),
					),
					'metadata'        => array(
						'type'       => 'nested',
						'properties' => array(
							'slug'          => array( 'type' => 'keyword' ),
							// Tainacan's meta_query keys are metadatum IDs, not slugs.
							'metadatum_id'  => array( 'type' => 'long' ),
							// Term/item IDs behind Taxonomy and Relationship metadata,
							// used for facet aggregations and filter translation.
							'value_ids'     => array( 'type' => 'long' ),
							'label'         => array( 'type' => 'keyword' ),
							'value_text'    => array(
								'type'            => 'text',
								'analyzer'        => 'tnc_pt_br',
								'search_analyzer' => 'tnc_pt_br_search',
							),
							'value_keyword' => array( 'type' => 'keyword' ),
							'value_number'  => array( 'type' => 'double' ),
							'value_date'    => array(
								'type'             => 'date',
								'ignore_malformed' => true,
							),
						),
					),
					'identifier'      => array( 'type' => 'keyword' ),
				),
			),
		);
	}

	/**
	 * Stemmer/stopword language the index is built with.
	 *
	 * Read from the stored option (not from a Settings instance) so the static
	 * analyzer helpers used by the vocabulary installer see the same value.
	 */
	public static function analysis_language(): string {
		$all  = Settings::all();
		$lang = isset( $all['analysis_language'] ) ? (string) $all['analysis_language'] : 'brazilian';
		return in_array( $lang, Settings::analysis_languages(), true ) ? $lang : 'brazilian';
	}

	/**
	 * Stemmer language an existing index was created with ('' when unknown).
	 *
	 * The index-time analyzer cannot change without reindexing; anything that
	 * rewrites the index filters (applying the vocabulary) must keep THIS
	 * language, not the one currently configured, or old documents end up
	 * stemmed differently from new ones and from queries.
	 */
	public function index_language( string $index ): string {
		$settings = $this->client->get_index_settings( $index );
		if ( is_wp_error( $settings ) || ! is_array( $settings ) ) {
			return '';
		}
		foreach ( $settings as $idx ) {
			$lang = $idx['settings']['index']['analysis']['filter']['brazilian_stemmer']['language'] ?? '';
			if ( is_string( $lang ) && in_array( $lang, Settings::analysis_languages(), true ) ) {
				return $lang;
			}
		}
		return '';
	}

	/**
	 * Token filters every index of this plugin defines.
	 *
	 * Filter names stay `brazilian_*` whatever the language, because the applied
	 * vocabulary and the search analyzer reference them by name; only the
	 * stemmer language and the stopword list follow the setting.
	 */
	public static function base_filters( string $lang = '' ): array {
		if ( '' === $lang || ! in_array( $lang, Settings::analysis_languages(), true ) ) {
			$lang = self::analysis_language();
		}
		return array(
			'brazilian_stop'        => array(
				'type'      => 'stop',
				'stopwords' => '_' . $lang . '_',
			),
			'brazilian_stemmer'     => array(
				'type'     => 'stemmer',
				'language' => $lang,
			),
			'asciifolding_preserve' => array(
				'type'              => 'asciifolding',
				'preserve_original' => true,
			),
		);
	}

	/**
	 * Filter chain of the index-time analyzer (`tnc_pt_br`), and of the search
	 * analyzer while no vocabulary is applied.
	 *
	 * @return string[]
	 */
	public static function base_chain(): array {
		return array( 'lowercase', 'asciifolding_preserve', 'brazilian_stop', 'brazilian_stemmer' );
	}

	/**
	 * Test connectivity. Returns a structured array including the URL the
	 * client actually hits (with userinfo stripped) and the auth method
	 * resolved from settings — so a confused manager can immediately see
	 * what was tried.
	 */
	public function test_connection(): array {
		$ping = $this->client->ping();
		$auth = $this->client->describe_auth();
		return array(
			'ok'        => (bool) $ping['ok'],
			'ms'        => (int) $ping['ms'],
			'code'      => (int) $ping['code'],
			'error'     => (string) ( $ping['error'] ?? '' ),
			'url'       => $this->client->effective_base_url(),
			'auth'      => $auth['method'],
			'auth_user' => $auth['user'],
		);
	}

	/**
	 * Create the index if it does not exist. Returns true|WP_Error.
	 *
	 * @return true|\WP_Error
	 */
	public function create_index() {
		$index  = (string) $this->settings->get( 'index_name' );
		$exists = $this->client->index_exists( $index );
		if ( is_wp_error( $exists ) ) {
			return $exists;
		}
		if ( $exists ) {
			$this->logger->info( Logger::CHAN_INDEXER, 'Índice já existe; create ignorado.', array( 'index' => $index ) );
			return true;
		}

		$res = $this->client->create_index( $index, $this->index_definition() );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		// Without intl the documents get no `title_sort`, so the index must not
		// claim the field. Autoloaded: the query builder reads it on every
		// routed listing.
		if ( Facets_Integration::collator() instanceof \Collator ) {
			update_option(
				self::INDEX_SCHEMA_OPTION,
				array(
					'index'  => $index,
					'schema' => self::INDEX_SCHEMA,
					'icu'    => defined( 'INTL_ICU_VERSION' ) ? (string) INTL_ICU_VERSION : '',
				),
				true
			);
		} else {
			delete_option( self::INDEX_SCHEMA_OPTION );
		}
		$this->logger->info( Logger::CHAN_INDEXER, 'Índice criado.', array( 'index' => $index ) );
		return true;
	}

	/**
	 * Schema of the index currently configured: what this plugin may rely on
	 * when querying it. 1 when the record belongs to another index name (the
	 * setting was pointed elsewhere) or is missing.
	 */
	public static function index_schema(): int {
		$record = get_option( self::INDEX_SCHEMA_OPTION );
		if ( ! is_array( $record ) ) {
			return 1;
		}
		$all = Settings::all();
		if ( (string) ( $record['index'] ?? '' ) !== (string) ( $all['index_name'] ?? '' ) ) {
			return 1;
		}
		return (int) ( $record['schema'] ?? 1 );
	}

	/**
	 * Whether a title ordering can be answered by the index: the index has
	 * `title_sort`, and this PHP computes sort keys with the same ICU that
	 * built them. Keys from different ICU versions are not comparable, so after
	 * a PHP/ICU upgrade title ordering goes back to SQL until the index is
	 * recreated and reindexed.
	 */
	public static function title_sort_ready(): bool {
		if ( self::index_schema() < 2 || ! Facets_Integration::collator() instanceof \Collator ) {
			return false;
		}
		$record = get_option( self::INDEX_SCHEMA_OPTION );
		$icu    = defined( 'INTL_ICU_VERSION' ) ? (string) INTL_ICU_VERSION : '';
		return is_array( $record ) && (string) ( $record['icu'] ?? '' ) === $icu;
	}

	/**
	 * Delete the index. Returns true|WP_Error.
	 *
	 * @return true|\WP_Error
	 */
	public function delete_index() {
		$index  = (string) $this->settings->get( 'index_name' );
		$exists = $this->client->index_exists( $index );
		if ( is_wp_error( $exists ) ) {
			return $exists;
		}
		if ( ! $exists ) {
			return true;
		}

		$res = $this->client->delete_index( $index );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		delete_option( self::INDEX_SCHEMA_OPTION );
		$this->logger->info( Logger::CHAN_INDEXER, 'Índice apagado.', array( 'index' => $index ) );
		return true;
	}

	/**
	 * Drop + recreate the index. Returns true|WP_Error.
	 *
	 * @return true|\WP_Error
	 */
	public function recreate_index() {
		$del = $this->delete_index();
		if ( is_wp_error( $del ) ) {
			return $del;
		}
		return $this->create_index();
	}
}
