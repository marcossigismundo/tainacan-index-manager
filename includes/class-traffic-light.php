<?php
/**
 * Status light: one answer to "is the search running on Elasticsearch?".
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * Reduces the health snapshot, the indexer and the routing fallback flag to a
 * traffic light, plus the checks that explain it.
 *
 * - green  — the index is answering Tainacan's listings, filters and search;
 * - yellow — it is answering, but something needs attention (coverage, queue,
 *            latency, a recent fallback to SQL);
 * - red    — searches are being answered by SQL: no connection, no index, or
 *            primary shards missing;
 * - off    — Elasticsearch disabled in the settings on purpose.
 *
 * The last result is remembered in a small option so the admin menu can show a
 * coloured dot without calling Elasticsearch while the menu is being built.
 */
final class Traffic_Light {

	public const OPTION = 'tainacan_idxmgr_light';

	/** A fallback older than this no longer paints the light yellow. */
	private const FALLBACK_WINDOW = 900;

	private Settings $settings;
	private Health_Service $health;
	private Indexer $indexer;
	private Search_Vocabulary $vocabulary;
	private Search_Integration $search;

	public function __construct( Settings $settings, Health_Service $health, Indexer $indexer, Search_Vocabulary $vocabulary, Search_Integration $search ) {
		$this->settings   = $settings;
		$this->health     = $health;
		$this->indexer    = $indexer;
		$this->vocabulary = $vocabulary;
		$this->search     = $search;
	}

