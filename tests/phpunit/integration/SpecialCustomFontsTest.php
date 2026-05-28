<?php

declare(strict_types=1);

namespace MediaWiki\Extension\CustomFonts\Tests\Integration;

use MediaWiki\Extension\CustomFonts\SpecialCustomFonts;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWikiIntegrationTestCase;

/**
 * @group CustomFonts
 * @covers \MediaWiki\Extension\CustomFonts\SpecialCustomFonts
 */
class SpecialCustomFontsTest extends MediaWikiIntegrationTestCase {

	/**
	 * Test the constructor constraints and page restriction.
	 */
	public function testConstructor(): void {
		$repoGroup = $this->createMock( RepoGroup::class );
		$resourceLoader = $this->createMock( ResourceLoader::class );

		$specialPage = new SpecialCustomFonts( $repoGroup, $resourceLoader );
		$this->assertSame( 'CustomFonts', $specialPage->getName() );
		$this->assertSame( 'manage-custom-fonts', $specialPage->getRestriction() );
	}
}
