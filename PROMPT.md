# Prompts for Antigravity 2 (Gemini 3.5 Flash Medium)

## Original prompt

Act as an expert MediaWiki developer specializing in MediaWiki 1.45.x architecture. Write a custom MediaWiki extension named "CustomFonts" that allows site administrators to upload custom web fonts via a special page and serves them dynamically using ResourceLoader.

The extension must strictly adhere to MediaWiki 1.45 SDK practices, including modern service wiring, strict PHP type declarations, and current OOUI/ResourceLoader abstractions.

Follow these strict requirements:

### 1. Extension Registration (extension.json)

- Base configuration targeting MediaWiki 1.45 compatibility.
- Register a special page named 'CustomFonts' mapped to `MediaWiki\Extension\CustomFonts\SpecialCustomFonts`.
- Define a custom permission 'manage-custom-fonts' and assign it to the 'sysop' group by default.
- Register a dynamic ResourceLoader module named 'ext.customFonts.styles' using a custom ResourceLoader module class (`MediaWiki\Extension\CustomFonts\FontStylesModule`).
- Use the modern Hook Handlers configuration to register the 'BeforePageDisplay' hook using its contemporary 1.45 interface class (`MediaWiki\Hook\BeforePageDisplayHook`) to inject 'ext.customFonts.styles' into every page load.

### 2. Storage Architecture (FileBackend)

- Font configuration data must be stored in a flat JSON file located at `$IP/images/fonts/fonts.json`.
- The JSON file must keep an array of font families tracking: human-readable family name, folder slug, and a mapping of formats (woff2, woff, ttf, eot, otf) to their exact filenames.
- Do NOT use native PHP filesystem operations (`mkdir`, `move_uploaded_file`, `unlink`). You MUST use MediaWiki's FileBackend architecture by fetching the local repo backend via the `RepoGroup` service.
- Store uploaded fonts under `$IP/images/fonts/<family-slug>/`. The `<family-slug>` must be a safely filtered version of the font family name (lowercase, alphanumeric, and hyphens only).

### 3. Special Page Class (`SpecialCustomFonts`)

- Inherit from `SpecialPage` and implement standard 1.45 constructor-based Dependency Injection for services (`RepoGroup`, `ResourceLoader`).
- Enforce the permission in `execute()` using `$this->checkPermissions( 'manage-custom-fonts' )`.
- Build the entire UI using MediaWiki's OOUI library.
- **View Module:** Display an HTML/OOUI table of currently active font families parsed from `fonts.json`. Include a deletion action button for each row.
- **Upload Module:** Provide an OOUI HTML form matching your layout requirements. Fields: Font Family Name (Text), and File Inputs for woff2, woff, ttf, eot, and otf. Mark woff2 and ttf as required.
- **File Handling:** Process files using `FileBackend::prepare()` and `FileBackend::quickImport()` to stream from PHP temp storage directly into the local repository target paths. Update `fonts.json` using the file backend.
- **Deletion Handling:** Use `FileBackend::doOperation(['op' => 'delete', ...])` to clear files, update the JSON index, and use `FileBackend::clean()` on the empty subdirectory.
- **Cache Purging:** Immediately after any successful upload or deletion, trigger `ResourceLoader::clearCache()` to ensure the layout changes propagate immediately.

### 4. Dynamic Style Module Class (`FontStylesModule`)

- Create the class `FontStylesModule` extending the base `ResourceLoaderModule`.
- Implement `getStyles( ResourceLoaderContext $context )`.
- Use the `RepoGroup` service to locate and safely read `$IP/images/fonts/fonts.json`.
- Dynamically build and return a valid CSS string containing standard `@font-face` blocks for every font family inside the JSON file.
- Resolve the font file source URLs using the local repository's public zone mapping: `$repo->getZoneUrl( 'public' ) . '/fonts/' . $slug . '/' . $filename`.
- Set proper format definitions in the CSS string matching the files (`format('woff2')`, `format('truetype')`, etc.).
- Ensure `getType()` returns `ResourceLoaderModule::LOAD_STYLES`.

Generate clean, robust, PSR-12 compliant code, utilizing modern PHP 8.x type hinting and proper strict null-checks required by the MediaWiki 1.45 SDK.

## Follow up

Using https://www.mediawiki.org/wiki/Manual:PHP_unit_testing/Writing_unit_tests_for_extensions as a reference, write unit tests for the extension.