	/**
	 * Evaluate the light.
	 *
	 * @param bool $refresh Rebuild the health snapshot instead of using the 60 s cache.
	 */
	public function evaluate( bool $refresh = false ): array {
		$snap   = $refresh ? $this->health->refresh_snapshot() : $this->health->get_snapshot();
		$checks = array();

		$engine_on = Settings::ENGINE_SQL !== $this->settings->engine();
		$checks[]  = $engine_on
			? self::check( 'engine', 'ok', __( 'Roteamento', 'tainacan-index-manager' ), __( 'Listagens, filtros e busca do Tainacan são enviados ao índice.', 'tainacan-index-manager' ) )
			: self::check( 'engine', 'off', __( 'Roteamento', 'tainacan-index-manager' ), __( 'Desligado em Configurações: tudo é respondido pelo banco (SQL).', 'tainacan-index-manager' ) );

		/* Connection */
		if ( empty( $snap['es_configured'] ) ) {
			$checks[] = self::check( 'connection', 'fail', __( 'Conexão', 'tainacan-index-manager' ), __( 'Endereço do Elasticsearch não configurado.', 'tainacan-index-manager' ) );
		} elseif ( empty( $snap['es_reachable'] ) ) {
			$checks[] = self::check( 'connection', 'fail', __( 'Conexão', 'tainacan-index-manager' ), __( 'O Elasticsearch não respondeu.', 'tainacan-index-manager' ) );
		} else {
			$ms       = (int) $snap['es_ping_ms'];
			$checks[] = self::check(
				'connection',
				$ms > 2000 ? 'warn' : 'ok',
				__( 'Conexão', 'tainacan-index-manager' ),
				/* translators: %s = milissegundos */
				sprintf( __( 'Responde em %s ms.', 'tainacan-index-manager' ), number_format_i18n( $ms ) )
			);
		}

		/* Index */
		if ( ! empty( $snap['es_reachable'] ) ) {
			$status = $this->shard_status( $snap );
			if ( empty( $snap['index_exists'] ) ) {
				/* translators: %s = nome do índice */
				$checks[] = self::check( 'index', 'fail', __( 'Índice', 'tainacan-index-manager' ), sprintf( __( 'O índice "%s" não existe.', 'tainacan-index-manager' ), (string) $snap['index_name'] ) );
			} elseif ( 'red' === $status ) {
				$checks[] = self::check( 'index', 'fail', __( 'Índice', 'tainacan-index-manager' ), __( 'Estado RED: há shards primários sem alocação.', 'tainacan-index-manager' ) );
			} elseif ( 'yellow' === $status ) {
				$checks[] = self::check( 'index', 'warn', __( 'Índice', 'tainacan-index-manager' ), __( 'Estado YELLOW: réplicas não alocadas.', 'tainacan-index-manager' ) );
			} else {
				$checks[] = self::check( 'index', 'ok', __( 'Índice', 'tainacan-index-manager' ), sprintf(
					/* translators: %1$s = índice, %2$s = documentos */
					__( '"%1$s" íntegro, %2$s documentos.', 'tainacan-index-manager' ),
					(string) $snap['index_name'],
					number_format_i18n( (int) $snap['index_doc_count'] )
				) );
			}
		}

		/* Coverage */
		if ( null !== ( $snap['coverage_pct'] ?? null ) ) {
			$over     = (float) $snap['divergence_pct'] > (float) $snap['divergence_threshold_pct'];
			$checks[] = self::check( 'coverage', $over ? 'warn' : 'ok', __( 'Cobertura', 'tainacan-index-manager' ), sprintf(
				/* translators: %1$s = %, %2$s = indexados, %3$s = itens */
				__( '%1$s%% — %2$s documentos para %3$s itens no Tainacan.', 'tainacan-index-manager' ),
				number_format_i18n( (float) $snap['coverage_pct'], 1 ),
				number_format_i18n( (int) $snap['index_doc_count'] ),
				number_format_i18n( (int) $snap['tainacan_item_count'] )
			) );
		}

		/* Queue */
		$queue = $this->indexer->queue_size();
		$state = $this->indexer->get_state();
		if ( Indexer::STATE_PAUSED === $state ) {
			/* translators: %s = itens */
			$checks[] = self::check( 'queue', 'warn', __( 'Fila de indexação', 'tainacan-index-manager' ), sprintf( __( 'Pausada, com %s itens esperando.', 'tainacan-index-manager' ), number_format_i18n( $queue ) ) );
		} elseif ( $queue > 0 && ! $this->settings->get( 'auto_indexing_enabled' ) ) {
			/* translators: %s = itens */
			$checks[] = self::check( 'queue', 'warn', __( 'Fila de indexação', 'tainacan-index-manager' ), sprintf( __( '%s itens esperando, e o processamento automático está desligado.', 'tainacan-index-manager' ), number_format_i18n( $queue ) ) );
		} elseif ( $queue > 0 ) {
			/* translators: %s = itens */
			$checks[] = self::check( 'queue', 'ok', __( 'Fila de indexação', 'tainacan-index-manager' ), sprintf( __( '%s itens sendo processados.', 'tainacan-index-manager' ), number_format_i18n( $queue ) ) );
		} else {
			$checks[] = self::check( 'queue', 'ok', __( 'Fila de indexação', 'tainacan-index-manager' ), __( 'Vazia: alterações nos itens já chegaram ao índice.', 'tainacan-index-manager' ) );
		}

		/* Recent fallback */
		$fallback = $this->search->last_fallback();
		if ( $engine_on && is_array( $fallback ) && ( time() - (int) ( $fallback['since'] ?? 0 ) ) < self::FALLBACK_WINDOW && 'es_not_configured' !== ( $fallback['reason'] ?? '' ) ) {
			$checks[] = self::check( 'fallback', 'warn', __( 'Respostas pelo SQL', 'tainacan-index-manager' ), sprintf(
				/* translators: %1$s = hora, %2$s = motivo */
				__( 'Às %1$s uma consulta falhou no índice e foi respondida pelo banco (%2$s).', 'tainacan-index-manager' ),
				wp_date( 'H:i', (int) $fallback['since'] ),
				self::fallback_reason( (string) ( $fallback['reason'] ?? '' ) )
			) );
		}

		/* Vocabulary — informative, never changes the colour */
		$vocab = $this->vocabulary->status();
		if ( $vocab['pending'] ) {
			$checks[] = self::check( 'vocabulary', 'info', __( 'Vocabulário', 'tainacan-index-manager' ), __( 'Há alterações salvas que ainda não foram aplicadas.', 'tainacan-index-manager' ) );
		} elseif ( $vocab['rules_count'] + $vocab['stopwords_count'] > 0 ) {
			$checks[] = self::check( 'vocabulary', 'ok', __( 'Vocabulário', 'tainacan-index-manager' ), sprintf(
				/* translators: %1$s regras, %2$s palavras */
				__( '%1$s regras e %2$s palavras ignoradas em uso.', 'tainacan-index-manager' ),
				number_format_i18n( $vocab['rules_count'] ),
				number_format_i18n( $vocab['stopwords_count'] )
			) );
		} else {
			$checks[] = self::check( 'vocabulary', 'off', __( 'Vocabulário', 'tainacan-index-manager' ), __( 'Nenhum sinônimo cadastrado.', 'tainacan-index-manager' ) );
		}

		$light = $this->classify( $engine_on, $checks );
		$light['checks']     = $checks;
		$light['checked_at'] = (int) ( $snap['generated_at'] ?? time() );

		$this->remember( $light );
		return $light;
	}

