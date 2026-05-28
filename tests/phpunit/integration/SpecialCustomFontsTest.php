<?php

declare(strict_types=1);

namespace MediaWiki\Extension\MediaWikiCustomFonts\Tests\Integration;

use FauxResponse;
use MediaWiki\Extension\MediaWikiCustomFonts\SpecialCustomFonts;
use MediaWiki\FileRepo\LocalRepo;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWiki\Session\Session;
use MediaWikiIntegrationTestCase;
use WebRequest;
use Wikimedia\FileBackend\FSFileBackend;
use Wikimedia\TestingAccessWrapper;

/**
 * @group CustomFonts
 * @covers \MediaWiki\Extension\MediaWikiCustomFonts\SpecialCustomFonts
 */
class SpecialCustomFontsTest extends MediaWikiIntegrationTestCase {

	/** @var string */
	private string $tempDir;

	/** @var FSFileBackend */
	private FSFileBackend $backend;

	/** @var LocalRepo|\PHPUnit\Framework\MockObject\MockObject */
	private $repoMock;

	/** @var RepoGroup|\PHPUnit\Framework\MockObject\MockObject */
	private $repoGroupMock;

	/** @var ResourceLoader|\PHPUnit\Framework\MockObject\MockObject */
	private $resourceLoaderMock;

