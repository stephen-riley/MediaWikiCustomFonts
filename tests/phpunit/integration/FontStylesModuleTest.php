<?php

declare(strict_types=1);

namespace MediaWiki\Extension\MediaWikiCustomFonts\Tests\Integration;

use MediaWiki\Extension\MediaWikiCustomFonts\FontStylesModule;
use MediaWiki\MediaWikiServices;
use MediaWiki\ResourceLoader\Context as ResourceLoaderContext;
use MediaWikiIntegrationTestCase;

/**
 * @group CustomFonts
 * @covers \MediaWiki\Extension\MediaWikiCustomFonts\FontStylesModule
 */
class FontStylesModuleTest extends MediaWikiIntegrationTestCase {

	/**
	 * Test module type is styles.
	 */
	public function testGetType(): void {
		$module = new FontStylesModule();
		$this->assertSame( 'styles', $module->getType() );
	}

	/**
	 * Test getStyles when fonts.json does not exist.
	 */
	public function testGetStylesEmpty(): void {
		$repo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
		$backend = $repo->getBackend();
		$fontsJsonPath = $repo->getZonePath( 'public' ) . '/fonts/fonts.json';

		// Clean up in case it exists from other tests
		if ( $backend->fileExists( [ 'src' => $fontsJsonPath ] ) ) {
			$backend->doOperation( [ 'op' => 'delete', 'src' => $fontsJsonPath ] );
		}

		$module = new FontStylesModule();
		$context = $this->createMock( ResourceLoaderContext::class );

		$styles = $module->getStyles( $context );
		$this->assertSame( [ 'all' => [ '' ] ], $styles );
	}

	/**
	 * Test getStyles parsing active fonts from fonts.json.
	 */
	public function testGetStylesWithFonts(): void {
		$fontData = [
			[
				'name' => 'Open Sans',
				'slug' => 'open-sans',
				'formats' => [
					'woff2' => 'open-sans-regular.woff2',
					'ttf' => 'open-sans-regular.ttf'
				]
			]
		];

		$repo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
		$backend = $repo->getBackend();
		$fontsJsonPath = $repo->getZonePath( 'public' ) . '/fonts/fonts.json';

		// Write to the temporary repository backend
		$backend->prepare( [ 'dir' => dirname( $fontsJsonPath ) ] );
		$backend->create( [
			'dst' => $fontsJsonPath,
			'content' => json_encode( $fontData ),
			'overwrite' => true
		] );

		$module = new FontStylesModule();
		$context = $this->createMock( ResourceLoaderContext::class );

		$styles = $module->getStyles( $context );

		$baseUrl = $repo->getZoneUrl( 'public' );
		$expectedCss = "@font-face {\n"
			. "\tfont-family: 'Open Sans';\n"
			. "\tsrc: url('" . $baseUrl . "/fonts/open-sans/open-sans-regular.woff2') format('woff2'),\n"
			. "\t\turl('" . $baseUrl . "/fonts/open-sans/open-sans-regular.ttf') format('truetype');\n"
			. "}\n";

		$this->assertSame( [ 'all' => [ $expectedCss ] ], $styles );

		// Clean up the created test file
		$backend->doOperation( [ 'op' => 'delete', 'src' => $fontsJsonPath ] );
	}
}
