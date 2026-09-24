<?php
// Uso: wp eval-file vocab-dump.php > vocab.json  (roda dentro do WordPress; imprime JSON após o marcador JSONSTART).
defined( 'ABSPATH' ) || exit;
// Extrai o vocabulário do acervo do brasiliana3 para gerar as listas do "Vocabulário da busca". Só lê.
ini_set( 'memory_limit', '2048M' );
set_time_limit( 0 );
global $wpdb;

$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type LIKE 'tnc\\_col\\_%\\_item' AND post_status = 'publish' ORDER BY ID" );
$n   = count( $ids );
$fold = function ( $w ) { return remove_accents( mb_strtolower( $w, 'UTF-8' ) ); };

$tf = $df = $cap = $forms = array();
$acr = array();
$connectors = array( 'de', 'da', 'do', 'dos', 'das', 'e', 'd', 'del', 'di', 'du', 'des', 'of', 'the', 'and', 'für', 'a', 'o', 'em', 'para' );

foreach ( array_chunk( $ids, 500 ) as $chunk ) {
	$in    = implode( ',', array_map( 'intval', $chunk ) );
	$texts = array();
	foreach ( $wpdb->get_results( "SELECT ID, post_title, post_content, post_excerpt FROM {$wpdb->posts} WHERE ID IN ($in)", ARRAY_A ) as $r ) {
		$texts[ $r['ID'] ] = $r['post_title'] . ' . ' . $r['post_content'] . ' . ' . $r['post_excerpt'];
	}
	foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($in) AND meta_key REGEXP '^[0-9]+$' AND meta_value <> ''", ARRAY_A ) as $r ) {
		$v = $r['meta_value'];
		if ( is_numeric( $v ) || 0 === strpos( $v, 'a:' ) || 0 === strpos( $v, 's:' ) ) { continue; }
		$texts[ $r['post_id'] ] = ( $texts[ $r['post_id'] ] ?? '' ) . ' . ' . $v;
	}
	foreach ( $wpdb->get_results( "SELECT tr.object_id, t.name FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tr.object_id IN ($in) AND tt.taxonomy LIKE 'tnc\\_tax\\_%'", ARRAY_A ) as $r ) {
		$texts[ $r['object_id'] ] = ( $texts[ $r['object_id'] ] ?? '' ) . ' . ' . $r['name'];
	}
	foreach ( $texts as $text ) {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );

		// Siglas que o próprio acervo define: "Nome por Extenso (SIGLA)".
		if ( preg_match_all( '/((?:[\p{L}\']+[ ]+){1,12})\(\s*(\p{Lu}{2,8})\s*\)/u', $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) {
				$sig   = $hit[2];
				$words = preg_split( '/\s+/u', trim( $hit[1] ) );
				$need  = mb_strtolower( $fold( $sig ) );
				$got   = '';
				$start = null;
				for ( $i = count( $words ) - 1; $i >= 0; $i-- ) {
					$w = $words[ $i ];
					if ( in_array( mb_strtolower( $w ), $connectors, true ) ) { continue; }
					if ( ! preg_match( '/^\p{Lu}/u', $w ) ) { break; }
					$got = $fold( mb_substr( $w, 0, 1 ) ) . $got;
					if ( $got === $need ) { $start = $i; break; }
					if ( strlen( $got ) >= strlen( $need ) ) { break; }
				}
				if ( null !== $start ) {
					$exp = implode( ' ', array_slice( $words, $start ) );
					$key = $sig . '|' . $exp;
					$acr[ $key ] = ( $acr[ $key ] ?? 0 ) + 1;
				}
			}
		}

		$words = preg_split( '/[^\p{L}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$seen  = array();
		foreach ( $words as $w ) {
			if ( mb_strlen( $w ) < 2 ) { continue; }
			$k = $fold( $w );
			$tf[ $k ] = ( $tf[ $k ] ?? 0 ) + 1;
			if ( preg_match( '/^\p{Lu}/u', $w ) ) { $cap[ $k ] = ( $cap[ $k ] ?? 0 ) + 1; }
			// Guarda a grafia (com acento) mais comum, para escrever as regras bonitas.
			$lw = mb_strtolower( $w, 'UTF-8' );
			if ( $lw !== $k ) { $forms[ $k ][ $lw ] = ( $forms[ $k ][ $lw ] ?? 0 ) + 1; }
			if ( ! isset( $seen[ $k ] ) ) { $seen[ $k ] = true; $df[ $k ] = ( $df[ $k ] ?? 0 ) + 1; }
		}
	}
	$wpdb->flush();
}

$vocab = array();
foreach ( $tf as $k => $f ) {
	$best = $k;
	if ( isset( $forms[ $k ] ) ) {
		arsort( $forms[ $k ] );
		$top = array_key_first( $forms[ $k ] );
		if ( $forms[ $k ][ $top ] * 2 >= $f ) { $best = $top; }
	}
	$vocab[] = array( $k, $f, $df[ $k ], round( ( $cap[ $k ] ?? 0 ) / $f, 2 ), $best );
}

$tax = array();
foreach ( $wpdb->get_results( "SELECT DISTINCT taxonomy FROM {$wpdb->term_taxonomy} WHERE taxonomy LIKE 'tnc\\_tax\\_%'", ARRAY_A ) as $t ) {
	$title = get_the_title( (int) substr( $t['taxonomy'], 8 ) );
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT t.name, tt.count FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s AND tt.count > 0", $t['taxonomy'] ), ARRAY_N );
	if ( $rows ) { $tax[ $title ?: $t['taxonomy'] ] = $rows; }
}

arsort( $acr );
echo 'JSONSTART', wp_json_encode( array( 'items' => $n, 'vocab' => $vocab, 'acronyms' => $acr, 'tax' => $tax ), JSON_UNESCAPED_UNICODE );
