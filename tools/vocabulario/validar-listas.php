<?php
namespace {
	// Só na linha de comando: php validar-listas.php <pasta com os 4 .txt>
	PHP_SAPI === 'cli' || exit;
	define( 'ABSPATH', __DIR__ . '/' );
	function __( $s ) { return $s; }
	function _n( $a, $b, $n ) { return 1 === $n ? $a : $b; }
	function number_format_i18n( $n, $d = 0 ) { return number_format( $n, $d, ',', '.' ); }
	function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
	function wp_json_encode( $v ) { return json_encode( $v ); }
	function remove_accents( $s ) { return iconv( 'UTF-8', 'ASCII//TRANSLIT', $s ); }
}
namespace TainacanIndexManager {
	class Settings {}
	class Logger {}
	class Elasticsearch_Client {}
	require dirname( __DIR__, 2 ) . '/includes/class-search-vocabulary.php';
	$dir   = $argv[1];
	$map   = array( 'synonyms' => '1-sinonimos.txt', 'variants' => '2-grafias-e-variantes.txt', 'corrections' => '3-correcoes-e-paronimos.txt', 'stopwords' => '4-palavras-ignoradas.txt' );
	$lists = array();
	foreach ( $map as $k => $f ) { $lists[ $k ] = file_get_contents( $dir . '/' . $f ); }
	$v   = ( new \ReflectionClass( Search_Vocabulary::class ) )->newInstanceWithoutConstructor();
	$res = $v->parse( $lists );
	echo json_encode( $res['report'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ), "\n";
	foreach ( $map as $k => $f ) { printf( "%-38s %6.1f KB\n", $f, filesize( $dir . '/' . $f ) / 1024 ); }
}
