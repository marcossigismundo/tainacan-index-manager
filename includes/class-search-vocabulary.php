<?php
/**
 * Search vocabulary: synonyms, spelling variants, corrections and ignored words
 * applied to the search-side analyzer of the index.
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the collection team teach the search which words mean the same thing.
 *
 * Four lists, kept as plain text so they can be edited in the panel or loaded
 * from .txt/.csv files:
 *
 * - `synonyms`    equivalences ("quadro, pintura, tela");
 * - `variants`    old spellings, foreign forms, abbreviations ("pharmacia, farmácia");
 * - `corrections` one-way: what people type → what they mean ("excessão => exceção");
 * - `stopwords`   words too common to help ("acervo").
 *
 * **Everything here acts at search time only.** The lists go into the search
 * analyzer (`tnc_pt_br_search`); the index analyzer (`tnc_pt_br`) is untouched,
 * so a new vocabulary never requires reindexing. Changing an analyzer does
 * require the index to be closed, which is why {@see apply()} is a sequence of
 * steps with a rehearsal on a throwaway index first: nothing reaches the real
 * index before Elasticsearch has accepted the exact same definition elsewhere.
 *
 * Filter order, measured on a lab index on the IBRAM cluster (ES 8.6):
 *
 *     lowercase → asciifolding → brazilian_stop → [ignored words] → brazilian_stemmer → [synonyms]
 *
 * - synonyms **after the stemmer**, so "fotografias" triggers the rule written
 *   as "fotografia" (before the stemmer, plurals never matched);
 * - plain `asciifolding` (not the preserve_original variant the index uses):
 *   rules are parsed through the preceding filters, and stacked tokens there
 *   make Elasticsearch reject rules. Recall is unchanged, because the index
 *   always holds the folded form too;
 * - ignored words **before the stemmer**, otherwise "museu" would have to be
 *   written as its stem "mus" to be removed;
 * - multi-word terms are normalised through the same filters before being
 *   written, because a stopword inside a rule ("rio *de* janeiro") makes
 *   Elasticsearch drop the whole rule. See {@see normalize_terms()}.
 */
final class Search_Vocabulary {

	public const OPTION = 'tainacan_idxmgr_vocabulary';

	/** List keys, in display order. */
	public const LISTS = array( 'synonyms', 'variants', 'corrections', 'stopwords' );

	public const FILTER_SYNONYMS = 'tnc_vocab_synonyms';
	public const FILTER_STOP     = 'tnc_vocab_stop';

	private const PROBE_ANALYZER = 'tnc_vocab_probe';
	private const LOCK           = 'tainacan_idxmgr_vocab_lock';

	/** Stays a single token through every filter, and never occurs in real text. */
	private const SEPARATOR = 'zzvocabsepzz';

	private const MAX_LIST_BYTES = 2097152;
	private const MAX_RULES      = 20000;
	private const MAX_STOPWORDS  = 2000;
	private const MAX_TERM_CHARS = 80;
	private const PROBE_CHUNK    = 800;

	private Settings $settings;
	private Logger $logger;
	private Elasticsearch_Client $client;

	public function __construct( Settings $settings, Logger $logger, Elasticsearch_Client $client ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->client   = $client;
	}

	/* ------------------------------------------------------------------ */
	/* Stored state                                                        */
	/* ------------------------------------------------------------------ */

	private static function defaults(): array {
		return array(
			'lists'      => array_fill_keys( self::LISTS, '' ),
			'updated_at' => 0,
			'applied'    => array(),
		);
	}

	/**
	 * Whole stored state: draft lists plus the last applied compilation.
	 */
	public static function state(): array {
		$stored = get_option( self::OPTION, array() );
		$state  = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		$state['lists'] = array_merge( array_fill_keys( self::LISTS, '' ), is_array( $state['lists'] ) ? $state['lists'] : array() );
		return $state;
	}

	/**
	 * The vocabulary currently in the index (or to be used when it is created).
	 *
	 * @return array{rules?:string[], stopwords?:string[]}
	 */
	public static function applied(): array {
		$state = self::state();
		return is_array( $state['applied'] ) ? $state['applied'] : array();
	}

	private static function lists_hash( array $lists ): string {
		$norm = array();
		foreach ( self::LISTS as $key ) {
			$norm[ $key ] = trim( str_replace( "\r\n", "\n", (string) ( $lists[ $key ] ?? '' ) ) );
		}
		return md5( (string) wp_json_encode( $norm ) );
	}

	/**
	 * Compact status for the status light and the diagnostics.
	 */
	public function status(): array {
		$state   = self::state();
		$applied = is_array( $state['applied'] ) ? $state['applied'] : array();
		$hash    = self::lists_hash( $state['lists'] );
		$empty   = '' === trim( implode( '', $state['lists'] ) );

		return array(
			'pending'         => empty( $applied ) ? ! $empty : ( $hash !== ( $applied['source_hash'] ?? '' ) ),
			'applied_at'      => (int) ( $applied['at'] ?? 0 ),
			'applied_index'   => (string) ( $applied['index'] ?? '' ),
			'rules_count'     => count( (array) ( $applied['rules'] ?? array() ) ),
			'stopwords_count' => count( (array) ( $applied['stopwords'] ?? array() ) ),
		);
	}

