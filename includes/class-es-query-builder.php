<?php
/**
 * Translation of WP_Query arguments into Elasticsearch query DSL.
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * Builds an ES query body out of the parts of a WP_Query that Tainacan uses to
 * list and filter items.
 *
 * Design rule: **fidelity over coverage**. Every translation here must return
 * exactly the same set of items the SQL query would have returned. Anything that
 * cannot be reproduced faithfully (an unknown compare operator, an ordering that
 * depends on data the index does not hold, a taxonomy field we do not index)
 * makes the builder return `null`, and the caller then lets WordPress run its
 * normal SQL. A wrong-but-fast result set is worse than a slow correct one.
 */
final class ES_Query_Builder {

	/** Statuses the indexer stores; anything outside this cannot be answered from ES. */
	private const INDEXED_STATUSES = array( 'publish', 'private', 'draft' );

	/**
	 * Build the `query` portion of an ES search body.
	 *
	 * @param \WP_Query $query      The query being answered.
	 * @param string[]  $post_types Tainacan item post types in play.
	 * @return array|null ES bool query, or null when it cannot be translated faithfully.
	 */
	public static function build( \WP_Query $query, array $post_types ): ?array {
		return self::build_from_args(
			array(
				'post_type'    => $query->get( 'post_type' ),
				'post_status'  => $query->get( 'post_status' ),
				's'            => $query->get( 's' ),
				'meta_query'   => $query->get( 'meta_query' ),
				'tax_query'    => $query->get( 'tax_query' ),
				'author'       => $query->get( 'author' ),
				'post__in'     => $query->get( 'post__in' ),
				'post__not_in' => $query->get( 'post__not_in' ),
			),
			$post_types
		);
	}

	/**
	 * Query vars that change which items match, and that this builder translates.
	 */
	private const HANDLED_ARGS = array(
		'post_type',
		'post_status',
		'perm',
		's',
		'meta_query',
		'tax_query',
		'author',
		'post__in',
		'post__not_in',
	);

	/**
	 * Query vars that do not change *which* items match (only ordering, paging,
	 * shape of the result or caching), so they are safe to ignore here.
	 */
	private const IGNORABLE_ARGS = array(
		'fields',
		'paged',
		'offset',
		'number',
		'perpage',
		'posts_per_page',
		'posts_per_archive_page',
		'nopaging',
		'no_found_rows',
		'order',
		'orderby',
		'cache_results',
		'update_post_meta_cache',
		'update_post_term_cache',
		'lazy_load_term_meta',
		'suppress_filters',
		'ignore_sticky_posts',
	);

