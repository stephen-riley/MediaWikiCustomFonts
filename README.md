# MediaWikiCustomFonts Extension

The **MediaWikiCustomFonts** extension for MediaWiki allows site administrators (sysops) to upload custom web fonts (`.woff2`, `.woff`, `.ttf`, `.eot`, `.otf`) directly through a special page, stores them using MediaWiki's internal `FileBackend` architecture, and serves them dynamically across all wiki pages using `ResourceLoader`.

This extension is built targeting **MediaWiki 1.45.x+** and conforms to modern extension development practices (ObjectFactory constructor injection, namespaces, strict types, and session-based CSRF protection).

---

## Features

- **Special Page Admin Panel:** A secure interface (`Special:CustomFonts`) built entirely with MediaWiki's OOUI library.
- **Dynamic CSS Module:** A dynamic ResourceLoader stylesheet module that reads font details from an index and generates standard CSS `@font-face` rules.
- **Safe Storage Abstraction:** Uploaded fonts are managed via MediaWiki's `FileBackend` (under `$IP/images/fonts/`), avoiding raw PHP filesystem operations to maintain compatibility with remote storage backends (e.g., AWS S3, Swift).
- **Incomplete Package Warning:** Prompts administrators with an OOUI warning page when attempting to register a font with fewer than all 5 formats (stashing files temporarily in `fonts/tmp/`), allowing them to proceed or cancel.
- **Font Family Editing:** Allows administrators to edit an existing font family's name (while keeping the slug read-only), upload new format files to add formats or replace/overwrite existing formats, and delete registered format entries from `fonts.json` (leaving the actual physical files untouched on the server to prevent link breakage).
- **Delete Confirmation:** Prompts administrators with an OOUI confirmation warning screen before permanently deleting a font and all its associated files to prevent accidental removal.
- **Auto Cache Invalidation:** Automatically invalidates ResourceLoader cache (`ResourceLoader::clearCache()`) immediately on font upload, edit, or deletion.
- **Modern Hook System:** Injects fonts globally using modern hook handlers mapping the `BeforePageDisplay` hook.
- **Robust Security:** Form submissions validate user permissions (`manage-custom-fonts`) and challenge CSRF tokens via `CsrfTokenSet`.

---

## Installation

### 1. Place the Extension Files

Clone or copy this extension to your MediaWiki installation's `extensions/` directory:

```bash
cd /path/to/mediawiki/extensions/
git clone https://github.com/stephen-riley/mediawiki-customfonts-extension.git MediaWikiCustomFonts
```

### 2. Enable the Extension

Add the following line to the bottom of your `$IP/LocalSettings.php` file:

```php
wfLoadExtension( 'MediaWikiCustomFonts' );
```

---

## Permissions

The extension registers a custom user right:

- `manage-custom-fonts`: Required to view, upload, and delete custom fonts.

By default, this right is assigned to the `sysop` user group in `extension.json`:

```json
"GroupPermissions": {
    "sysop": {
        "manage-custom-fonts": true
    }
}
```

---

## Architecture & Storage Schema

### Storage Architecture

All files are stored inside the local repository’s public zone under a `/fonts/` directory.

- **Index File:** `$IP/images/fonts/fonts.json`
- **Fonts Location:** `$IP/images/fonts/<family-slug>/`

### Config Format (`fonts.json`)

The active fonts index tracks uploaded font metadata:

```json
[
  {
    "name": "Open Sans",
    "slug": "open-sans",
    "formats": {
      "woff2": "open-sans.woff2",
      "ttf": "open-sans.ttf"
    }
  }
]
```

*Note: The font family name is safely sanitized into a lowercase alphanumeric-and-hyphen slug (e.g., "Open Sans" becomes "open-sans") which is used as the subdirectory name. Original filenames are preserved during upload and registration.*

---

## PHPUnit Testing

The extension includes a comprehensive suite of unit and integration tests under `tests/phpunit/` to verify logic correctness, secure actions, and layout flows.

### Test Coverage

- **[HookHandlerTest.php](file:///Users/jsrs701/Projects/mediawiki-font-manager/tests/phpunit/unit/HookHandlerTest.php)**: Unit test verifying HookHandler correctly registers the dynamic styles module with the output page.
- **[FontStylesModuleTest.php](file:///Users/jsrs701/Projects/mediawiki-font-manager/tests/phpunit/integration/FontStylesModuleTest.php)**: Integration test checking that font-face CSS is dynamically compiled correctly using an isolated, temporary test directory and file backend.
- **[SpecialCustomFontsTest.php](file:///Users/jsrs701/Projects/mediawiki-font-manager/tests/phpunit/integration/SpecialCustomFontsTest.php)**: Integration test using session mocking, isolated backend storage, and partial SpecialPage mocking to verify:
  - Complete upload registrations.
  - Incomplete upload warning triggering, stashing, confirmation, and cancellation.
  - Delete warning confirmation triggering, deletion of font files and directory, and cancellation.
  - Editing font family name only.
  - Uploading and replacing format files (preserving original filenames).
  - Deleting font file entries from the configuration map while keeping files on the backend.

### Run all tests in the extension

You must install dependencies before running tests for the first time. Run from within your **MediaWiki core directory**:

```bash
composer install
```

Run the PHPUnit suite from within your **MediaWiki core directory**:

```bash
composer phpunit:entrypoint -- --configuration tests/phpunit/suite.xml extensions/MediaWikiCustomFonts/tests/phpunit/
```

Alternatively, you can run `phpunit` directly from the MediaWiki root directory:

```bash
phpunit --configuration tests/phpunit/suite.xml extensions/MediaWikiCustomFonts/tests/phpunit/
```

### Run specific tests

- **Unit Tests:**

  ```bash
  composer phpunit:entrypoint -- extensions/MediaWikiCustomFonts/tests/phpunit/unit/HookHandlerTest.php
  ```

- **Integration Tests:**

  ```bash
  composer phpunit:entrypoint -- extensions/MediaWikiCustomFonts/tests/phpunit/integration/FontStylesModuleTest.php
  ```

  ```bash
  composer phpunit:entrypoint -- extensions/MediaWikiCustomFonts/tests/phpunit/integration/SpecialCustomFontsTest.php
  ```

---

## License

This extension is licensed under the GPL-2.0-or-later license.