	/**
	 * Payload of GET /vocabulary.
	 */
	public function to_public_array(): array {
		$state   = self::state();
		$applied = is_array( $state['applied'] ) ? $state['applied'] : array();

		return array(
			'lists'      => $state['lists'],
			'updated_at' => (int) $state['updated_at'],
			'status'     => $this->status(),
			'report'     => $this->parse( $state['lists'] )['report'],
			'applied'    => array(
				'at'        => (int) ( $applied['at'] ?? 0 ),
				'index'     => (string) ( $applied['index'] ?? '' ),
				'counts'    => (array) ( $applied['counts'] ?? array() ),
				'notes'     => (array) ( $applied['notes'] ?? array() ),
				'samples'   => (array) ( $applied['samples'] ?? array() ),
				'rules'     => count( (array) ( $applied['rules'] ?? array() ) ),
				'stopwords' => count( (array) ( $applied['stopwords'] ?? array() ) ),
			),
			'index_name' => (string) $this->settings->get( 'index_name' ),
			'limits'     => array(
				'max_rules'      => self::MAX_RULES,
				'max_stopwords'  => self::MAX_STOPWORDS,
				'max_term_chars' => self::MAX_TERM_CHARS,
				'max_list_bytes' => self::MAX_LIST_BYTES,
			),
		);
	}

	/**
	 * Save the draft lists (nothing is sent to Elasticsearch).
	 *
	 * @param array $lists Raw text per list key.
	 * @return array Parse report of what was saved.
	 */
	public function save( array $lists ): array {
		$state = self::state();
		foreach ( self::LISTS as $key ) {
			if ( ! array_key_exists( $key, $lists ) ) {
				continue;
			}
			$text = is_string( $lists[ $key ] ) ? $lists[ $key ] : '';
			$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
			$text = preg_replace( '/^\xEF\xBB\xBF/', '', $text );
			if ( strlen( $text ) > self::MAX_LIST_BYTES ) {
				$text = substr( $text, 0, self::MAX_LIST_BYTES );
			}
			$state['lists'][ $key ] = wp_check_invalid_utf8( $text, true );
		}
		$state['updated_at'] = time();
		update_option( self::OPTION, $state, false );

		return $this->parse( $state['lists'] )['report'];
	}

	/* ------------------------------------------------------------------ */
	/* Parsing (pure PHP, no Elasticsearch)                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Parse the four lists into structured rules plus a human report.
	 *
	 * @param array $lists Raw text per list key.
	 * @return array{rules: array<int, array>, stopwords: string[], report: array}
	 */
	public function parse( array $lists ): array {
		$rules     = array();
		$stopwords = array();
		$errors    = array();
		$counts    = array_fill_keys( self::LISTS, 0 );
		$seen      = array();
		$dupes     = 0;

		foreach ( self::LISTS as $key ) {
			$text  = (string) ( $lists[ $key ] ?? '' );
			$lines = preg_split( '/\n/', str_replace( array( "\r\n", "\r" ), "\n", $text ) );

			foreach ( $lines as $n => $raw ) {
				$line = trim( $raw );
				if ( '' === $line || '#' === $line[0] ) {
					continue;
				}
				$line_no = $n + 1;

				if ( 'stopwords' === $key ) {
					foreach ( preg_split( '/[,;\t]+/', $line ) as $word ) {
						$word = self::clean_term( $word );
						if ( '' === $word ) {
							continue;
						}
						if ( false !== strpos( $word, ' ' ) ) {
							$errors[] = self::issue( $key, $line_no, $raw, __( 'Palavras ignoradas vão uma de cada vez — expressões com espaço não são aceitas aqui.', 'tainacan-index-manager' ) );
							continue;
						}
						$folded = remove_accents( $word );
						if ( ! isset( $stopwords[ $folded ] ) ) {
							$stopwords[ $folded ] = true;
							++$counts[ $key ];
						}
					}
					continue;
				}

				$rule = $this->parse_rule_line( $key, $line );
				if ( is_string( $rule ) ) {
					$errors[] = self::issue( $key, $line_no, $raw, $rule );
					continue;
				}

				$sig = $rule['type'] . '|' . implode( ',', $rule['left'] ) . '|' . implode( ',', $rule['right'] );
				if ( isset( $seen[ $sig ] ) ) {
					++$dupes;
					continue;
				}
				$seen[ $sig ]  = true;
				$rule['list']  = $key;
				$rule['line']  = $line_no;
				$rules[]       = $rule;
				++$counts[ $key ];
			}
		}

		if ( count( $rules ) > self::MAX_RULES ) {
			$errors[] = self::issue( '', 0, '', sprintf(
				/* translators: %1$s = regras, %2$s = limite */
				__( 'São %1$s regras; o limite é %2$s. Divida o vocabulário ou remova regras pouco usadas.', 'tainacan-index-manager' ),
				number_format_i18n( count( $rules ) ),
				number_format_i18n( self::MAX_RULES )
			) );
		}
		if ( count( $stopwords ) > self::MAX_STOPWORDS ) {
			$errors[] = self::issue( 'stopwords', 0, '', sprintf(
				/* translators: %s = limite */
				__( 'A lista de palavras ignoradas passou do limite de %s palavras.', 'tainacan-index-manager' ),
				number_format_i18n( self::MAX_STOPWORDS )
			) );
		}

		return array(
			'rules'     => $rules,
			'stopwords' => array_keys( $stopwords ),
			'report'    => array(
				'ok'         => empty( $errors ),
				'counts'     => $counts,
				'duplicates' => $dupes,
				'errors'     => array_slice( $errors, 0, 200 ),
				'errors_total' => count( $errors ),
			),
		);
	}

