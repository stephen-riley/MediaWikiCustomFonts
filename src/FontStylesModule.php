<?php

declare(strict_types=1);

namespace MediaWiki\Extension\CustomFonts;

use MediaWiki\MediaWikiServices;
use MediaWiki\ResourceLoader\Context as ResourceLoaderContext;
use MediaWiki\ResourceLoader\Module as ResourceLoaderModule;

/**
 * Dynamic ResourceLoader module for serving custom web fonts.
 */
class FontStylesModule extends ResourceLoaderModule {

	private const FORMAT_MAP = [
		'woff2' => "format('woff2')",
		'woff' => "format('woff')",
		'ttf' => "format('truetype')",
		'eot' => "format('embedded-opentype')",
		'otf' => "format('opentype')",
	];

	/**
	 * Get the type of module: style-only.
	 *
	 * @return string
	 */
	public function getType(): string {
		return self::LOAD_STYLES;
	}

	/**
	 * Get the dynamic CSS styles for all uploaded fonts.
	 *
	 * @param ResourceLoaderContext $context
	 * @return array
	 */
	public function getStyles( ResourceLoaderContext $context ): array {
		$services = MediaWikiServices::getInstance();
		$repo = $services->getRepoGroup()->getLocalRepo();
		$backend = $repo->getBackend();
		$fontsJsonPath = $repo->getZonePath( 'public' ) . '/fonts/fonts.json';

		$css = '';
		if ( $backend->fileExists( [ 'src' => $fontsJsonPath ] ) ) {
			$content = $backend->getFileContents( [ 'src' => $fontsJsonPath ] );
			if ( is_string( $content ) ) {
				$fonts = json_decode( $content, true );
				if ( is_array( $fonts ) ) {
					$baseUrl = $repo->getZoneUrl( 'public' );
					foreach ( $fonts as $font ) {
						if ( !isset( $font['name'], $font['slug'], $font['formats'] ) || !is_array( $font['formats'] ) ) {
							continue;
						}
						$sources = [];
						foreach ( $font['formats'] as $format => $filename ) {
							if ( isset( self::FORMAT_MAP[$format] ) && is_string( $filename ) ) {
								$url = $baseUrl . '/fonts/' . $font['slug'] . '/' . $filename;
								$urlEscaped = str_replace( "'", "\\'", $url );
								$sources[] = "url('" . $urlEscaped . "') " . self::FORMAT_MAP[$format];
							}
						}
						if ( $sources ) {
							$nameEscaped = str_replace( "'", "\\'", $font['name'] );
							$css .= "@font-face {\n";
							$css .= "\tfont-family: '" . $nameEscaped . "';\n";
							$css .= "\tsrc: " . implode( ",\n\t\t", $sources ) . ";\n";
							$css .= "}\n";
						}
					}
				}
			}
		}

		return [
			'all' => [ $css ]
		];
	}
}
