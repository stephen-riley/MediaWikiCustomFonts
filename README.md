# MediaWiki CustomFonts Extension

The **CustomFonts** extension for MediaWiki allows site administrators (sysops) to upload custom web fonts (`.woff2`, `.woff`, `.ttf`, `.eot`, `.otf`) directly through a special page, stores them using MediaWiki's internal `FileBackend` architecture, and serves them dynamically across all wiki pages using `ResourceLoader`.

This extension is built targeting **MediaWiki 1.45.x+** and conforms to modern extension development practices (ObjectFactory constructor injection, namespaces, strict types, and session-based CSRF protection).

---

## Features

- **Special Page Admin Panel:** A secure interface (`Special:CustomFonts`) built entirely with MediaWiki's OOUI library.
- **Dynamic CSS Module:** A dynamic ResourceLoader stylesheet module that reads font details from an index and generates standard CSS `@font-face` rules.
- **Safe Storage Abstraction:** Uploaded fonts are managed via MediaWiki's `FileBackend` (under `$IP/images/fonts/`), avoiding raw PHP filesystem operations to maintain compatibility with remote storage backends (e.g., AWS S3, Swift).
- **Auto Cache Invalidation:** Automatically invalidates ResourceLoader cache (`ResourceLoader::clearCache()`) immediately on font upload or deletion.
- **Modern Hook System:** Injects fonts globally using modern hook handlers mapping the `BeforePageDisplay` hook.
- **Robust Security:** Form submissions validate user permissions (`manage-custom-fonts`) and challenge CSRF tokens via `CsrfTokenSet`.

---

## Installation

### 1. Place the Extension Files

Clone or copy this extension to your MediaWiki installation's `extensions/` directory:

```bash
cd /path/to/mediawiki/extensions/
git clone https://github.com/stephen-riley/mediawiki-customfonts-extension.git CustomFonts
```

### 2. Enable the Extension

Add the following line to the bottom of your `$IP/LocalSettings.php` file:

```php
wfLoadExtension( 'CustomFonts' );
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

*Note: The font family name is safely sanitized into a lowercase alphanumeric-and-hyphen slug (e.g., "Open Sans" becomes "open-sans") which is used as both the subdirectory name and prefix for files.*

---

## PHPUnit Testing

The extension includes a suite of unit and integration tests under `tests/phpunit/`.

### Run all tests in the extension

Run from within your **MediaWiki core directory**:

```bash
composer phpunit:entrypoint -- extensions/CustomFonts/tests/phpunit/
```

### Run specific tests

- **Unit Tests:**

  ```bash
  composer phpunit:entrypoint -- extensions/CustomFonts/tests/phpunit/unit/HookHandlerTest.php
  ```

- **Integration Tests:**

  ```bash
  composer phpunit:entrypoint -- extensions/CustomFonts/tests/phpunit/integration/FontStylesModuleTest.php
  ```

---

## License

This extension is licensed under the GPL-2.0-or-later license.