	/**
	 * @param array[] $checks
	 */
	private function classify( bool $engine_on, array $checks ): array {
		if ( ! $engine_on ) {
			return array(
				'color'   => 'off',
				'title'   => __( 'Elasticsearch desligado', 'tainacan-index-manager' ),
				'message' => __( 'O plugin está configurado para não usar o índice. Todas as consultas vão direto ao banco de dados.', 'tainacan-index-manager' ),
			);
		}

		$fail = self::first( $checks, 'fail' );
		if ( $fail ) {
			return array(
				'color'   => 'red',
				'title'   => __( 'Busca fora do Elasticsearch', 'tainacan-index-manager' ),
				'message' => sprintf(
					/* translators: %s = motivo */
					__( 'As consultas estão sendo respondidas pelo banco de dados, mais lento. Motivo: %s', 'tainacan-index-manager' ),
					$fail['detail']
				),
			);
		}

		$warn = self::first( $checks, 'warn' );
		if ( $warn ) {
			return array(
				'color'   => 'yellow',
				'title'   => __( 'Funcionando, com atenção', 'tainacan-index-manager' ),
				'message' => sprintf(
					/* translators: %1$s = verificação, %2$s = detalhe */
					__( 'O índice está respondendo, mas vale olhar: %1$s — %2$s', 'tainacan-index-manager' ),
					$warn['label'],
					$warn['detail']
				),
			);
		}

		return array(
			'color'   => 'green',
			'title'   => __( 'Elasticsearch funcionando', 'tainacan-index-manager' ),
			'message' => __( 'Listagens, filtros e buscas do Tainacan estão sendo respondidos pelo índice.', 'tainacan-index-manager' ),
		);
	}

	private static function first( array $checks, string $state ): ?array {
		foreach ( $checks as $c ) {
			if ( $state === $c['state'] ) {
				return $c;
			}
		}
		return null;
	}

	private static function check( string $key, string $state, string $label, string $detail ): array {
		return array(
			'key'    => $key,
			'state'  => $state,
			'label'  => $label,
			'detail' => $detail,
		);
	}

	/**
	 * Same rule as Health_Service::shard_status(): our index first, and a yellow
	 * on a single-node cluster is a replica with nowhere to go, not a problem.
	 */
	private function shard_status( array $snap ): string {
		$status = (string) ( $snap['index_status'] ?? $snap['cluster_status'] ?? '' );
		if ( 'yellow' === $status && ! empty( $snap['single_node_cluster'] ) ) {
			return 'green';
		}
		return $status;
	}

	private static function fallback_reason( string $reason ): string {
		switch ( $reason ) {
			case 'es_query_error':
				return __( 'erro do Elasticsearch', 'tainacan-index-manager' );
			case 'es_malformed_response':
				return __( 'resposta inesperada', 'tainacan-index-manager' );
			case 'exception':
				return __( 'erro interno do plugin', 'tainacan-index-manager' );
		}
		return $reason;
	}

	/**
	 * Keep the colour for the admin menu; write only when something changed or
	 * the stored value is getting old, since the panel polls this.
	 */
	private function remember( array $light ): void {
		$old = self::remembered();
		if ( $old && $old['color'] === $light['color'] && $old['title'] === $light['title'] && ( time() - $old['at'] ) < 300 ) {
			return;
		}
		update_option( self::OPTION, array(
			'color' => $light['color'],
			'title' => $light['title'],
			'at'    => time(),
		), false );
	}

	/**
	 * Last evaluated light, or null if it never ran.
	 *
	 * @return array{color:string, title:string, at:int}|null
	 */
	public static function remembered(): ?array {
		$v = get_option( self::OPTION, null );
		if ( ! is_array( $v ) || empty( $v['color'] ) ) {
			return null;
		}
		return array(
			'color' => (string) $v['color'],
			'title' => (string) ( $v['title'] ?? '' ),
			'at'    => (int) ( $v['at'] ?? 0 ),
		);
	}
}