	/**
	 * Parse one line of the synonyms/variants/corrections lists.
	 *
	 * @return array|string Rule array, or an error message.
	 */
	private function parse_rule_line( string $key, string $line ) {
		// Accept the arrows people actually type.
		$line  = str_replace( array( '→', '->' ), '=>', $line );
		$arrow = strpos( $line, '=>' );

		if ( false !== $arrow ) {
			$left  = self::split_terms( substr( $line, 0, $arrow ) );
			$right = self::split_terms( substr( $line, $arrow + 2 ) );
			if ( is_string( $left ) ) {
				return $left;
			}
			if ( is_string( $right ) ) {
				return $right;
			}
			if ( empty( $left ) || empty( $right ) ) {
				return __( 'A seta "=>" precisa de termos dos dois lados: o que a pessoa digita => o que a busca deve procurar.', 'tainacan-index-manager' );
			}
			if ( 'corrections' === $key ) {
				// Keep what was typed: catalogue records carry the same misspellings
				// the public types, and dropping the typed form would hide them.
				$right = array_values( array_unique( array_merge( $left, $right ) ) );
			}
			if ( ! array_diff( $right, $left ) && ! array_diff( $left, $right ) ) {
				return __( 'Os dois lados da seta são iguais — a regra não mudaria nada.', 'tainacan-index-manager' );
			}
			return array( 'type' => 'map', 'left' => $left, 'right' => $right );
		}

		if ( 'corrections' === $key ) {
			return __( 'Nesta lista cada linha usa uma seta: o que a pessoa digita => o que a busca deve procurar. Ex.: excessão => exceção', 'tainacan-index-manager' );
		}

		$terms = self::split_terms( $line );
		if ( is_string( $terms ) ) {
			return $terms;
		}
		if ( count( $terms ) < 2 ) {
			return __( 'Uma equivalência precisa de pelo menos dois termos separados por vírgula. Ex.: quadro, pintura, tela', 'tainacan-index-manager' );
		}
		return array( 'type' => 'equiv', 'left' => $terms, 'right' => array() );
	}

