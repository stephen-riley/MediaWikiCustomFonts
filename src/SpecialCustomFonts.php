<?php

declare(strict_types=1);

namespace MediaWiki\Extension\MediaWikiCustomFonts;

use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWiki\Session\CsrfTokenSet;
use MediaWiki\SpecialPage\SpecialPage;
use OOUI;
use WebRequest;

/**
 * Special page to upload and manage custom web fonts.
 */
class SpecialCustomFonts extends SpecialPage {

	private RepoGroup $repoGroup;
	private ResourceLoader $resourceLoader;

	private string $statusMessage = '';
	private bool $isError = false;

	private array $tempUploadData = [];
	private array $missingFormats = [];
	private bool $showConfirmWarning = false;

	private string $deleteSlug = '';
	private bool $showDeleteConfirm = false;

	private string $editSlug = '';
	private bool $showEditForm = false;

	/**
	 * @param RepoGroup $repoGroup
	 * @param ResourceLoader $resourceLoader
	 */
	public function __construct( RepoGroup $repoGroup, ResourceLoader $resourceLoader ) {
		parent::__construct( 'CustomFonts', 'manage-custom-fonts' );
		$this->repoGroup = $repoGroup;
		$this->resourceLoader = $resourceLoader;
	}

	/**
	 * Execute the special page.
	 *
	 * @param string|null $subPage
	 * @return void
	 */
	public function execute( $subPage ) {
		// Enforce user permission
		$this->checkPermissions();

		$out = $this->getOutput();
		$this->setHeaders();
		$out->enableOOUI();

		$request = $this->getRequest();

		// Handle actions from POST requests
		if ( $request->wasPosted() ) {
			$csrfTokenSet = new CsrfTokenSet( $request );
			$token = $request->getVal( 'token' );

			if ( $token === null || !$csrfTokenSet->matchToken( $token ) ) {
				$this->statusMessage = 'Invalid CSRF token. Please try again.';
				$this->isError = true;
			} else {
				$action = $request->getVal( 'action' );
				if ( $action === 'upload' ) {
					$this->handleUpload( $request );
				} elseif ( $action === 'delete' ) {
					$slug = (string)$request->getVal( 'slug' );
					if ( $slug !== '' ) {
						$this->deleteSlug = $slug;
						$this->showDeleteConfirm = true;
					} else {
						$this->statusMessage = 'Missing font slug for deletion.';
						$this->isError = true;
					}
				} elseif ( $action === 'confirm-delete' ) {
					$confirmBtn = $request->getVal( 'confirm-btn' );
					$slug = (string)$request->getVal( 'slug' );
					if ( $confirmBtn === 'cancel' ) {
						$this->statusMessage = 'Deletion cancelled.';
						$this->isError = false;
					} else {
						$this->handleDelete( $slug );
					}
				} elseif ( $action === 'confirm-upload' ) {
					$confirmBtn = $request->getVal( 'confirm-btn' );
					if ( $confirmBtn === 'cancel' ) {
						$this->handleCancelUpload( $request );
					} else {
						$this->handleConfirmUpload( $request );
					}
				} elseif ( $action === 'save-edit' ) {
					$this->handleEdit( $request );
				}
			}
		} else {
			$action = $request->getVal( 'action' );
			if ( $action === 'edit' ) {
				$slug = (string)$request->getVal( 'slug' );
				if ( $slug !== '' ) {
					$this->showEditForm = true;
					$this->editSlug = $slug;
				}
			}
		}

		$this->renderPage();
	}

