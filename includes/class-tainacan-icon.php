<?php
/**
 * Resolução segura de ícones SVG do Tainacan.
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * O trait \Tainacan\Traits\SVG_Icon chama file_get_contents() direto, sem
 * checar se o arquivo existe. Um ícone ausente vira um warning impresso no
 * meio do HTML e, como isso acontece enquanto o menu do admin é montado, o
 * WordPress quebra em seguida com "headers already sent" — o admin inteiro
 * fica sem cabeçalho.
 *
 * O conjunto de ícones muda entre versões do Tainacan (`chart` não existe nem
 * na 1.0.3 nem na 1.1.0), então nenhum nome pode ser assumido: pede-se uma
 * lista de candidatos e usa-se o primeiro que realmente existir.
 */
final class Tainacan_Icon {

	/**
	 * Retorna o SVG do primeiro ícone que existir na instalação.
	 *
	 * @param object   $page       Página que usa o trait SVG_Icon.
	 * @param string[] $candidates Nomes de ícone em ordem de preferência.
	 * @return string SVG inline, ou '' se nenhum candidato existir.
	 */
	public static function svg( $page, array $candidates ): string {
		if ( ! method_exists( $page, 'get_svg_icon' ) ) {
			return '';
		}

		$folder = self::icons_folder();

		foreach ( $candidates as $slug ) {
			// Sem a pasta resolvida não dá para checar antes; o @ abaixo ainda
			// impede que um warning do Tainacan vá parar no meio do HTML.
			if ( '' !== $folder && ! is_readable( $folder . $slug . '.svg' ) ) {
				continue;
			}

			$svg = @$page->get_svg_icon( $slug ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( is_string( $svg ) && '' !== $svg ) {
				return $svg;
			}
		}

		return '';
	}

	/**
	 * Reproduz a resolução de caminho do próprio trait do Tainacan, inclusive o
	 * filtro que permite reapontar a pasta.
	 *
	 * @return string Caminho com barra final, ou '' se não for determinável.
	 */
	private static function icons_folder(): string {
		if ( ! trait_exists( '\\Tainacan\\Traits\\SVG_Icon' ) ) {
			return '';
		}

		try {
			$reflection = new \ReflectionClass( '\\Tainacan\\Traits\\SVG_Icon' );
			$file       = $reflection->getFileName();
		} catch ( \Throwable $e ) {
			return '';
		}

		if ( ! $file ) {
			return '';
		}

		// O trait fica em {tainacan}/classes/traits/, e resolve a pasta como
		// plugin_dir_path( dirname( __FILE__, 2 ) ) . 'assets/icons/'.
		$folder = plugin_dir_path( dirname( $file, 2 ) ) . 'assets/icons/';

		return (string) apply_filters( 'tainacan-svg-icons-folder-path', $folder );
	}
}