	/**
	 * @return string[]|string Terms, or an error message.
	 */
	private static function split_terms( string $chunk ) {
		$out = array();
		foreach ( preg_split( '/[,;\t]+/', $chunk ) as $piece ) {
			$term = self::clean_term( $piece );
			if ( '' === $term ) {
				continue;
			}
			if ( mb_strlen( $term ) > self::MAX_TERM_CHARS ) {
				return sprintf(
					/* translators: %d = limite de caracteres */
					__( 'Termo com mais de %d caracteres. Sinônimos são palavras ou expressões curtas, não frases.', 'tainacan-index-manager' ),
					self::MAX_TERM_CHARS
				);
			}
			$out[] = $term;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Lowercase, drop characters that mean something in the rule syntax, collapse spaces.
	 */
	private static function clean_term( string $term ): string {
		$term = wp_strip_all_tags( $term );
		$term = preg_replace( '/[^\p{L}\p{N}\s\'\-\.&]/u', ' ', $term );
		$term = preg_replace( '/\s+/u', ' ', (string) $term );
		return trim( mb_strtolower( (string) $term ) );
	}

	private static function issue( string $list, int $line, string $text, string $message ): array {
		return array(
			'list'    => $list,
			'line'    => $line,
			'text'    => mb_substr( trim( $text ), 0, 160 ),
			'message' => $message,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Analysis definition                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Filters and search analyzer for a compiled vocabulary.
	 *
	 * With an empty vocabulary the search analyzer is exactly the historical one,
	 * so installing this version changes nothing until someone applies a list.
	 *
	 * @param array $applied `rules` (Solr-format strings) and `stopwords`.
	 * @param bool  $strict  Reject bad rules instead of skipping them (rehearsal).
	 * @return array{filter: array, analyzer: array}
	 */
	public static function search_analysis( array $applied, bool $strict = false ): array {
		$rules     = array_values( array_filter( (array) ( $applied['rules'] ?? array() ), 'is_string' ) );
		$stopwords = array_values( array_filter( (array) ( $applied['stopwords'] ?? array() ), 'is_string' ) );

		if ( empty( $rules ) && empty( $stopwords ) ) {
			return array(
				'filter'   => array(),
				'analyzer' => array(
					'tnc_pt_br_search' => array(
						'tokenizer' => 'standard',
						'filter'    => Index_Manager::base_chain(),
					),
				),
			);
		}

		$filters = array();
		$chain   = array( 'lowercase', 'asciifolding', 'brazilian_stop' );

		if ( ! empty( $stopwords ) ) {
			$filters[ self::FILTER_STOP ] = array(
				'type'        => 'stop',
				'stopwords'   => $stopwords,
				'ignore_case' => true,
			);
			$chain[] = self::FILTER_STOP;
		}

		$chain[] = 'brazilian_stemmer';

		if ( ! empty( $rules ) ) {
			$filters[ self::FILTER_SYNONYMS ] = array(
				'type'     => 'synonym_graph',
				'synonyms' => $rules,
				// On the live index a rule Elasticsearch cannot parse is skipped rather
				// than keeping the index from opening. The rehearsal runs strict, so by
				// the time this reaches the live index every rule has been accepted.
				'lenient'  => ! $strict,
			);
			$chain[] = self::FILTER_SYNONYMS;
		}

		return array(
			'filter'   => $filters,
			'analyzer' => array(
				'tnc_pt_br_search' => array(
					'tokenizer' => 'standard',
					'filter'    => $chain,
				),
			),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Apply                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Send the saved lists to Elasticsearch.
	 *
	 * Steps, each reported back to the panel:
	 *  1. validate the lists;
	 *  2. normalise every term through the index's own filters (temporary index);
	 *  3. rehearse the final analyzer on the temporary index, strict mode;
	 *  4. close the real index, write the analyzer, reopen — always reopen;
	 *  5. confirm the index is back and the analyzer is the new one.
	 */
	public function apply(): array {
		$started = time();
		$t0      = microtime( true );
		$steps   = array();
		self::$lap = $t0;
		$notes   = array();

		if ( false !== get_transient( self::LOCK ) ) {
			return $this->result( false, $steps, __( 'Já existe uma aplicação do vocabulário em andamento. Aguarde alguns segundos e atualize a página.', 'tainacan-index-manager' ) );
		}
		set_transient( self::LOCK, 1, 120 );

		try {
			$state  = self::state();
			$parsed = $this->parse( $state['lists'] );

			/* 1. Validate */
			if ( ! $parsed['report']['ok'] ) {
				$steps[] = self::step( 'validate', 'fail', sprintf(
					/* translators: %d = erros */
					_n( '%d linha precisa de correção.', '%d linhas precisam de correção.', $parsed['report']['errors_total'], 'tainacan-index-manager' ),
					$parsed['report']['errors_total']
				) );
				return $this->result( false, $steps, __( 'Corrija as linhas marcadas antes de aplicar. Nada foi enviado ao Elasticsearch.', 'tainacan-index-manager' ), $parsed['report'] );
			}
			$steps[] = self::step( 'validate', 'ok', sprintf(
				/* translators: %1$d regras, %2$d palavras ignoradas */
				__( '%1$d regras e %2$d palavras ignoradas, sem erros.', 'tainacan-index-manager' ),
				count( $parsed['rules'] ),
				count( $parsed['stopwords'] )
			) );

			if ( ! $this->client->is_configured() ) {
				$steps[] = self::step( 'connect', 'fail', __( 'Elasticsearch não configurado.', 'tainacan-index-manager' ) );
				return $this->result( false, $steps, __( 'Configure a conexão com o Elasticsearch primeiro.', 'tainacan-index-manager' ) );
			}

			$index  = (string) $this->settings->get( 'index_name' );
			$exists = $this->client->index_exists( $index );
			if ( is_wp_error( $exists ) ) {
				$steps[] = self::step( 'connect', 'fail', $exists->get_error_message() );
				return $this->result( false, $steps, __( 'Não foi possível falar com o Elasticsearch. Nada foi alterado.', 'tainacan-index-manager' ) );
			}

			$probe_index = substr( $index, 0, 180 ) . '-vocab-check';

			/* 2. Normalise terms */
			$normalized = $this->normalize_terms( $probe_index, $parsed['rules'], $parsed['stopwords'] );
			if ( is_wp_error( $normalized ) ) {
				$steps[] = self::step( 'normalize', 'fail', $normalized->get_error_message() );
				$this->drop_index_quietly( $probe_index );
				return $this->result( false, $steps, __( 'O Elasticsearch recusou a análise dos termos. Nada foi alterado no índice.', 'tainacan-index-manager' ) );
			}

			$compiled = $this->compile( $parsed['rules'], $normalized, $notes );
			$applied  = array(
				'rules'       => $compiled,
				'stopwords'   => $parsed['stopwords'],
				'counts'      => $parsed['report']['counts'],
				'source_hash' => self::lists_hash( $state['lists'] ),
				'notes'       => array_slice( $notes, 0, 100 ),
				'notes_total' => count( $notes ),
			);
			$steps[] = self::step( 'normalize', 'ok', empty( $notes )
				? __( 'Todos os termos passaram pelo analisador sem ajustes.', 'tainacan-index-manager' )
				: sprintf(
					/* translators: %d = ajustes */
					_n( '%d termo foi ajustado (veja as observações abaixo).', '%d termos foram ajustados (veja as observações abaixo).', count( $notes ), 'tainacan-index-manager' ),
					count( $notes )
				) );

			/* 3. Rehearsal, strict */
			$rehearsal = $this->rehearse( $probe_index, $applied );
			if ( is_wp_error( $rehearsal ) ) {
				$steps[] = self::step( 'rehearsal', 'fail', $rehearsal->get_error_message() );
				$this->drop_index_quietly( $probe_index );
				return $this->result( false, $steps, __( 'O ensaio num índice temporário falhou — o índice real não foi tocado.', 'tainacan-index-manager' ) );
			}
			$applied['samples'] = $rehearsal;
			$this->drop_index_quietly( $probe_index );
			$steps[] = self::step( 'rehearsal', 'ok', __( 'O Elasticsearch aceitou o vocabulário num índice temporário, já apagado.', 'tainacan-index-manager' ) );

			/* 4. Real index */
			if ( ! $exists ) {
				$steps[] = self::step( 'install', 'skip', sprintf(
					/* translators: %s = índice */
					__( 'O índice "%s" ainda não existe; ele já será criado com este vocabulário.', 'tainacan-index-manager' ),
					$index
				) );
				$this->store_applied( $applied, $index );
				return $this->result( true, $steps, __( 'Vocabulário salvo. Ele entra em vigor quando o índice for criado.', 'tainacan-index-manager' ) );
			}

			$installed = $this->install( $index, $applied, $steps );
			if ( ! $installed ) {
				return $this->result( false, $steps, __( 'O vocabulário não foi aplicado. O índice foi reaberto com o analisador anterior — a busca segue como antes.', 'tainacan-index-manager' ) );
			}

			$this->store_applied( $applied, $index );
			Search_Integration::forget_fallback_since( $started );
			delete_transient( 'tainacan_idxmgr_health_snapshot' );

			$this->logger->info( Logger::CHAN_VOCABULARY, 'Vocabulário da busca aplicado.', array(
				'index'     => $index,
				'rules'     => count( $compiled ),
				'stopwords' => count( $parsed['stopwords'] ),
				'notes'     => count( $notes ),
				'ms'        => (int) round( ( microtime( true ) - $t0 ) * 1000 ),
			) );

			return $this->result( true, $steps, sprintf(
				/* translators: %s = segundos */
				__( 'Vocabulário aplicado em %s s. A busca já está usando as novas regras — não é preciso reindexar.', 'tainacan-index-manager' ),
				number_format_i18n( microtime( true ) - $t0, 1 )
			) );
		} catch ( \Throwable $e ) {
			$this->logger->error( Logger::CHAN_VOCABULARY, 'Falha inesperada ao aplicar o vocabulário.', array( 'error' => $e->getMessage() ) );
			$steps[] = self::step( 'error', 'fail', $e->getMessage() );
			return $this->result( false, $steps, __( 'Falha inesperada. Consulte a aba Logs do painel.', 'tainacan-index-manager' ) );
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/**
	 * Close → write analyzer → reopen → confirm. The index is reopened whatever
	 * happens in between; a closed index would be far worse than an old analyzer.
	 */
	private function install( string $index, array $applied, array &$steps ): bool {
		$analysis  = self::search_analysis( $applied );
		$closed_at = microtime( true );

		$closed = $this->client->close_index( $index );
		if ( is_wp_error( $closed ) ) {
			$steps[] = self::step( 'close', 'fail', $closed->get_error_message() );
			return false;
		}
		$steps[] = self::step( 'close', 'ok', __( 'Índice fechado. Durante estes segundos a busca responde pelo banco (SQL).', 'tainacan-index-manager' ) );

		$written = $this->client->put_index_settings( $index, array(
			'index' => array(
				'analysis' => array(
					'filter'   => array_merge( Index_Manager::base_filters(), $analysis['filter'] ),
					'analyzer' => $analysis['analyzer'],
				),
			),
		) );
		$write_ok = ! is_wp_error( $written );
		$steps[]  = self::step( 'write', $write_ok ? 'ok' : 'fail', $write_ok
			? __( 'Analisador de busca atualizado.', 'tainacan-index-manager' )
			: $written->get_error_message() );

		$opened = $this->client->open_index( $index );
		if ( is_wp_error( $opened ) ) {
			sleep( 2 );
			$opened = $this->client->open_index( $index );
		}
		if ( is_wp_error( $opened ) ) {
			$this->logger->critical( Logger::CHAN_VOCABULARY, 'O índice não reabriu depois de aplicar o vocabulário.', array(
				'index' => $index,
				'error' => $opened->get_error_message(),
			) );
			$steps[] = self::step( 'open', 'fail', sprintf(
				/* translators: %s = erro */
				__( 'O índice não reabriu: %s. A busca segue pelo SQL até ele ser reaberto (POST /<índice>/_open).', 'tainacan-index-manager' ),
				$opened->get_error_message()
			) );
			return false;
		}

		$health = $this->client->wait_for_index( $index, 'yellow', 30 );
		$ready  = is_array( $health ) && empty( $health['tim_tolerated'] ) && empty( $health['timed_out'] );
		$steps[] = self::step( 'open', $ready ? 'ok' : 'fail', $ready
			? sprintf(
				/* translators: %s = segundos */
				__( 'Índice reaberto e respondendo. Ficou fechado por %s s.', 'tainacan-index-manager' ),
				number_format_i18n( microtime( true ) - $closed_at, 1 )
			)
			: __( 'Índice reaberto, mas ainda não ficou pronto em 30 s. Acompanhe o semáforo.', 'tainacan-index-manager' ) );

		if ( ! $write_ok || ! $ready ) {
			return false;
		}

		// Confirm by reading back, not by trusting the acknowledgement.
		$settings = $this->client->get_index_settings( $index );
		$chain    = is_array( $settings ) ? ( $settings[ $index ]['settings']['index']['analysis']['analyzer']['tnc_pt_br_search']['filter'] ?? null ) : null;
		$expected = $analysis['analyzer']['tnc_pt_br_search']['filter'];
		$matches  = is_array( $chain ) && array_values( $chain ) === $expected;
		$steps[]  = self::step( 'verify', $matches ? 'ok' : 'fail', $matches
			? __( 'Conferido: o índice está usando o novo analisador.', 'tainacan-index-manager' )
			: __( 'O analisador lido de volta não é o que foi enviado.', 'tainacan-index-manager' ) );

		return $matches;
	}

	/**
	 * Run every term through lowercase → asciifolding → stopwords (the part of
	 * the search chain that precedes the synonym filter, minus the stemmer).
	 *
	 * Why: Elasticsearch parses each rule through the filters before it, and a
	 * term with a stopword inside ("rio de janeiro") comes out with a gap, which
	 * makes the whole rule invalid. Writing the term as the analyzer sees it
	 * ("rio janeiro") keeps the rule, and queries still match it — tested with
	 * "rio de janeiro" against "Vista do Rio de Janeiro".
	 *
	 * All terms go in a handful of `_analyze` calls, separated by a sentinel
	 * token, instead of one call per term.
	 *
	 * @param string[] $stopwords Extra ignored words, part of the same chain.
	 * @return array<string,string>|\WP_Error term => normalised term ('' = nothing left).
	 */
	private function normalize_terms( string $probe_index, array $rules, array $stopwords ) {
		$terms = array();
		foreach ( $rules as $rule ) {
			foreach ( array_merge( $rule['left'], $rule['right'] ) as $t ) {
				$terms[ $t ] = true;
			}
		}
		$terms = array_keys( $terms );
		if ( empty( $terms ) ) {
			return array();
		}

		$this->drop_index_quietly( $probe_index );

		$filters = Index_Manager::base_filters();
		$chain   = array( 'lowercase', 'asciifolding', 'brazilian_stop' );
		if ( ! empty( $stopwords ) ) {
			$filters[ self::FILTER_STOP ] = array( 'type' => 'stop', 'stopwords' => $stopwords, 'ignore_case' => true );
			$chain[]                      = self::FILTER_STOP;
		}

		$created = $this->client->create_index( $probe_index, array(
			'settings' => array(
				'number_of_shards'   => 1,
				'number_of_replicas' => 0,
				'analysis'           => array(
					'filter'   => $filters,
					'analyzer' => array(
						self::PROBE_ANALYZER => array( 'tokenizer' => 'standard', 'filter' => $chain ),
					),
				),
			),
		) );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$map = array();
		foreach ( array_chunk( $terms, self::PROBE_CHUNK ) as $chunk ) {
			$res = $this->client->analyze( $probe_index, array(
				'analyzer' => self::PROBE_ANALYZER,
				'text'     => implode( ' ' . self::SEPARATOR . ' ', $chunk ),
			) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}

			$groups  = array( array() );
			foreach ( (array) ( $res['tokens'] ?? array() ) as $tok ) {
				if ( self::SEPARATOR === ( $tok['token'] ?? '' ) ) {
					$groups[] = array();
					continue;
				}
				$groups[ count( $groups ) - 1 ][] = (string) $tok['token'];
			}
			if ( count( $groups ) !== count( $chunk ) ) {
				return new \WP_Error( 'tim_vocab_probe', __( 'A análise dos termos voltou desalinhada; por segurança, nada foi aplicado.', 'tainacan-index-manager' ) );
			}
			foreach ( $chunk as $i => $term ) {
				$map[ $term ] = implode( ' ', $groups[ $i ] );
			}
		}

		return $map;
	}

	/**
	 * Turn structured rules into Solr-format strings, using normalised terms.
	 *
	 * @param array $notes Collects a human note for every term that changed meaningfully.
	 * @return string[]
	 */
	private function compile( array $rules, array $normalized, array &$notes ): array {
		$out = array();

		$fix = function ( array $terms, array $rule ) use ( $normalized, &$notes ): array {
			$kept = array();
			foreach ( $terms as $term ) {
				$norm = $normalized[ $term ] ?? $term;
				if ( '' === $norm ) {
					$notes[] = self::note( $rule, sprintf(
						/* translators: %s = termo */
						__( '"%s" só tem palavras ignoradas pela busca (como "de", "da", "o") e foi deixado de fora da regra.', 'tainacan-index-manager' ),
						$term
					) );
					continue;
				}
				// Case and accents are expected to change; only report dropped words.
				if ( substr_count( $norm, ' ' ) < substr_count( $term, ' ' ) ) {
					$notes[] = self::note( $rule, sprintf(
						/* translators: %1$s = termo original, %2$s = termo gravado */
						__( '"%1$s" foi gravado como "%2$s": palavras como "de" e "da" não entram nas regras, mas a busca por "%1$s" continua encontrando a regra.', 'tainacan-index-manager' ),
						$term,
						$norm
					) );
				}
				$kept[] = $norm;
			}
			return array_values( array_unique( $kept ) );
		};

		foreach ( $rules as $rule ) {
			$left = $fix( $rule['left'], $rule );
			if ( 'equiv' === $rule['type'] ) {
				if ( count( $left ) < 2 ) {
					$notes[] = self::note( $rule, __( 'Depois dos ajustes, a regra ficou com menos de dois termos e foi ignorada.', 'tainacan-index-manager' ) );
					continue;
				}
				$out[] = implode( ', ', $left );
				continue;
			}
			$right = $fix( $rule['right'], $rule );
			if ( empty( $left ) || empty( $right ) || $left === $right ) {
				$notes[] = self::note( $rule, __( 'Depois dos ajustes, a regra não tinha mais efeito e foi ignorada.', 'tainacan-index-manager' ) );
				continue;
			}
			$out[] = implode( ', ', $left ) . ' => ' . implode( ', ', $right );
		}

		return array_values( array_unique( $out ) );
	}

	private static function note( array $rule, string $message ): array {
		return array(
			'list'    => (string) ( $rule['list'] ?? '' ),
			'line'    => (int) ( $rule['line'] ?? 0 ),
			'message' => $message,
		);
	}

	/**
	 * Create a throwaway index with the final analyzer, strict, and show what a
	 * few rules expand to. Returns the samples, or the error Elasticsearch gave.
	 *
	 * @return array|\WP_Error
	 */
	private function rehearse( string $probe_index, array $applied ) {
		$this->drop_index_quietly( $probe_index );

		$analysis = self::search_analysis( $applied, true );
		$created  = $this->client->create_index( $probe_index, array(
			'settings' => array(
				'number_of_shards'   => 1,
				'number_of_replicas' => 0,
				'analysis'           => array(
					'filter'   => array_merge( Index_Manager::base_filters(), $analysis['filter'] ),
					'analyzer' => $analysis['analyzer'],
				),
			),
		) );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$samples = array();
		foreach ( array_slice( (array) ( $applied['rules'] ?? array() ), 0, 4 ) as $rule ) {
			$first = trim( (string) preg_split( '/,|=>/', $rule )[0] );
			$res   = $this->client->analyze( $probe_index, array( 'analyzer' => 'tnc_pt_br_search', 'text' => $first ) );
			if ( is_array( $res ) ) {
				$samples[] = array(
					'text'  => $first,
					'forms' => self::group_tokens( $res ),
				);
			}
		}
		return $samples;
	}

	private function store_applied( array $applied, string $index ): void {
		$state            = self::state();
		$applied['at']    = time();
		$applied['index'] = $index;
		$state['applied'] = $applied;
		update_option( self::OPTION, $state, false );
	}

	private function drop_index_quietly( string $index ): void {
		$exists = $this->client->index_exists( $index );
		if ( true === $exists ) {
			$this->client->delete_index( $index );
		}
	}

	/** Start of the step being timed; each step() reports the time since the previous one. */
	private static float $lap = 0.0;

	private static function step( string $key, string $status, string $detail ): array {
		$now       = microtime( true );
		$ms        = self::$lap > 0 ? (int) round( ( $now - self::$lap ) * 1000 ) : 0;
		self::$lap = $now;
		$labels = array(
			'validate'  => __( 'Conferir as listas', 'tainacan-index-manager' ),
			'connect'   => __( 'Conectar ao Elasticsearch', 'tainacan-index-manager' ),
			'normalize' => __( 'Passar os termos pelo analisador', 'tainacan-index-manager' ),
			'rehearsal' => __( 'Ensaiar num índice temporário', 'tainacan-index-manager' ),
			'install'   => __( 'Instalar no índice', 'tainacan-index-manager' ),
			'close'     => __( 'Fechar o índice', 'tainacan-index-manager' ),
			'write'     => __( 'Gravar o novo analisador de busca', 'tainacan-index-manager' ),
			'open'      => __( 'Reabrir o índice', 'tainacan-index-manager' ),
			'verify'    => __( 'Conferir o resultado', 'tainacan-index-manager' ),
			'error'     => __( 'Erro', 'tainacan-index-manager' ),
		);
		return array(
			'key'    => $key,
			'label'  => $labels[ $key ] ?? $key,
			'status' => $status,
			'detail' => $detail,
			'ms'     => $ms,
		);
	}

	private function result( bool $ok, array $steps, string $message, ?array $report = null ): array {
		$out = array(
			'ok'      => $ok,
			'message' => $message,
			'steps'   => $steps,
			'state'   => $this->to_public_array(),
		);
		if ( null !== $report ) {
			$out['report'] = $report;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Try a search term                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Show what the search does with a term, and how many items it finds with
	 * and without the vocabulary.
	 */
	public function test( string $text ): array {
		$text  = trim( wp_strip_all_tags( $text ) );
		$index = (string) $this->settings->get( 'index_name' );
		if ( '' === $text ) {
			return array( 'ok' => false, 'message' => __( 'Digite uma palavra ou expressão.', 'tainacan-index-manager' ) );
		}
		if ( true !== $this->client->index_exists( $index ) ) {
			return array( 'ok' => false, 'message' => __( 'O índice ainda não existe — crie-o em Configurações.', 'tainacan-index-manager' ) );
		}

		$with = $this->client->analyze( $index, array( 'analyzer' => 'tnc_pt_br_search', 'text' => $text ) );
		if ( is_wp_error( $with ) ) {
			return array( 'ok' => false, 'message' => $with->get_error_message() );
		}
		$without = $this->client->analyze( $index, array(
			'tokenizer' => 'standard',
			'filter'    => array( 'lowercase', 'asciifolding', 'brazilian_stop', 'brazilian_stemmer' ),
			'text'      => $text,
		) );

		return array(
			'ok'            => true,
			'text'          => $text,
			'forms'         => self::group_tokens( $with ),
			'forms_without' => is_array( $without ) ? self::group_tokens( $without ) : array(),
			'count_with'    => $this->count_matches( $index, $text, 'tnc_pt_br_search' ),
			'count_without' => $this->count_matches( $index, $text, 'tnc_pt_br' ),
		);
	}

	/**
	 * Published items matching the same free-text query the search runs.
	 */
	private function count_matches( string $index, string $text, string $analyzer ): ?int {
		$res = $this->client->count( $index, array(
			'query' => array(
				'bool' => array(
					'must'   => array(
						array(
							'multi_match' => array(
								'query'    => $text,
								'fields'   => array( 'title^3', 'description^2', 'content', 'metadata.value_text', 'taxonomies.terms' ),
								'operator' => 'and',
								'analyzer' => $analyzer,
								'auto_generate_synonyms_phrase_query' => false,
							),
						),
					),
					'filter' => array( array( 'term' => array( 'post_status' => 'publish' ) ) ),
				),
			),
		) );
		return is_wp_error( $res ) ? null : (int) $res;
	}

	/**
	 * Group analyzer tokens by position: each group is one "word" of the query
	 * and every form in it is an alternative the search accepts.
	 *
	 * @return array<int, string[]>
	 */
	private static function group_tokens( array $res ): array {
		$by_pos = array();
		foreach ( (array) ( $res['tokens'] ?? array() ) as $tok ) {
			$pos = (int) ( $tok['position'] ?? 0 );
			$by_pos[ $pos ][] = (string) ( $tok['token'] ?? '' );
		}
		ksort( $by_pos );
		return array_values( array_map( static function ( $forms ) {
			return array_values( array_unique( $forms ) );
		}, $by_pos ) );
	}
}
