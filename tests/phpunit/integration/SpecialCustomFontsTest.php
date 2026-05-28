<?php

declare(strict_types=1);

namespace MediaWiki\Extension\MediaWikiCustomFonts\Tests\Integration;

use MediaWiki\Extension\MediaWikiCustomFonts\SpecialCustomFonts;
use MediaWiki\FileRepo\LocalRepo;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\Request\FauxRequest;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWikiIntegrationTestCase;
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

		// Instantiate FauxRequest
		$request = new FauxRequest( [
			'font-name' => 'Test Font',
			'action' => 'upload'
		], true );

		$request->setUpload( 'font-file-woff2', [
			'name' => 'dummy.woff2',
			'type' => 'font/woff2',
			'size' => 13,
			'tmp_name' => $srcWoff2,
			'error' => UPLOAD_ERR_OK
		] );
		$request->setUpload( 'font-file-ttf', [
			'name' => 'dummy.ttf',
			'type' => 'font/ttf',
			'size' => 11,
			'tmp_name' => $srcTtf,
			'error' => UPLOAD_ERR_OK
		] );

		// Call the private handleUpload method
		$wrapper->handleUpload( $request );

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
		$session = $request->getSession();
		$stashData = $session->get( 'CustomFontsTempUpload' );
		$this->assertNotNull( $stashData );
		$this->assertSame( $tempDir, $stashData['tempDir'] );
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

		// Instantiate FauxRequest
		$request = new FauxRequest( [], true );

		// Populate Session with pending upload
		$session = $request->getSession();
		$session->set( 'CustomFontsTempUpload', [
			'name' => 'Test Font',
			'slug' => 'test-font',
			'tempDir' => $tempDir,
			'files' => [
				'woff2' => 'test-font.woff2'
			]
		] );

		// Call the private handleConfirmUpload method
		$wrapper->handleConfirmUpload( $request );

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
		$this->assertNull( $session->get( 'CustomFontsTempUpload' ) );
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

		// Instantiate FauxRequest
		$request = new FauxRequest( [], true );

		// Populate Session with pending upload
		$session = $request->getSession();
		$session->set( 'CustomFontsTempUpload', [
			'name' => 'Test Font',
			'slug' => 'test-font',
			'tempDir' => $tempDir,
			'files' => [
				'woff2' => 'test-font.woff2'
			]
		] );

		// Call the private handleCancelUpload method
		$wrapper->handleCancelUpload( $request );

		// Assertions
		$this->assertFalse( $wrapper->isError );
		$this->assertSame( 'Upload cancelled.', $wrapper->statusMessage );

		// Verify stashed files deleted
		$this->assertFalse( $this->backend->fileExists( [ 'src' => $tempDir . '/test-font.woff2' ] ) );

		// Verify session cleared
		$this->assertNull( $session->get( 'CustomFontsTempUpload' ) );
	}

	/**
	 * Test that post action 'delete' triggers confirmation warning.
	 */
	public function testDeleteActionTriggersConfirm(): void {
		$specialPage = new SpecialCustomFonts( $this->repoGroupMock, $this->resourceLoaderMock );

		// Initialize session/request for CSRF
		$request = new FauxRequest( [], true );
		$csrfTokenSet = new \MediaWiki\Session\CsrfTokenSet( $request );
		$token = $csrfTokenSet->getToken();

		$session = $request->getSession();
		$request = new FauxRequest( [
			'action' => 'delete',
			'slug' => 'test-font',
			'token' => (string)$token
		], true, $session );

		$specialPage->setRequest( $request );

		$out = $this->createMock( \OutputPage::class );
		$specialPage->getContext()->setOutput( $out );

		$specialPage->execute( null );

		$wrapper = TestingAccessWrapper::newFromObject( $specialPage );
		$this->assertTrue( $wrapper->showDeleteConfirm );
		$this->assertSame( 'test-font', $wrapper->deleteSlug );
	}

	/**
	 * Test confirming font deletion.
	 */
	public function testDeleteConfirmed(): void {
		$specialPage = new SpecialCustomFonts( $this->repoGroupMock, $this->resourceLoaderMock );
		$wrapper = TestingAccessWrapper::newFromObject( $specialPage );

		// Setup registered font family and files
		$fontDir = $this->repoMock->getZonePath( 'public' ) . '/fonts/test-font';
		$this->backend->prepare( [ 'dir' => $fontDir ] );
		$this->backend->create( [ 'dst' => $fontDir . '/test-font.woff2', 'content' => 'woff2-content' ] );

		$fontsJsonPath = $this->repoMock->getZonePath( 'public' ) . '/fonts/fonts.json';
		$fontData = [
			[
				'name' => 'Test Font',
				'slug' => 'test-font',
				'formats' => [
					'woff2' => 'test-font.woff2'
				]
			]
		];
		$this->backend->create( [ 'dst' => $fontsJsonPath, 'content' => json_encode( $fontData ) ] );

		// Call the refactored handleDelete method
		$wrapper->handleDelete( 'test-font' );

		// Assertions
		$this->assertFalse( $wrapper->isError );
		$this->assertStringContainsString( 'has been deleted', $wrapper->statusMessage );

		// Verify files deleted
		$this->assertFalse( $this->backend->fileExists( [ 'src' => $fontDir . '/test-font.woff2' ] ) );

		// Verify removed from fonts.json
		$json = json_decode( $this->backend->getFileContents( [ 'src' => $fontsJsonPath ] ), true );
		$this->assertEmpty( $json );
	}
}