	protected function setUp(): void {
		parent::setUp();

		$this->tempDir = sys_get_temp_dir() . '/mw-customfonts-special-test-' . uniqid();
		mkdir( $this->tempDir, 0777, true );

		$this->backend = new FSFileBackend( [
			'name' => 'test-fonts-backend-special',
			'wikiId' => 'testwiki',
			'containerPaths' => [ 'test-container' => $this->tempDir ]
		] );

		$this->repoMock = $this->createMock( LocalRepo::class );
		$this->repoMock->method( 'getBackend' )->willReturn( $this->backend );
		$this->repoMock->method( 'getZonePath' )->willReturn( 'mwstore://test-fonts-backend-special/test-container' );
		$this->repoMock->method( 'getZoneUrl' )->willReturn( '/images' );

		$this->repoGroupMock = $this->createMock( RepoGroup::class );
		$this->repoGroupMock->method( 'getLocalRepo' )->willReturn( $this->repoMock );

		$this->resourceLoaderMock = $this->createMock( ResourceLoader::class );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->tempDir ) ) {
			$this->deleteDirectory( $this->tempDir );
		}
		parent::tearDown();
	}

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
	 * Test the constructor constraints and page restriction.
	 */
	public function testConstructor(): void {
		$specialPage = new SpecialCustomFonts( $this->repoGroupMock, $this->resourceLoaderMock );
		$this->assertSame( 'CustomFonts', $specialPage->getName() );
		$this->assertSame( 'manage-custom-fonts', $specialPage->getRestriction() );
	}

	/**
	 * Test incomplete upload stashing and warning screen trigger.
	 */
	public function testHandleUploadIncomplete(): void {
		$specialPage = new SpecialCustomFonts( $this->repoGroupMock, $this->resourceLoaderMock );
		$wrapper = TestingAccessWrapper::newFromObject( $specialPage );

		// Create dummy source files
		$srcWoff2 = $this->tempDir . '/dummy.woff2';
		$srcTtf = $this->tempDir . '/dummy.ttf';
		file_put_contents( $srcWoff2, 'woff2-content' );
		file_put_contents( $srcTtf, 'ttf-content' );

		// Mock WebRequest
		$requestMock = $this->createMock( WebRequest::class );
		$requestMock->method( 'getVal' )->willReturnMap( [
			[ 'font-name', 'Test Font' ]
		] );

		$requestMock->method( 'getFileTempname' )->willReturnMap( [
			[ 'font-file-woff2', $srcWoff2 ],
			[ 'font-file-ttf', $srcTtf ],
			[ 'font-file-woff', '' ],
			[ 'font-file-eot', '' ],
			[ 'font-file-otf', '' ]
		] );

		$requestMock->method( 'getFileName' )->willReturnMap( [
			[ 'font-file-woff2', 'dummy.woff2' ],
			[ 'font-file-ttf', 'dummy.ttf' ],
			[ 'font-file-woff', '' ],
			[ 'font-file-eot', '' ],
			[ 'font-file-otf', '' ]
		] );

		// Mock Session
		$sessionData = [];
		$sessionMock = $this->createMock( Session::class );
		$sessionMock->method( 'has' )->willReturnCallback( function( $key ) use ( &$sessionData ) {
			return isset( $sessionData[$key] );
		} );
		$sessionMock->method( 'set' )->willReturnCallback( function( $key, $value ) use ( &$sessionData ) {
			$sessionData[$key] = $value;
		} );
		$sessionMock->method( 'get' )->willReturnCallback( function( $key ) use ( &$sessionData ) {
			return $sessionData[$key] ?? null;
		} );
		$sessionMock->method( 'remove' )->willReturnCallback( function( $key ) use ( &$sessionData ) {
			unset( $sessionData[$key] );
		} );

		$requestMock->method( 'getSession' )->willReturn( $sessionMock );

		// Call the private handleUpload method
		$wrapper->handleUpload( $requestMock );

		// Assertions
		$this->assertTrue( $wrapper->showConfirmWarning );
		$this->assertSame( [ 'woff', 'eot', 'otf' ], $wrapper->missingFormats );
		$this->assertSame( 'Test Font', $wrapper->tempUploadData['name'] );
		$this->assertSame( 'test-font', $wrapper->tempUploadData['slug'] );

		// Verify files exist in backend's temp path
		$tempDir = $wrapper->tempUploadData['tempDir'];
		$this->assertTrue( $this->backend->fileExists( [ 'src' => $tempDir . '/test-font.woff2' ] ) );
		$this->assertTrue( $this->backend->fileExists( [ 'src' => $tempDir . '/test-font.ttf' ] ) );

		// Verify session has stash info
		$this->assertNotNull( $sessionData['CustomFontsTempUpload'] );
		$this->assertSame( $tempDir, $sessionData['CustomFontsTempUpload']['tempDir'] );
	}

	/**
	 * Test confirming an incomplete upload.
	 */
	public function testHandleConfirmUpload(): void {
		$specialPage = new SpecialCustomFonts( $this->repoGroupMock, $this->resourceLoaderMock );
		$wrapper = TestingAccessWrapper::newFromObject( $specialPage );

		// Setup stashed temp files
		$tempDir = $this->repoMock->getZonePath( 'public' ) . '/fonts/tmp/stash-' . uniqid();
		$this->backend->prepare( [ 'dir' => $tempDir ] );
		$this->backend->create( [ 'dst' => $tempDir . '/test-font.woff2', 'content' => 'woff2-content' ] );

		// Mock Session populated with pending upload
		$sessionData = [
			'CustomFontsTempUpload' => [
				'name' => 'Test Font',
				'slug' => 'test-font',
				'tempDir' => $tempDir,
				'files' => [
					'woff2' => 'test-font.woff2'
				]
			]
		];

		$sessionMock = $this->createMock( Session::class );
		$sessionMock->method( 'get' )->willReturnCallback( function( $key ) use ( &$sessionData ) {
			return $sessionData[$key] ?? null;
		} );
		$sessionMock->method( 'remove' )->willReturnCallback( function( $key ) use ( &$sessionData ) {
			unset( $sessionData[$key] );
		} );

		$requestMock = $this->createMock( WebRequest::class );
		$requestMock->method( 'getSession' )->willReturn( $sessionMock );

		// Call the private handleConfirmUpload method
		$wrapper->handleConfirmUpload( $requestMock );

		// Assertions
		$this->assertFalse( $wrapper->isError );
		$this->assertStringContainsString( 'registered successfully', $wrapper->statusMessage );

		// Verify files moved to destination
		$destPath = $this->repoMock->getZonePath( 'public' ) . '/fonts/test-font/test-font.woff2';
		$this->assertTrue( $this->backend->fileExists( [ 'src' => $destPath ] ) );

		// Verify fonts.json created and updated
		$fontsJsonPath = $this->repoMock->getZonePath( 'public' ) . '/fonts/fonts.json';
		$this->assertTrue( $this->backend->fileExists( [ 'src' => $fontsJsonPath ] ) );
		$json = json_decode( $this->backend->getFileContents( [ 'src' => $fontsJsonPath ] ), true );
		$this->assertCount( 1, $json );
		$this->assertSame( 'Test Font', $json[0]['name'] );
		$this->assertSame( 'test-font', $json[0]['slug'] );
		$this->assertSame( [ 'woff2' => 'test-font.woff2' ], $json[0]['formats'] );

		// Verify session cleared
		$this->assertArrayNotHasKey( 'CustomFontsTempUpload', $sessionData );
	}

	/**
	 * Test cancelling an incomplete upload.
	 */
	public function testHandleCancelUpload(): void {
		$specialPage = new SpecialCustomFonts( $this->repoGroupMock, $this->resourceLoaderMock );
		$wrapper = TestingAccessWrapper::newFromObject( $specialPage );

		// Setup stashed temp files
		$tempDir = $this->repoMock->getZonePath( 'public' ) . '/fonts/tmp/stash-' . uniqid();
		$this->backend->prepare( [ 'dir' => $tempDir ] );
		$this->backend->create( [ 'dst' => $tempDir . '/test-font.woff2', 'content' => 'woff2-content' ] );

		// Mock Session populated with pending upload
		$sessionData = [
			'CustomFontsTempUpload' => [
				'name' => 'Test Font',
				'slug' => 'test-font',
				'tempDir' => $tempDir,
				'files' => [
					'woff2' => 'test-font.woff2'
				]
			]
		];

		$sessionMock = $this->createMock( Session::class );
		$sessionMock->method( 'get' )->willReturnCallback( function( $key ) use ( &$sessionData ) {
			return $sessionData[$key] ?? null;
		} );
		$sessionMock->method( 'remove' )->willReturnCallback( function( $key ) use ( &$sessionData ) {
			unset( $sessionData[$key] );
		} );

		$requestMock = $this->createMock( WebRequest::class );
		$requestMock->method( 'getSession' )->willReturn( $sessionMock );

		// Call the private handleCancelUpload method
		$wrapper->handleCancelUpload( $requestMock );

		// Assertions
		$this->assertFalse( $wrapper->isError );
		$this->assertSame( 'Upload cancelled.', $wrapper->statusMessage );

		// Verify stashed files deleted
		$this->assertFalse( $this->backend->fileExists( [ 'src' => $tempDir . '/test-font.woff2' ] ) );

		// Verify session cleared
		$this->assertArrayNotHasKey( 'CustomFontsTempUpload', $sessionData );
	}
}
