<?php

declare(strict_types=1);

namespace MediaWiki\Extension\MediaWikiCustomFonts\Tests\Integration;

use MediaWiki\Extension\MediaWikiCustomFonts\FontStylesModule;
use MediaWiki\FileRepo\LocalRepo;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\ResourceLoader\Context as ResourceLoaderContext;
use MediaWikiIntegrationTestCase;
use Wikimedia\FileBackend\FSFileBackend;

/**
 * @group CustomFonts
 * @covers \MediaWiki\Extension\MediaWikiCustomFonts\FontStylesModule
 */
class FontStylesModuleTest extends MediaWikiIntegrationTestCase {

	/** @var string */
	private string $tempDir;

	/** @var FSFileBackend */
	private FSFileBackend $backend;

	/** @var LocalRepo|\PHPUnit\Framework\MockObject\MockObject */
	private $repoMock;

	protected function setUp(): void {
		parent::setUp();

		$this->tempDir = sys_get_temp_dir() . '/mw-customfonts-test-' . uniqid();
		mkdir( $this->tempDir, 0777, true );

		$this->backend = new FSFileBackend( [
			'name' => 'test-fonts-backend',
			'wikiId' => 'testwiki',
			'containerPaths' => [ 'test-container' => $this->tempDir ]
		] );

		$this->repoMock = $this->createMock( LocalRepo::class );
		$this->repoMock->method( 'getBackend' )->willReturn( $this->backend );
		$this->repoMock->method( 'getZonePath' )->willReturn( 'mwstore://test-fonts-backend/test-container' );
		$this->repoMock->method( 'getZoneUrl' )->willReturn( '/images' );

		$repoGroupMock = $this->createMock( RepoGroup::class );
		$repoGroupMock->method( 'getLocalRepo' )->willReturn( $this->repoMock );

		$this->setService( 'RepoGroup', $repoGroupMock );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->tempDir ) ) {
			$this->deleteDirectory( $this->tempDir );
		}
		parent::tearDown();
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir
	 */
	private function deleteDirectory( string $dir ): void {
		if ( !is_dir( $dir ) ) {
			return;
		}
		$files = array_diff( scandir( $dir ), [ '.', '..' ] );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			if ( is_dir( $path ) ) {
				$this->deleteDirectory( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

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
		$repo = $this->repoMock;
		$backend = $this->backend;
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

		$repo = $this->repoMock;
		$backend = $this->backend;
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

