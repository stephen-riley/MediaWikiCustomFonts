<?php

declare(strict_types=1);

namespace MediaWiki\Extension\CustomFonts\Tests\Unit;

use MediaWiki\Extension\CustomFonts\HookHandler;
use MediaWikiUnitTestCase;
use OutputPage;
use Skin;

/**
 * @group CustomFonts
 * @covers \MediaWiki\Extension\CustomFonts\HookHandler
 */
class HookHandlerTest extends MediaWikiUnitTestCase {

	/**
	 * Test that onBeforePageDisplay injects the CustomFonts stylesheet module.
	 */
	public function testOnBeforePageDisplay(): void {
		$out = $this->createMock( OutputPage::class );
		$skin = $this->createMock( Skin::class );

		$out->expects( $this->once() )
			->method( 'addModuleStyles' )
			->with( [ 'ext.customFonts.styles' ] );

		$handler = new HookHandler();
		$handler->onBeforePageDisplay( $out, $skin );
	}
}
