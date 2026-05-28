<?php

declare(strict_types=1);

namespace MediaWiki\Extension\CustomFonts;

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
					$this->handleDelete( $request );
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

		// Track font formats and required statuses
		$formats = [ 'woff2', 'woff', 'ttf', 'eot', 'otf' ];
		$required = [ 'woff2', 'ttf' ];
		$uploadedFiles = [];
		$destFileMap = [];

		foreach ( $formats as $format ) {
			$fieldName = 'font-file-' . $format;
			$tmpName = $request->getFileTempname( $fieldName );
			$origName = $request->getFileName( $fieldName );

			if ( !$tmpName || $origName === null || $origName === '' ) {
				if ( in_array( $format, $required, true ) ) {
					$this->statusMessage = "The {$format} format is required.";
					$this->isError = true;
					return;
				}
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
				'name' => $slug . '.' . $format
			];
		}

		// Prepare fonts directory
		$fontDir = $repo->getZonePath( 'public' ) . '/fonts/' . $slug;
		$status = $backend->prepare( [ 'dir' => $fontDir ] );
		if ( !$status->isOK() ) {
			$this->statusMessage = 'Failed to prepare the storage directory: ' . $status->getWikiText( false, false, 'en' );
			$this->isError = true;
			return;
		}

		// Stream files from PHP temp storage directly into the local repository target paths
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

		// Append new font family to list
		$fonts[] = [
			'name' => $familyName,
			'slug' => $slug,
			'formats' => $destFileMap
		];

		// Save the updated JSON configuration using FileBackend
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

		// Purge ResourceLoader caches
		ResourceLoader::clearCache();

		$this->statusMessage = "Font family '{$familyName}' uploaded and registered successfully.";
		$this->isError = false;
	}

	/**
	 * Process custom font deletion.
	 *
	 * @param WebRequest $request
	 * @return void
	 */
	private function handleDelete( WebRequest $request ): void {
		$slug = (string)$request->getVal( 'slug' );
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

		// 1. View Module: Table of active fonts
		$out->addHTML( '<h2>Active Fonts</h2>' );

		if ( !$fonts ) {
			$out->addHTML( '<p>No custom fonts registered yet.</p>' );
		} else {
			$tableHtml = '<table class="wikitable font-manager-table" style="width: 100%;">';
			$tableHtml .= '<thead><tr><th>Family Name</th><th>Slug</th><th>Available Formats</th><th>Action</th></tr></thead>';
			$tableHtml .= '<tbody>';

			foreach ( $fonts as $font ) {
				$formatsList = implode( ', ', array_keys( $font['formats'] ) );
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

				$tableHtml .= '<tr>';
				$tableHtml .= '<td><strong>' . $nameEsc . '</strong></td>';
				$tableHtml .= '<td><code>' . $slugEsc . '</code></td>';
				$tableHtml .= '<td>' . htmlspecialchars( $formatsList ) . '</td>';
				$tableHtml .= '<td>' . $deleteForm . '</td>';
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
			'woff2' => [ 'required' => true, 'help' => 'Highly recommended (required).' ],
			'ttf' => [ 'required' => true, 'help' => 'TrueType outline (required).' ],
			'woff' => [ 'required' => false, 'help' => 'Web Open Font Format (optional).' ],
			'eot' => [ 'required' => false, 'help' => 'Embedded OpenType for legacy IE (optional).' ],
			'otf' => [ 'required' => false, 'help' => 'OpenType outline (optional).' ],
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
}