	/**
	 * Build an ES query from raw WP_Query-style arguments.
	 *
	 * @param array    $args       WP_Query-style arguments.
	 * @param string[] $post_types Tainacan item post types in play.
	 * @param bool     $strict     When true, refuse any argument this builder does not
	 *                             explicitly understand. Callers that must reproduce a
	 *                             filtered set exactly (facet counts) require this;
	 *                             silently dropping an unknown filter would inflate counts.
	 * @return array|null
	 */
	public static function build_from_args( array $args, array $post_types, bool $strict = false ): ?array {
		$filter   = array();
		$must     = array();
		$must_not = array();

		if ( empty( $post_types ) ) {
			return null;
		}

		if ( $strict ) {
			foreach ( $args as $key => $value ) {
				if ( in_array( $key, self::HANDLED_ARGS, true ) || in_array( $key, self::IGNORABLE_ARGS, true ) ) {
					continue;
				}
				// An argument we do not model (date_query, meta_key, sentence, ...).
				// Ignoring it would silently widen the result set.
				if ( null !== $value && '' !== $value && array() !== $value ) {
					return null;
				}
			}
		}

		$filter[] = array( 'terms' => array( 'post_type' => array_values( array_map( 'strval', $post_types ) ) ) );

		$statuses = self::resolve_statuses( $args['post_status'] ?? null, $args['perm'] ?? '', $post_types );
		if ( null === $statuses ) {
			return null;
		}
		$filter[] = array( 'terms' => array( 'post_status' => $statuses ) );

		// Free-text search.
		$s = trim( (string) ( $args['s'] ?? '' ) );
		if ( '' !== $s ) {
			$match = array(
				'query'    => $s,
				'fields'   => array( 'title^3', 'description^2', 'content', 'metadata.value_text', 'taxonomies.terms' ),
				'operator' => 'and',
				// A multi-word synonym ("rio de janeiro, rj") would otherwise become a
				// phrase query. Stopwords leave position gaps in the index ("rio _
				// janeiro"), so the phrase never matches and the expansion loses the
				// very documents it was meant to find. Measured on a lab index: with
				// the phrase, "rio de janeiro" missed "Vista do Rio de Janeiro".
				'auto_generate_synonyms_phrase_query' => false,
			);
			if ( self::typo_tolerance_enabled() ) {
				// AUTO = 0 edits up to 2 letters, 1 up to 5, 2 beyond. The first letter
				// must match, which keeps the expansion cheap and the results sane.
				$match['fuzziness']     = 'AUTO';
				$match['prefix_length'] = 1;
			}
			$must[] = array( 'multi_match' => $match );
		}

		// Explicit ID restrictions.
		$post_in = $args['post__in'] ?? null;
		if ( ! empty( $post_in ) && is_array( $post_in ) ) {
			$filter[] = array( 'terms' => array( 'item_id' => array_values( array_map( 'intval', $post_in ) ) ) );
		}
		$post_not_in = $args['post__not_in'] ?? null;
		if ( ! empty( $post_not_in ) && is_array( $post_not_in ) ) {
			$must_not[] = array( 'terms' => array( 'item_id' => array_values( array_map( 'intval', $post_not_in ) ) ) );
		}

		$author = $args['author'] ?? null;
		if ( ! empty( $author ) && is_numeric( $author ) ) {
			$filter[] = array( 'term' => array( 'author_id' => (int) $author ) );
		}

		// Metadata filters (Tainacan facets on non-taxonomy metadata).
		$meta_query = $args['meta_query'] ?? null;
		if ( ! empty( $meta_query ) && is_array( $meta_query ) ) {
			$translated = self::translate_meta_query( $meta_query );
			if ( null === $translated ) {
				return null;
			}
			if ( ! empty( $translated ) ) {
				$filter[] = $translated;
			}
		}

		// Taxonomy filters (Tainacan facets on taxonomy metadata).
		$tax_query = $args['tax_query'] ?? null;
		if ( ! empty( $tax_query ) && is_array( $tax_query ) ) {
			$translated = self::translate_tax_query( $tax_query );
			if ( null === $translated ) {
				return null;
			}
			if ( ! empty( $translated ) ) {
				$filter[] = $translated;
			}
		}

		$bool = array();
		if ( ! empty( $must ) ) {
			$bool['must'] = $must;
		}
		if ( ! empty( $filter ) ) {
			$bool['filter'] = $filter;
		}
		if ( ! empty( $must_not ) ) {
			$bool['must_not'] = $must_not;
		}
		if ( empty( $bool ) ) {
			$bool['must'] = array( array( 'match_all' => new \stdClass() ) );
		}

		return array( 'bool' => $bool );
	}

	/**
	 * Whether free-text search should accept small typos (setting "Tolerância a
	 * erros de digitação"). Read from the option directly: the builder is static
	 * and WordPress already caches the autoloaded option for the request.
	 */
	private static function typo_tolerance_enabled(): bool {
		$all = Settings::all();
		return ! empty( $all['search_typo_tolerance'] );
	}

	/**
	 * Determine which post statuses the query asks for.
	 *
	 * @param mixed    $requested  Raw `post_status` query var.
	 * @param mixed    $perm       Raw `perm` query var ('readable' / 'editable').
	 * @param string[] $post_types Post types in play, for capability lookup.
	 * @return string[]|null Null when the query wants a status the index does not hold.
	 */
	private static function resolve_statuses( $requested, $perm = '', array $post_types = array() ): ?array {
		$perm = is_string( $perm ) ? strtolower( trim( $perm ) ) : '';

		if ( empty( $requested ) ) {
			// WP defaults to `publish` on the front end. Being explicit keeps deleted
			// or unpublished leftovers in the index from surfacing publicly.
			if ( '' === $perm ) {
				return array( 'publish' );
			}

			// `perm` widens the default set to whatever this viewer is allowed to
			// see. Anonymous visitors get public posts only — the same as the
			// default — so nothing changes for them.
			if ( ! is_user_logged_in() ) {
				return array( 'publish' );
			}

			if ( self::can_read_private( $post_types ) ) {
				return array( 'publish', 'private' );
			}

			// Remaining case: a logged-in user without the capability still sees
			// their *own* private items. That is an author-scoped condition rather
			// than a plain status filter, and getting it wrong would hide a
			// curator's own records, so let SQL answer it.
			return null;
		}

		$requested = (array) $requested;
		if ( in_array( 'any', $requested, true ) ) {
			return self::INDEXED_STATUSES;
		}

		$statuses = array();
		foreach ( $requested as $status ) {
			$status = (string) $status;
			if ( ! in_array( $status, self::INDEXED_STATUSES, true ) ) {
				// e.g. `trash`, `auto-draft` — never indexed, so ES cannot answer this.
				return null;
			}
			$statuses[] = $status;
		}

		return empty( $statuses ) ? array( 'publish' ) : array_values( array_unique( $statuses ) );
	}