	/**
	 * Process custom font upload.
	 *
	 * @param WebRequest $request
	 * @return void
	 */
	private function handleUpload( WebRequest $request ): void {
		$familyName = trim( (string)$request->getVal( 'font-name' ) );
		if ( $familyName === '' ) {
			$this->statusMessage = 'Font family name is required.';
			$this->isError = true;
			return;
		}

		// Sanitize font family name to generate a safe directory and file slug
		$slug = preg_replace( '/[^a-z0-9\-]/', '', str_replace( ' ', '-', strtolower( $familyName ) ) );
		$slug = preg_replace( '/-+/', '-', (string)$slug );
		$slug = trim( (string)$slug, '-' );

		if ( $slug === '' ) {
			$this->statusMessage = 'Invalid font family name (must contain alphanumeric characters or hyphens).';
			$this->isError = true;
			return;
		}

		$repo = $this->repoGroup->getLocalRepo();
		$backend = $repo->getBackend();
		$fontsJsonPath = $repo->getZonePath( 'public' ) . '/fonts/fonts.json';

		// Retrieve existing configurations
		$fonts = [];
		if ( $backend->fileExists( [ 'src' => $fontsJsonPath ] ) ) {
			$content = $backend->getFileContents( [ 'src' => $fontsJsonPath ] );
			if ( is_string( $content ) ) {
				$fonts = json_decode( $content, true ) ?: [];
			}
		}

		// Ensure no duplicate slugs exist
		foreach ( $fonts as $font ) {
			if ( isset( $font['slug'] ) && $font['slug'] === $slug ) {
				$this->statusMessage = "A font family with the slug '{$slug}' already exists.";
				$this->isError = true;
				return;
			}
		}

		// Track font formats
		$formats = [ 'woff2', 'woff', 'ttf', 'eot', 'otf' ];
		$uploadedFiles = [];
		$destFileMap = [];

		foreach ( $formats as $format ) {
			$fieldName = 'font-file-' . $format;
			$tmpName = $request->getFileTempname( $fieldName );
			$origName = $request->getFileName( $fieldName );

			if ( !$tmpName || $origName === null || $origName === '' ) {
				continue;
			}

			// Validate file extension
			$ext = pathinfo( $origName, PATHINFO_EXTENSION );
			if ( strtolower( $ext ) !== $format ) {
				$this->statusMessage = "Invalid file extension for {$format} format (expected .{$format}).";
				$this->isError = true;
				return;
			}

			$uploadedFiles[$format] = [
				'tmp' => $tmpName,
				'name' => $origName
			];
		}

		if ( !$uploadedFiles ) {
			$this->statusMessage = 'At least one font file format must be uploaded.';
			$this->isError = true;
			return;
		}

		// Clean up any old pending temp uploads first
		$session = $request->getSession();
		if ( $session->exists( 'CustomFontsTempUpload' ) ) {
			$this->cleanTempUpload( $session->get( 'CustomFontsTempUpload' ) );
			$session->remove( 'CustomFontsTempUpload' );
		}

		$missingFormats = array_diff( $formats, array_keys( $uploadedFiles ) );

		// If incomplete package (fewer than all 5 formats), stash and ask for confirmation
		if ( $missingFormats ) {
			$tempDirName = 'upload-' . uniqid() . '-' . mt_rand( 1000, 9999 );
			$tempDir = $repo->getZonePath( 'public' ) . '/fonts/tmp/' . $tempDirName;

			$status = $backend->prepare( [ 'dir' => $tempDir ] );
			if ( !$status->isOK() ) {
				$this->statusMessage = 'Failed to prepare the temporary storage directory: ' . $status->getWikiText( false, false, 'en' );
				$this->isError = true;
				return;
			}

			$tempFiles = [];
			foreach ( $uploadedFiles as $format => $fileData ) {
				$dstPath = $tempDir . '/' . $fileData['name'];

				if ( method_exists( $backend, 'quickImport' ) ) {
					$importStatus = $backend->quickImport( [ 'src' => $fileData['tmp'], 'dst' => $dstPath ] );
				} else {
					$importStatus = $backend->quickStore( [ 'src' => $fileData['tmp'], 'dst' => $dstPath ] );
				}

				if ( !$importStatus->isOK() ) {
					$this->statusMessage = "Failed to store temporary {$format} file: " . $importStatus->getWikiText( false, false, 'en' );
					$this->isError = true;
					$this->cleanTempUpload( [ 'tempDir' => $tempDir, 'files' => $tempFiles ] );
					return;
				}

				$tempFiles[$format] = $fileData['name'];
			}

			// Store metadata in session
			$session->set( 'CustomFontsTempUpload', [
				'name' => $familyName,
				'slug' => $slug,
				'tempDir' => $tempDir,
				'files' => $tempFiles
			] );

			$this->tempUploadData = [
				'name' => $familyName,
				'slug' => $slug,
				'tempDir' => $tempDir,
				'files' => $tempFiles
			];
			$this->missingFormats = array_values( $missingFormats );
			$this->showConfirmWarning = true;
			return;
		}

		// If complete package (all 5 formats), proceed to final store directly
		$fontDir = $repo->getZonePath( 'public' ) . '/fonts/' . $slug;
		$status = $backend->prepare( [ 'dir' => $fontDir ] );
		if ( !$status->isOK() ) {
			$this->statusMessage = 'Failed to prepare the storage directory: ' . $status->getWikiText( false, false, 'en' );
			$this->isError = true;
			return;
		}

		foreach ( $uploadedFiles as $format => $fileData ) {
			$dstPath = $fontDir . '/' . $fileData['name'];

			if ( method_exists( $backend, 'quickImport' ) ) {
				$importStatus = $backend->quickImport( [ 'src' => $fileData['tmp'], 'dst' => $dstPath ] );
			} else {
				$importStatus = $backend->quickStore( [ 'src' => $fileData['tmp'], 'dst' => $dstPath ] );
			}

			if ( !$importStatus->isOK() ) {
				$this->statusMessage = "Failed to store {$format} file: " . $importStatus->getWikiText( false, false, 'en' );
				$this->isError = true;
				return;
			}

			$destFileMap[$format] = $fileData['name'];
		}

		$fonts[] = [
			'name' => $familyName,
			'slug' => $slug,
			'formats' => $destFileMap
		];

		$jsonContent = json_encode( $fonts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$writeStatus = $backend->create( [
			'dst' => $fontsJsonPath,
			'content' => $jsonContent,
			'overwrite' => true
		] );

		if ( !$writeStatus->isOK() ) {
			$this->statusMessage = 'Failed to write config file: ' . $writeStatus->getWikiText( false, false, 'en' );
			$this->isError = true;
			return;
		}

		ResourceLoader::clearCache();

		$this->statusMessage = "Font family '{$familyName}' uploaded and registered successfully.";
		$this->isError = false;
	}

	/**
	 * Process custom font deletion.
	 *
	 * @param string $slug
	 * @return void
	 */
	private function handleDelete( string $slug ): void {
		if ( $slug === '' ) {
			$this->statusMessage = 'Missing font slug for deletion.';
			$this->isError = true;
			return;
		}

		$repo = $this->repoGroup->getLocalRepo();
		$backend = $repo->getBackend();
		$fontsJsonPath = $repo->getZonePath( 'public' ) . '/fonts/fonts.json';

		// Retrieve existing configurations
		$fonts = [];
		if ( $backend->fileExists( [ 'src' => $fontsJsonPath ] ) ) {
			$content = $backend->getFileContents( [ 'src' => $fontsJsonPath ] );
			if ( is_string( $content ) ) {
				$fonts = json_decode( $content, true ) ?: [];
			}
		}

		$fontKey = null;
		foreach ( $fonts as $key => $font ) {
			if ( isset( $font['slug'] ) && $font['slug'] === $slug ) {
				$fontKey = $key;
				break;
			}
		}

		if ( $fontKey === null ) {
			$this->statusMessage = "Font family with slug '{$slug}' not found.";
			$this->isError = true;
			return;
		}

		$fontToDelete = $fonts[$fontKey];
		$fontDir = $repo->getZonePath( 'public' ) . '/fonts/' . $slug;

		// Delete each uploaded file associated with the font
		foreach ( $fontToDelete['formats'] as $filename ) {
			$filePath = $fontDir . '/' . $filename;
			$backend->doOperation( [
				'op' => 'delete',
				'src' => $filePath
			] );
		}

		// Remove from index
		unset( $fonts[$fontKey] );
		$fonts = array_values( $fonts );

		// Write configuration back
		$jsonContent = json_encode( $fonts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$writeStatus = $backend->create( [
			'dst' => $fontsJsonPath,
			'content' => $jsonContent,
			'overwrite' => true
		] );

		if ( !$writeStatus->isOK() ) {
			$this->statusMessage = 'Failed to write config file: ' . $writeStatus->getWikiText( false, false, 'en' );
			$this->isError = true;
			return;
		}

		// Clean the empty directory
		$backend->clean( [ 'dir' => $fontDir ] );

		// Purge ResourceLoader caches
		ResourceLoader::clearCache();

		$this->statusMessage = "Font family '{$fontToDelete['name']}' has been deleted.";
		$this->isError = false;
	}

	/**
	 * Process confirmation of incomplete font upload.
	 *
	 * @param WebRequest $request
	 * @return void
	 */
	private function handleConfirmUpload( WebRequest $request ): void {
		$session = $request->getSession();
		$tempData = $session->get( 'CustomFontsTempUpload' );

		if ( !$tempData || !is_array( $tempData ) ) {
			$this->statusMessage = 'No pending upload found. Please try again.';
			$this->isError = true;
			return;
		}

		$repo = $this->repoGroup->getLocalRepo();
		$backend = $repo->getBackend();
		$fontsJsonPath = $repo->getZonePath( 'public' ) . '/fonts/fonts.json';

		// Retrieve existing configurations
		$fonts = [];
		if ( $backend->fileExists( [ 'src' => $fontsJsonPath ] ) ) {
			$content = $backend->getFileContents( [ 'src' => $fontsJsonPath ] );
			if ( is_string( $content ) ) {
				$fonts = json_decode( $content, true ) ?: [];
			}
		}

		// Ensure no duplicate slugs exist
		foreach ( $fonts as $font ) {
			if ( isset( $font['slug'] ) && $font['slug'] === $tempData['slug'] ) {
				$this->statusMessage = "A font family with the slug '{$tempData['slug']}' already exists.";
				$this->isError = true;
				$this->cleanTempUpload( $tempData );
				$session->remove( 'CustomFontsTempUpload' );
				return;
			}
		}

		// Prepare destination directory
		$fontDir = $repo->getZonePath( 'public' ) . '/fonts/' . $tempData['slug'];
		$status = $backend->prepare( [ 'dir' => $fontDir ] );
		if ( !$status->isOK() ) {
			$this->statusMessage = 'Failed to prepare the storage directory: ' . $status->getWikiText( false, false, 'en' );
			$this->isError = true;
			$this->cleanTempUpload( $tempData );
			$session->remove( 'CustomFontsTempUpload' );
			return;
		}

		// Move files from temporary directory to destination
		$ops = [];
		$destFileMap = [];
		foreach ( $tempData['files'] as $format => $filename ) {
			$dstPath = $fontDir . '/' . $filename;
			$ops[] = [
				'op' => 'move',
				'src' => $tempData['tempDir'] . '/' . $filename,
				'dst' => $dstPath,
				'overwrite' => true
			];
			$destFileMap[$format] = $filename;
		}

		$moveStatus = $backend->doOperations( $ops );
		if ( !$moveStatus->isOK() ) {
			$this->statusMessage = 'Failed to move font files: ' . $moveStatus->getWikiText( false, false, 'en' );
			$this->isError = true;
			$this->cleanTempUpload( $tempData );
			$session->remove( 'CustomFontsTempUpload' );
			return;
		}

		// Append new font family to list
		$fonts[] = [
			'name' => $tempData['name'],
			'slug' => $tempData['slug'],
			'formats' => $destFileMap
		];

		// Save the updated JSON configuration
		$jsonContent = json_encode( $fonts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$writeStatus = $backend->create( [
			'dst' => $fontsJsonPath,
			'content' => $jsonContent,
			'overwrite' => true
		] );

		if ( !$writeStatus->isOK() ) {
			$this->statusMessage = 'Failed to write config file: ' . $writeStatus->getWikiText( false, false, 'en' );
			$this->isError = true;
			// Clean destination directory since it failed
			$deleteOps = [];
			foreach ( $destFileMap as $filename ) {
				$deleteOps[] = [
					'op' => 'delete',
					'src' => $fontDir . '/' . $filename
				];
			}
			$backend->doOperations( $deleteOps );
			$backend->clean( [ 'dir' => $fontDir ] );
			return;
		}

		// Clean up temporary directory
		$backend->clean( [ 'dir' => $tempData['tempDir'] ] );
		$session->remove( 'CustomFontsTempUpload' );

		// Purge ResourceLoader caches
		ResourceLoader::clearCache();

		$this->statusMessage = "Font family '{$tempData['name']}' uploaded and registered successfully.";
		$this->isError = false;
	}

	/**
	 * Process cancellation of incomplete font upload.
	 *
	 * @param WebRequest $request
	 * @return void
	 */
	private function handleCancelUpload( WebRequest $request ): void {
		$session = $request->getSession();
		$tempData = $session->get( 'CustomFontsTempUpload' );

		if ( $tempData && is_array( $tempData ) ) {
			$this->cleanTempUpload( $tempData );
		}

		$session->remove( 'CustomFontsTempUpload' );
		$this->statusMessage = 'Upload cancelled.';
		$this->isError = false;
	}

	/**
	 * Clean up a temporary upload from storage.
	 *
	 * @param array $tempData
	 */
	private function cleanTempUpload( array $tempData ): void {
		$repo = $this->repoGroup->getLocalRepo();
		$backend = $repo->getBackend();
		$tempDir = $tempData['tempDir'] ?? null;
		$files = $tempData['files'] ?? [];

		if ( $tempDir ) {
			$ops = [];
			foreach ( $files as $format => $filename ) {
				$ops[] = [
					'op' => 'delete',
					'src' => $tempDir . '/' . $filename
				];
			}
			if ( $ops ) {
				$backend->doOperations( $ops );
			}
			$backend->clean( [ 'dir' => $tempDir ] );
		}
	}

	/**
	 * Render confirmation warning screen.
	 *
	 * @return void
	 */
	private function renderConfirmWarning(): void {
		$out = $this->getOutput();
		$csrfTokenSet = new CsrfTokenSet( $this->getRequest() );

		$missingList = implode( ', ', array_map( 'strtoupper', $this->missingFormats ) );
		$warningHtml = "<div class=\"warningbox\">"
			. "<strong>Warning:</strong> You are registering the font family '"
			. htmlspecialchars( $this->tempUploadData['name'] )
			. "' with missing formats: <strong>" . htmlspecialchars( $missingList ) . "</strong>.<br>"
			. "Some older browsers or specific platforms might not render the font correctly without these formats."
			. "</div><br>";
		$out->addHTML( $warningHtml );

		$confirmButton = new OOUI\ButtonInputWidget( [
			'type' => 'submit',
			'name' => 'confirm-btn',
			'value' => 'confirm',
			'label' => 'Proceed and Register',
			'flags' => [ 'primary', 'progressive' ]
		] );

		$cancelButton = new OOUI\ButtonInputWidget( [
			'type' => 'submit',
			'name' => 'confirm-btn',
			'value' => 'cancel',
			'label' => 'Cancel Upload',
			'flags' => [ 'destructive' ]
		] );

		$confirmForm = new OOUI\FormLayout( [
			'method' => 'POST',
			'action' => $this->getPageTitle()->getLocalURL()
		] );
		$confirmForm->appendContent( new OOUI\HiddenInputWidget( [
			'name' => 'action',
			'value' => 'confirm-upload'
		] ) );
		$confirmForm->appendContent( new OOUI\HiddenInputWidget( [
			'name' => 'token',
			'value' => $csrfTokenSet->getToken()
		] ) );

		$buttonGroup = new OOUI\HorizontalLayout( [
			'items' => [ $confirmButton, $cancelButton ]
		] );
		$confirmForm->appendContent( $buttonGroup );

		$out->addHTML( $confirmForm );
	}

	/**
	 * Render confirmation warning screen for deletion.
	 *
	 * @return void
	 */
	private function renderDeleteConfirm(): void {
		$out = $this->getOutput();
		$csrfTokenSet = new CsrfTokenSet( $this->getRequest() );
		$slug = $this->deleteSlug;

		// Try to find the font's display name
		$repo = $this->repoGroup->getLocalRepo();
		$backend = $repo->getBackend();
		$fontsJsonPath = $repo->getZonePath( 'public' ) . '/fonts/fonts.json';
		$fontName = $slug;

		if ( $backend->fileExists( [ 'src' => $fontsJsonPath ] ) ) {
			$content = $backend->getFileContents( [ 'src' => $fontsJsonPath ] );
			if ( is_string( $content ) ) {
				$fonts = json_decode( $content, true ) ?: [];
				foreach ( $fonts as $font ) {
					if ( isset( $font['slug'] ) && $font['slug'] === $slug ) {
						$fontName = $font['name'] ?? $slug;
						break;
					}
				}
			}
		}

		$warningHtml = "<div class=\"warningbox\">"
			. "<strong>Warning:</strong> Are you sure you want to delete the font family '<strong>"
			. htmlspecialchars( $fontName ) . "</strong>' (slug: <code>" . htmlspecialchars( $slug ) . "</code>)?<br>"
			. "This will permanently remove all associated font files and cannot be undone."
			. "</div><br>";
		$out->addHTML( $warningHtml );

		$confirmButton = new OOUI\ButtonInputWidget( [
			'type' => 'submit',
			'name' => 'confirm-btn',
			'value' => 'confirm',
			'label' => 'Delete Font',
			'flags' => [ 'primary', 'destructive' ]
		] );

		$cancelButton = new OOUI\ButtonInputWidget( [
			'type' => 'submit',
			'name' => 'confirm-btn',
			'value' => 'cancel',
			'label' => 'Cancel',
			'flags' => [ 'safe' ]
		] );

		$confirmForm = new OOUI\FormLayout( [
			'method' => 'POST',
			'action' => $this->getPageTitle()->getLocalURL()
		] );
		$confirmForm->appendContent( new OOUI\HiddenInputWidget( [
			'name' => 'action',
			'value' => 'confirm-delete'
		] ) );
		$confirmForm->appendContent( new OOUI\HiddenInputWidget( [
			'name' => 'slug',
			'value' => $slug
		] ) );
		$confirmForm->appendContent( new OOUI\HiddenInputWidget( [
			'name' => 'token',
			'value' => $csrfTokenSet->getToken()
		] ) );

		$buttonGroup = new OOUI\HorizontalLayout( [
			'items' => [ $confirmButton, $cancelButton ]
		] );
		$confirmForm->appendContent( $buttonGroup );

		$out->addHTML( $confirmForm );
	}

	/**
	 * Render special page contents.
	 *
	 * @return void
	 */
	private function renderPage(): void {
		$out = $this->getOutput();

		// Add custom page titles
		$out->setPageTitle( 'Manage Custom Web Fonts' );

		// Display status banner if set
		if ( $this->statusMessage !== '' ) {
			$class = $this->isError ? 'errorbox' : 'successbox';
			$out->addHTML( "<div class=\"{$class}\">" . htmlspecialchars( $this->statusMessage ) . "</div><br>" );
		}

		if ( $this->showConfirmWarning ) {
			$this->renderConfirmWarning();
			return;
		}

		if ( $this->showDeleteConfirm ) {
			$this->renderDeleteConfirm();
			return;
		}

		if ( $this->showEditForm && $this->editSlug !== '' ) {
			$this->renderEditForm( $this->editSlug );
			return;
		}

		$repo = $this->repoGroup->getLocalRepo();
		$backend = $repo->getBackend();
		$fontsJsonPath = $repo->getZonePath( 'public' ) . '/fonts/fonts.json';

		// Parse active fonts
		$fonts = [];
		if ( $backend->fileExists( [ 'src' => $fontsJsonPath ] ) ) {
			$content = $backend->getFileContents( [ 'src' => $fontsJsonPath ] );
			if ( is_string( $content ) ) {
				$fonts = json_decode( $content, true ) ?: [];
			}
		}

		// Sort fonts alphabetically by family name (case-insensitive)
		usort( $fonts, static function ( $a, $b ) {
			return strcasecmp( $a['name'] ?? '', $b['name'] ?? '' );
		} );

		// 1. View Module: Table of active fonts
		$out->addHTML( '<h2>Active Fonts</h2>' );

		if ( !$fonts ) {
			$out->addHTML( '<p>No custom fonts registered yet.</p>' );
		} else {
			$tableHtml = '<table class="wikitable font-manager-table" style="width: 100%;">';
			$tableHtml .= '<thead><tr><th>Family Name</th><th>Slug</th><th>Available Formats</th><th>Action</th></tr></thead>';
			$tableHtml .= '<tbody>';

			foreach ( $fonts as $font ) {
				$formatDetails = [];
				$allFormats = [ 'eot', 'otf', 'ttf', 'woff', 'woff2' ];
				foreach ( $allFormats as $format ) {
					if ( isset( $font['formats'][$format] ) ) {
						$filename = $font['formats'][$format];
						$formatDetails[] = '<li>' . htmlspecialchars( $format ) . ': ' . htmlspecialchars( $filename ) . '</li>';
					} else {
						$formatDetails[] = '<li>' . htmlspecialchars( $format ) . ': <span style="color: red;">not present</span></li>';
					}
				}
				$formatsListHtml = '<ul style="margin: 0; padding-left: 1.5em;">' . implode( '', $formatDetails ) . '</ul>';
				$slugEsc = htmlspecialchars( $font['slug'] );
				$nameEsc = htmlspecialchars( $font['name'] );

				// Deletion form
				$csrfTokenSet = new CsrfTokenSet( $this->getRequest() );
				$deleteButton = new OOUI\ButtonInputWidget( [
					'type' => 'submit',
					'name' => 'slug',
					'value' => $font['slug'],
					'label' => 'Delete',
					'flags' => [ 'destructive' ]
				] );

				$deleteForm = new OOUI\FormLayout( [
					'method' => 'POST',
					'action' => $this->getPageTitle()->getLocalURL()
				] );
				$deleteForm->appendContent( new OOUI\HiddenInputWidget( [
					'name' => 'action',
					'value' => 'delete'
				] ) );
				$deleteForm->appendContent( new OOUI\HiddenInputWidget( [
					'name' => 'token',
					'value' => $csrfTokenSet->getToken()
				] ) );
				$deleteForm->appendContent( $deleteButton );

				$editButton = new OOUI\ButtonWidget( [
					'href' => $this->getPageTitle()->getLocalURL( [
						'action' => 'edit',
						'slug' => $font['slug']
					] ),
					'label' => 'Edit',
					'flags' => [ 'progressive' ]
				] );

				$tableHtml .= '<tr>';
				$tableHtml .= '<td><strong>' . $nameEsc . '</strong></td>';
				$tableHtml .= '<td><code>' . $slugEsc . '</code></td>';
				$tableHtml .= '<td>' . $formatsListHtml . '</td>';
				$tableHtml .= '<td><div style="display: flex; gap: 8px; align-items: center;">' . $editButton . $deleteForm . '</div></td>';
				$tableHtml .= '</tr>';
			}

			$tableHtml .= '</tbody></table>';
			$out->addHTML( $tableHtml );
		}

		// 2. Upload Module: OOUI form
		$out->addHTML( '<h2>Upload Font</h2>' );

		$nameInput = new OOUI\TextInputWidget( [
			'name' => 'font-name',
			'required' => true,
			'placeholder' => 'e.g., Montserrat, Source Sans 3'
		] );

		$fieldset = new OOUI\FieldsetLayout( [
			'label' => 'Upload a new Font Family'
		] );

		$fieldset->addItems( [
			new OOUI\FieldLayout( $nameInput, [
				'label' => 'Font Family Name',
				'align' => 'top',
				'help' => 'Enter the official font family name.'
			] )
		] );

		$formats = [
			'woff2' => [ 'required' => false, 'help' => 'Web Open Font Format 2.0 for modern browsers with superior compression (optional).' ],
			'ttf' => [ 'required' => false, 'help' => 'TrueType Font for older browsers and general operating system compatibility (optional).' ],
			'woff' => [ 'required' => false, 'help' => 'Web Open Font Format 1.0 for compatibility with older modern browsers (optional).' ],
			'eot' => [ 'required' => false, 'help' => 'Embedded OpenType for legacy Internet Explorer compatibility (optional).' ],
			'otf' => [ 'required' => false, 'help' => 'OpenType Font for advanced typesetting features and cross-platform compatibility (optional).' ],
		];

		foreach ( $formats as $format => $config ) {
			$fileInput = new OOUI\SelectFileInputWidget( [
				'name' => 'font-file-' . $format,
				'accept' => [ '.' . $format ],
				'required' => $config['required']
			] );

			$fieldset->addItems( [
				new OOUI\FieldLayout( $fileInput, [
					'label' => strtoupper( $format ) . ' File',
					'align' => 'top',
					'help' => $config['help']
				] )
			] );
		}

		$submitButton = new OOUI\ButtonInputWidget( [
			'type' => 'submit',
			'label' => 'Register Font Family',
			'flags' => [ 'primary', 'progressive' ]
		] );

		$fieldset->addItems( [
			new OOUI\FieldLayout( $submitButton )
		] );

		$csrfTokenSet = new CsrfTokenSet( $this->getRequest() );
		$uploadForm = new OOUI\FormLayout( [
			'method' => 'POST',
			'action' => $this->getPageTitle()->getLocalURL(),
			'enctype' => 'multipart/form-data'
		] );
		$uploadForm->appendContent( new OOUI\HiddenInputWidget( [
			'name' => 'action',
			'value' => 'upload'
		] ) );
		$uploadForm->appendContent( new OOUI\HiddenInputWidget( [
			'name' => 'token',
			'value' => $csrfTokenSet->getToken()
		] ) );
		$uploadForm->appendContent( $fieldset );

		$out->addHTML( $uploadForm );
	}

	/**
	 * Render the edit form for a font family.
	 *
	 * @param string $slug
	 * @return void
	 */
	private function renderEditForm( string $slug ): void {
		$out = $this->getOutput();
		$repo = $this->repoGroup->getLocalRepo();
		$backend = $repo->getBackend();
		$fontsJsonPath = $repo->getZonePath( 'public' ) . '/fonts/fonts.json';

		$fonts = [];
		if ( $backend->fileExists( [ 'src' => $fontsJsonPath ] ) ) {
			$content = $backend->getFileContents( [ 'src' => $fontsJsonPath ] );
			if ( is_string( $content ) ) {
				$fonts = json_decode( $content, true ) ?: [];
			}
		}

		$targetFont = null;
		foreach ( $fonts as $font ) {
			if ( isset( $font['slug'] ) && $font['slug'] === $slug ) {
				$targetFont = $font;
				break;
			}
		}

		if ( !$targetFont ) {
			$this->statusMessage = "Font family with slug '{$slug}' not found.";
			$this->isError = true;
			$this->showEditForm = false;
			// Re-render main page
			$this->renderPage();
			return;
		}

		$out->setPageTitle( 'Edit Font Family: ' . $targetFont['name'] );
		$out->addHTML( '<style>.mw-customfonts-hidden-input { display: none; }</style>' );

		// Display status banner if set
		if ( $this->statusMessage !== '' ) {
			$class = $this->isError ? 'errorbox' : 'successbox';
			$out->addHTML( "<div class=\"{$class}\">" . htmlspecialchars( $this->statusMessage ) . "</div><br>" );
		}

		$slugInput = new OOUI\TextInputWidget( [
			'name' => 'font-slug-display',
			'value' => $slug,
			'disabled' => true
		] );

		$nameInput = new OOUI\TextInputWidget( [
			'name' => 'font-name',
			'required' => true,
			'value' => $targetFont['name']
		] );

		$fieldset = new OOUI\FieldsetLayout( [
			'label' => 'Edit Font Details'
		] );

		$fieldset->addItems( [
			new OOUI\FieldLayout( $slugInput, [
				'label' => 'Font Slug (cannot be changed)',
				'align' => 'top'
			] ),
			new OOUI\FieldLayout( $nameInput, [
				'label' => 'Font Family Name',
				'align' => 'top',
				'help' => 'Edit the family name used in CSS font-family rules.'
			] )
		] );

		$formats = [
			'woff2' => 'Web Open Font Format 2.0 for modern browsers with superior compression.',
			'ttf' => 'TrueType Font for older browsers and general operating system compatibility.',
			'woff' => 'Web Open Font Format 1.0 for compatibility with older modern browsers.',
			'eot' => 'Embedded OpenType for legacy Internet Explorer compatibility.',
			'otf' => 'OpenType Font for advanced typesetting features and cross-platform compatibility.',
		];

		foreach ( $formats as $format => $help ) {
			$hasFile = isset( $targetFont['formats'][$format] );
			$currentFilename = $hasFile ? $targetFont['formats'][$format] : '';
			$fileLabel = strtoupper( $format ) . ' File';

			$fileInputHtml = new OOUI\Widget( [
				'content' => new OOUI\HtmlSnippet(
					'<input type="file" name="font-file-' . $format . '" id="mw-font-input-' . $format . '" accept=".' . $format . '" '
					. ( $hasFile ? 'style="display: none;"' : '' ) . '>'
				)
			] );

			if ( $hasFile ) {
				$fileHelp = new OOUI\HtmlSnippet(
					'<input type="hidden" name="delete-file-' . $format . '" id="delete-file-' . $format . '" value="0">'
					. '<div id="mw-font-current-' . $format . '" style="display: flex; gap: 8px; align-items: center; margin-top: 4px; margin-bottom: 4px;">'
					. '<span style="font-weight: bold; font-family: monospace; color: #202122;">' . htmlspecialchars( $currentFilename ) . '</span>'
					. '<button type="button" style="border: 1px solid #36c; color: #36c; background: #fff; padding: 2px 8px; border-radius: 2px; font-weight: bold; cursor: pointer; font-size: 0.9em;" '
					. 'onclick="'
					. 'document.getElementById(\'mw-font-current-' . $format . '\').style.display=\'none\'; '
					. 'document.getElementById(\'mw-font-cancel-block-' . $format . '\').style.display=\'flex\'; '
					. 'document.getElementById(\'mw-font-input-' . $format . '\').style.display=\'block\';'
					. '">Replace</button>'
					. '<button type="button" style="border: 1px solid #d33; color: #d33; background: #fff; padding: 2px 8px; border-radius: 2px; font-weight: bold; cursor: pointer; font-size: 0.9em;" '
					. 'onclick="'
					. 'document.getElementById(\'delete-file-' . $format . '\').value=\'1\'; '
					. 'document.getElementById(\'mw-font-current-' . $format . '\').style.display=\'none\'; '
					. 'document.getElementById(\'mw-font-cancel-block-' . $format . '\').style.display=\'flex\'; '
					. 'document.getElementById(\'mw-font-input-' . $format . '\').style.display=\'block\';'
					. '">Delete</button>'
					. '<span style="color: #72777d; font-size: 0.9em; margin-left: 4px;">(' . htmlspecialchars( $help ) . ')</span>'
					. '</div>'
					. '<div id="mw-font-cancel-block-' . $format . '" style="display: none; gap: 8px; align-items: center; margin-top: 4px; margin-bottom: 4px;">'
					. '<button type="button" style="border: 1px solid #72777d; color: #202122; background: #fff; padding: 2px 8px; border-radius: 2px; font-weight: bold; cursor: pointer; font-size: 0.9em;" '
					. 'onclick="'
					. 'document.getElementById(\'mw-font-current-' . $format . '\').style.display=\'flex\'; '
					. 'document.getElementById(\'mw-font-cancel-block-' . $format . '\').style.display=\'none\'; '
					. 'document.getElementById(\'mw-font-input-' . $format . '\').style.display=\'none\'; '
					. 'document.getElementById(\'delete-file-' . $format . '\').value=\'0\'; '
					. 'var inp = document.getElementById(\'mw-font-input-' . $format . '\'); if (inp) { inp.value = \'\'; }'
					. '">Cancel</button>'
					. '<span style="color: #72777d; font-size: 0.9em; margin-left: 4px;">(' . htmlspecialchars( $help ) . ')</span>'
					. '</div>'
				);
			} else {
				$fileHelp = 'No file uploaded. Upload a file to add this format. (' . $help . ')';
			}

			$fieldset->addItems( [
				new OOUI\FieldLayout( $fileInputHtml, [
					'label' => $fileLabel,
					'align' => 'top',
					'help' => $fileHelp,
					'helpInline' => true
				] )
			] );
		}

		$submitButton = new OOUI\ButtonInputWidget( [
			'type' => 'submit',
			'label' => 'Save Changes',
			'flags' => [ 'primary', 'progressive' ]
		] );

		$cancelButton = new OOUI\ButtonWidget( [
			'href' => $this->getPageTitle()->getLocalURL(),
			'label' => 'Cancel',
			'flags' => [ 'safe' ]
		] );

		$buttonGroup = new OOUI\HorizontalLayout( [
			'items' => [ $submitButton, $cancelButton ]
		] );

		$fieldset->addItems( [
			$buttonGroup
		] );

		$csrfTokenSet = new CsrfTokenSet( $this->getRequest() );
		$editForm = new OOUI\FormLayout( [
			'method' => 'POST',
			'action' => $this->getPageTitle()->getLocalURL(),
			'enctype' => 'multipart/form-data'
		] );
		$editForm->appendContent( new OOUI\HiddenInputWidget( [
			'name' => 'action',
			'value' => 'save-edit'
		] ) );
		$editForm->appendContent( new OOUI\HiddenInputWidget( [
			'name' => 'slug',
			'value' => $slug
		] ) );
		$editForm->appendContent( new OOUI\HiddenInputWidget( [
			'name' => 'token',
			'value' => $csrfTokenSet->getToken()
		] ) );
		$editForm->appendContent( $fieldset );

		$out->addHTML( $editForm );
	}

	/**
	 * Process editing of a font family.
	 *
	 * @param WebRequest $request
	 * @return void
	 */
	private function handleEdit( WebRequest $request ): void {
		$slug = (string)$request->getVal( 'slug' );
		$familyName = trim( (string)$request->getVal( 'font-name' ) );

		if ( $slug === '' ) {
			$this->statusMessage = 'Missing font slug for edit.';
			$this->isError = true;
			return;
		}

		if ( $familyName === '' ) {
			$this->statusMessage = 'Font family name is required.';
			$this->isError = true;
			$this->showEditForm = true;
			$this->editSlug = $slug;
			return;
		}

		$repo = $this->repoGroup->getLocalRepo();
		$backend = $repo->getBackend();
		$fontsJsonPath = $repo->getZonePath( 'public' ) . '/fonts/fonts.json';

		// Retrieve existing configurations
		$fonts = [];
		if ( $backend->fileExists( [ 'src' => $fontsJsonPath ] ) ) {
			$content = $backend->getFileContents( [ 'src' => $fontsJsonPath ] );
			if ( is_string( $content ) ) {
				$fonts = json_decode( $content, true ) ?: [];
			}
		}

		$fontKey = null;
		foreach ( $fonts as $key => $font ) {
			if ( isset( $font['slug'] ) && $font['slug'] === $slug ) {
				$fontKey = $key;
				break;
			}
		}

		if ( $fontKey === null ) {
			$this->statusMessage = "Font family with slug '{$slug}' not found.";
			$this->isError = true;
			return;
		}

		// Track font formats
		$formats = [ 'woff2', 'woff', 'ttf', 'eot', 'otf' ];
		$uploadedFiles = [];

		foreach ( $formats as $format ) {
			$fieldName = 'font-file-' . $format;
			$tmpName = $request->getFileTempname( $fieldName );
			$origName = $request->getFileName( $fieldName );

			if ( !$tmpName || $origName === null || $origName === '' ) {
				continue;
			}

			// Validate file extension
			$ext = pathinfo( $origName, PATHINFO_EXTENSION );
			if ( strtolower( $ext ) !== $format ) {
				$this->statusMessage = "Invalid file extension for {$format} format (expected .{$format}).";
				$this->isError = true;
				$this->showEditForm = true;
				$this->editSlug = $slug;
				return;
			}

			$uploadedFiles[$format] = [
				'tmp' => $tmpName,
				'name' => $origName
			];
		}

		$fontDir = $repo->getZonePath( 'public' ) . '/fonts/' . $slug;
		$status = $backend->prepare( [ 'dir' => $fontDir ] );
		if ( !$status->isOK() ) {
			$this->statusMessage = 'Failed to prepare the storage directory: ' . $status->getWikiText( false, false, 'en' );
			$this->isError = true;
			$this->showEditForm = true;
			$this->editSlug = $slug;
			return;
		}

		$destFileMap = $fonts[$fontKey]['formats'] ?: [];
		foreach ( $formats as $format ) {
			$deleteFlag = $request->getVal( 'delete-file-' . $format );
			if ( $deleteFlag === '1' ) {
				unset( $destFileMap[$format] );
			}
		}

		foreach ( $uploadedFiles as $format => $fileData ) {
			$dstPath = $fontDir . '/' . $fileData['name'];

			if ( method_exists( $backend, 'quickImport' ) ) {
				$importStatus = $backend->quickImport( [ 'src' => $fileData['tmp'], 'dst' => $dstPath ] );
			} else {
				$importStatus = $backend->quickStore( [ 'src' => $fileData['tmp'], 'dst' => $dstPath ] );
			}

			if ( !$importStatus->isOK() ) {
				$this->statusMessage = "Failed to store {$format} file: " . $importStatus->getWikiText( false, false, 'en' );
				$this->isError = true;
				$this->showEditForm = true;
				$this->editSlug = $slug;
				return;
			}

			$destFileMap[$format] = $fileData['name'];
		}

		$fonts[$fontKey]['name'] = $familyName;
		$fonts[$fontKey]['formats'] = $destFileMap;

		$jsonContent = json_encode( $fonts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$writeStatus = $backend->create( [
			'dst' => $fontsJsonPath,
			'content' => $jsonContent,
			'overwrite' => true
		] );

		if ( !$writeStatus->isOK() ) {
			$this->statusMessage = 'Failed to write config file: ' . $writeStatus->getWikiText( false, false, 'en' );
			$this->isError = true;
			$this->showEditForm = true;
			$this->editSlug = $slug;
			return;
		}

		ResourceLoader::clearCache();

		$this->statusMessage = "Font family '{$familyName}' updated successfully.";
		$this->isError = false;
	}
}
