<?php

declare(strict_types=1);

namespace MediaWiki\Extension\MediaWikiCustomFonts\Tests\Integration;

use MediaWiki\Extension\MediaWikiCustomFonts\FontStylesModule;
use Wikimedia\FileBackend\FileBackend;
use MediaWiki\FileRepo\LocalRepo;
use MediaWiki\FileRepo\RepoGroup;
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
		$backendMock = $this->createMock( FileBackend::class );
		$backendMock->method( 'fileExists' )
			->willReturn( false );

		$repoMock = $this->createMock( LocalRepo::class );
		$repoMock->method( 'getBackend' )
			->willReturn( $backendMock );
		$repoMock->method( 'getZonePath' )
			->with( 'public' )
			->willReturn( 'mwstore://local-backend/local-public' );

		$repoGroupMock = $this->createMock( RepoGroup::class );
		$repoGroupMock->method( 'getLocalRepo' )
			->willReturn( $repoMock );

		$this->setService( 'RepoGroup', $repoGroupMock );

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

		$backendMock = $this->createMock( FileBackend::class );
		$backendMock->method( 'fileExists' )
			->willReturn( true );
		$backendMock->method( 'getFileContents' )
			->willReturn( json_encode( $fontData ) );

		$repoMock = $this->createMock( LocalRepo::class );
		$repoMock->method( 'getBackend' )
			->willReturn( $backendMock );
		$repoMock->method( 'getZonePath' )
			->with( 'public' )
			->willReturn( 'mwstore://local-backend/local-public' );
		$repoMock->method( 'getZoneUrl' )
			->with( 'public' )
			->willReturn( 'https://example.com/images' );

		$repoGroupMock = $this->createMock( RepoGroup::class );
		$repoGroupMock->method( 'getLocalRepo' )
			->willReturn( $repoMock );

		$this->setService( 'RepoGroup', $repoGroupMock );

		$module = new FontStylesModule();
		$context = $this->createMock( ResourceLoaderContext::class );

		$styles = $module->getStyles( $context );

		$expectedCss = "@font-face {\n"
			. "\tfont-family: 'Open Sans';\n"
			. "\tsrc: url('https://example.com/images/fonts/open-sans/open-sans-regular.woff2') format('woff2'),\n"
			. "\t\turl('https://example.com/images/fonts/open-sans/open-sans-regular.ttf') format('truetype');\n"
			. "}\n";

		$this->assertSame( [ 'all' => [ $expectedCss ] ], $styles );
	}
}
