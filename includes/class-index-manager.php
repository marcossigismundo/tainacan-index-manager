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
		return array(
			'settings' => array(
				'number_of_shards'   => 1,
				'number_of_replicas' => 0,
				'analysis'           => array(
					'filter' => array(
						'brazilian_stop' => array(
							'type'      => 'stop',
							'stopwords' => '_brazilian_',
						),
						'brazilian_stemmer' => array(
							'type'     => 'stemmer',
							'language' => 'brazilian',
						),
						'asciifolding_preserve' => array(
							'type'              => 'asciifolding',
							'preserve_original' => true,
						),
					),
					'analyzer' => array(
						'tnc_pt_br' => array(
							'tokenizer' => 'standard',
							'filter'    => array(
								'lowercase',
								'asciifolding_preserve',
								'brazilian_stop',
								'brazilian_stemmer',
							),
						),
						'tnc_pt_br_search' => array(
							'tokenizer' => 'standard',
							'filter'    => array(
								'lowercase',
								'asciifolding_preserve',
								'brazilian_stop',
								'brazilian_stemmer',
							),
						),
					),
				),
			),
			'mappings' => array(
				'dynamic'    => 'true',
				'properties' => array(
					'item_id'        => array( 'type' => 'long' ),
					'collection_id'  => array( 'type' => 'long' ),
					'collection_name' => array( 'type' => 'keyword' ),
					'post_type'      => array( 'type' => 'keyword' ),
					'post_status'    => array( 'type' => 'keyword' ),
					'title'          => array(
						'type'            => 'text',
						'analyzer'        => 'tnc_pt_br',
						'search_analyzer' => 'tnc_pt_br_search',
						'fields'          => array(
							'raw' => array( 'type' => 'keyword' ),
						),
					),
					'description' => array(
						'type'            => 'text',
						'analyzer'        => 'tnc_pt_br',
						'search_analyzer' => 'tnc_pt_br_search',
					),
					'content' => array(
						'type'            => 'text',
						'analyzer'        => 'tnc_pt_br',
						'search_analyzer' => 'tnc_pt_br_search',
					),
					'author_id'   => array( 'type' => 'long' ),
					'author_name' => array( 'type' => 'keyword' ),
					'permalink'   => array( 'type' => 'keyword' ),
					'thumbnail'   => array( 'type' => 'keyword' ),
					'date_created' => array( 'type' => 'date' ),
					'date_modified' => array( 'type' => 'date' ),
					'taxonomies'  => array(
						'type'       => 'nested',
						'properties' => array(
							'slug'  => array( 'type' => 'keyword' ),
							'terms' => array(
								'type'   => 'text',
								'fields' => array(
									'raw' => array( 'type' => 'keyword' ),
								),
							),
						),
					),
					'metadata'    => array(
						'type'       => 'nested',
						'properties' => array(
							'slug'  => array( 'type' => 'keyword' ),
							'label' => array( 'type' => 'keyword' ),
							'value_text' => array(
								'type'            => 'text',
								'analyzer'        => 'tnc_pt_br',
								'search_analyzer' => 'tnc_pt_br_search',
							),
							'value_keyword' => array( 'type' => 'keyword' ),
							'value_number'  => array( 'type' => 'double' ),
							'value_date'    => array( 'type' => 'date', 'ignore_malformed' => true ),
						),
					),
					'identifier'  => array( 'type' => 'keyword' ),
				),
			),
		);
	}

	/**
	 * Test connectivity. Returns a structured array.
	 */
	public function test_connection(): array {
		$ping = $this->client->ping();
		return array(
			'ok'     => (bool) $ping['ok'],
			'ms'     => (int) $ping['ms'],
			'code'   => (int) $ping['code'],
			'error'  => (string) ( $ping['error'] ?? '' ),
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
		$this->logger->info( Logger::CHAN_INDEXER, 'Índice criado.', array( 'index' => $index ) );
		return true;
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
