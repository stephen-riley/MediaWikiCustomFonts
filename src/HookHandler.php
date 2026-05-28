<?php

declare(strict_types=1);

namespace MediaWiki\Extension\CustomFonts;

use MediaWiki\Hook\BeforePageDisplayHook;
use OutputPage;
use Skin;

/**
 * Hook handler for BeforePageDisplay hook.
 */
class HookHandler implements BeforePageDisplayHook {

	/**
	 * Inject the custom fonts styles into the page display.
	 *
	 * @param OutputPage $out
	 * @param Skin $skin
	 * @return void
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$out->addModuleStyles( [ 'ext.customFonts.styles' ] );
	}
}