	/**
	 * Whether the current user may read private items of every post type in play.
	 *
	 * Uses each post type's own `read_private_posts` capability, since Tainacan
	 * registers per-collection capabilities rather than reusing the generic ones.
	 *
	 * @param string[] $post_types Post types the query targets.
	 */
	private static function can_read_private( array $post_types ): bool {
		if ( empty( $post_types ) ) {
			return false;
		}

		foreach ( $post_types as $post_type ) {
			$object = get_post_type_object( $post_type );
			$cap    = ( is_object( $object ) && isset( $object->cap->read_private_posts ) )
				? (string) $object->cap->read_private_posts
				: 'read_private_posts';

			if ( ! current_user_can( $cap ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Translate a WP meta_query into a nested ES query over `metadata`.
	 *
	 * @return array|null
	 */
	private static function translate_meta_query( array $meta_query ): ?array {
		$relation = 'AND';
		if ( isset( $meta_query['relation'] ) ) {
			$relation = strtoupper( (string) $meta_query['relation'] );
			unset( $meta_query['relation'] );
		}
		if ( ! in_array( $relation, array( 'AND', 'OR' ), true ) ) {
			return null;
		}

		$clauses = array();
		foreach ( $meta_query as $clause ) {
			if ( ! is_array( $clause ) ) {
				continue;
			}
			// Nested clause groups: recurse once.
			if ( ! isset( $clause['key'] ) && ( isset( $clause['relation'] ) || isset( $clause[0] ) ) ) {
				$sub = self::translate_meta_query( $clause );
				if ( null === $sub ) {
					return null;
				}
				if ( ! empty( $sub ) ) {
					$clauses[] = $sub;
				}
				continue;
			}
			if ( ! isset( $clause['key'] ) ) {
				continue;
			}

			$built = self::build_meta_clause( $clause );
			if ( null === $built ) {
				return null;
			}
			$clauses[] = $built;
		}

		if ( empty( $clauses ) ) {
			return array();
		}

		return 'OR' === $relation
			? array( 'bool' => array( 'should' => $clauses, 'minimum_should_match' => 1 ) )
			: array( 'bool' => array( 'filter' => $clauses ) );
	}

	/**
	 * Build one nested `metadata` clause.
	 *
	 * @return array|null
	 */
	private static function build_meta_clause( array $clause ): ?array {
		$key = $clause['key'];
		// Tainacan addresses metadata by numeric metadatum ID; a non-numeric key is a
		// raw postmeta key we do not index per-field.
		if ( ! is_numeric( $key ) ) {
			return null;
		}

		$inner = array(
			array( 'term' => array( 'metadata.metadatum_id' => (int) $key ) ),
		);

		$compare = isset( $clause['compare'] ) ? strtoupper( trim( (string) $clause['compare'] ) ) : '=';
		$value   = $clause['value'] ?? null;
		$negate  = false;

		switch ( $compare ) {
			case 'EXISTS':
				// The metadatum term above is already the existence test.
				break;

			case 'NOT EXISTS':
				return array(
					'bool' => array(
						'must_not' => array(
							array(
								'nested' => array(
									'path'  => 'metadata',
									'query' => array( 'bool' => array( 'filter' => $inner ) ),
								),
							),
						),
					),
				);

			case '!=':
			case 'NOT IN':
				$negate = true;
				// Fall through: build the positive match, then negate it below.
			case '=':
			case 'IN':
				if ( null === $value ) {
					return null;
				}
				$values = array_values( array_map( 'strval', (array) $value ) );
				if ( empty( $values ) ) {
					return null;
				}
				$match = array(
					array( 'terms' => array( 'metadata.value_keyword' => $values ) ),
				);
				// Taxonomy/Relationship metadata are filtered by entity ID, which lives
				// in `value_ids` rather than in the human-readable keyword.
				$numeric = array_values( array_filter( $values, 'ctype_digit' ) );
				if ( ! empty( $numeric ) ) {
					$match[] = array( 'terms' => array( 'metadata.value_ids' => array_map( 'intval', $numeric ) ) );
				}
				$inner[] = array(
					'bool' => array(
						'should'               => $match,
						'minimum_should_match' => 1,
					),
				);
				break;

			case 'LIKE':
				if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
					return null;
				}
				$inner[] = array( 'match_phrase' => array( 'metadata.value_text' => (string) $value ) );
				break;

			case '>':
			case '>=':
			case '<':
			case '<=':
				if ( ! is_numeric( $value ) ) {
					return null;
				}
				$op      = array(
					'>'  => 'gt',
					'>=' => 'gte',
					'<'  => 'lt',
					'<=' => 'lte',
				)[ $compare ];
				$inner[] = array( 'range' => array( 'metadata.value_number' => array( $op => (float) $value ) ) );
				break;

			case 'BETWEEN':
				if ( ! is_array( $value ) || 2 !== count( $value ) || ! is_numeric( $value[0] ) || ! is_numeric( $value[1] ) ) {
					return null;
				}
				$inner[] = array(
					'range' => array(
						'metadata.value_number' => array(
							'gte' => (float) $value[0],
							'lte' => (float) $value[1],
						),
					),
				);
				break;

			default:
				// Unknown operator: refuse rather than guess.
				return null;
		}

		$nested = array(
			'nested' => array(
				'path'  => 'metadata',
				'query' => array( 'bool' => array( 'filter' => $inner ) ),
			),
		);

		return $negate
			? array( 'bool' => array( 'must_not' => array( $nested ) ) )
			: $nested;
	}

	/**
	 * Translate a WP tax_query into a nested ES query over `taxonomies`.
	 *
	 * @return array|null
	 */
	private static function translate_tax_query( array $tax_query ): ?array {
		$relation = 'AND';
		if ( isset( $tax_query['relation'] ) ) {
			$relation = strtoupper( (string) $tax_query['relation'] );
			unset( $tax_query['relation'] );
		}
		if ( ! in_array( $relation, array( 'AND', 'OR' ), true ) ) {
			return null;
		}

		$clauses = array();
		foreach ( $tax_query as $clause ) {
			if ( ! is_array( $clause ) ) {
				continue;
			}
			if ( ! isset( $clause['taxonomy'] ) && ( isset( $clause['relation'] ) || isset( $clause[0] ) ) ) {
				$sub = self::translate_tax_query( $clause );
				if ( null === $sub ) {
					return null;
				}
				if ( ! empty( $sub ) ) {
					$clauses[] = $sub;
				}
				continue;
			}
			if ( ! isset( $clause['taxonomy'] ) ) {
				continue;
			}

			$built = self::build_tax_clause( $clause );
			if ( null === $built ) {
				return null;
			}
			$clauses[] = $built;
		}

		if ( empty( $clauses ) ) {
			return array();
		}

		return 'OR' === $relation
			? array( 'bool' => array( 'should' => $clauses, 'minimum_should_match' => 1 ) )
			: array( 'bool' => array( 'filter' => $clauses ) );
	}

	/**
	 * Build one nested `taxonomies` clause.
	 *
	 * @return array|null
	 */
	private static function build_tax_clause( array $clause ): ?array {
		$taxonomy = (string) $clause['taxonomy'];
		if ( '' === $taxonomy ) {
			return null;
		}

		$operator = isset( $clause['operator'] ) ? strtoupper( trim( (string) $clause['operator'] ) ) : 'IN';
		$field    = isset( $clause['field'] ) ? strtolower( trim( (string) $clause['field'] ) ) : 'term_id';
		$inner    = array( array( 'term' => array( 'taxonomies.slug' => $taxonomy ) ) );

		if ( 'EXISTS' === $operator ) {
			return array(
				'nested' => array(
					'path'  => 'taxonomies',
					'query' => array( 'bool' => array( 'filter' => $inner ) ),
				),
			);
		}
		if ( 'NOT EXISTS' === $operator ) {
			return array(
				'bool' => array(
					'must_not' => array(
						array(
							'nested' => array(
								'path'  => 'taxonomies',
								'query' => array( 'bool' => array( 'filter' => $inner ) ),
							),
						),
					),
				),
			);
		}

		$terms = $clause['terms'] ?? null;
		if ( null === $terms ) {
			return null;
		}
		$terms = array_values( (array) $terms );
		if ( empty( $terms ) ) {
			return null;
		}

		if ( in_array( $field, array( 'term_id', 'id', 'term_taxonomy_id' ), true ) ) {
			// `term_taxonomy_id` is not the same column as `term_id`; only translate it
			// when they are known to coincide, which we cannot assume here.
			if ( 'term_taxonomy_id' === $field ) {
				return null;
			}
			$inner[] = array( 'terms' => array( 'taxonomies.term_ids' => array_map( 'intval', $terms ) ) );
		} elseif ( 'name' === $field ) {
			$inner[] = array( 'terms' => array( 'taxonomies.terms.raw' => array_map( 'strval', $terms ) ) );
		} else {
			// `slug` and anything else: term slugs are not indexed.
			return null;
		}

		if ( 'AND' === $operator ) {
			// Every term must be present. Each needs its own nested clause, because a
			// single nested match only proves one term matched.
			$all = array();
			foreach ( $terms as $term ) {
				$one = self::build_tax_clause(
					array(
						'taxonomy' => $taxonomy,
						'field'    => $field,
						'terms'    => array( $term ),
						'operator' => 'IN',
					)
				);
				if ( null === $one ) {
					return null;
				}
				$all[] = $one;
			}
			return array( 'bool' => array( 'filter' => $all ) );
		}

		$nested = array(
			'nested' => array(
				'path'  => 'taxonomies',
				'query' => array( 'bool' => array( 'filter' => $inner ) ),
			),
		);

		if ( 'NOT IN' === $operator ) {
			return array( 'bool' => array( 'must_not' => array( $nested ) ) );
		}
		if ( 'IN' !== $operator ) {
			return null;
		}

		return $nested;
	}

	/**
	 * Translate ordering. Returns an ES `sort` array, or null when the ordering
	 * depends on data the index cannot sort on.
	 *
	 * @return array|null
	 */
	public static function build_sort( \WP_Query $query ): ?array {
		$orderby = $query->get( 'orderby' );
		$order   = strtoupper( (string) $query->get( 'order' ) );
		$order   = in_array( $order, array( 'ASC', 'DESC' ), true ) ? strtolower( $order ) : 'desc';

		if ( empty( $orderby ) ) {
			$orderby = '' !== trim( (string) $query->get( 's' ) ) ? 'relevance' : 'date';
		}

		// WP accepts three shapes, and Tainacan uses the associative one
		// (`['date' => 'DESC', 'ID' => 'DESC']`) for its item listings. Normalise
		// all of them to an ordered list of [field, direction] pairs.
		$pairs = array();
		if ( is_array( $orderby ) ) {
			foreach ( $orderby as $key => $direction ) {
				$pairs[] = array( (string) $key, (string) $direction );
			}
		} else {
			// A space-separated string ("title ID") shares one direction.
			foreach ( preg_split( '/\s+/', trim( (string) $orderby ) ) as $key ) {
				if ( '' !== $key ) {
					$pairs[] = array( $key, $order );
				}
			}
		}

		if ( empty( $pairs ) ) {
			return null;
		}

		$sort = array();
		foreach ( $pairs as $pair ) {
			list( $key, $direction ) = $pair;

			$direction = strtoupper( trim( $direction ) );
			$direction = in_array( $direction, array( 'ASC', 'DESC' ), true ) ? strtolower( $direction ) : $order;

			$field = self::sort_field( strtolower( trim( $key ) ) );
			if ( null === $field ) {
				// One untranslatable key makes the whole ordering untrustworthy.
				return null;
			}

			$sort[] = '_score' === $field
				? array( '_score' => array( 'order' => 'desc' ) )
				: array( $field => array( 'order' => $direction ) );
		}

		return $sort;
	}

	/**
	 * Map a WP `orderby` key to the index field that reproduces its ordering.
	 *
	 * @return string|null Null when the index cannot order by it faithfully.
	 */
	private static function sort_field( string $key ): ?string {
		switch ( $key ) {
			case 'relevance':
				return '_score';
			case 'date':
			case 'post_date':
				return 'date_created';
			case 'modified':
			case 'post_modified':
				return 'date_modified';
			case 'id':
			case 'post_id':
				return 'item_id';
			case 'author':
				return 'author_id';
			case 'title':
				// `title.raw` sorts by raw UTF-8 byte order. MySQL orders that column
				// under utf8mb4_unicode_520_ci (case/accent-insensitive), which this
				// ES cluster has no ICU plugin to reproduce — confirmed to diverge on
				// real data. Until a collation-aware sort key is indexed, defer to SQL
				// rather than return a differently-ordered — and therefore
				// differently-paginated — result set.
				return null;
			default:
				// `rand`, `meta_value`, `meta_value_num`, `menu_order`, ...
				return null;
		}
	}
}
