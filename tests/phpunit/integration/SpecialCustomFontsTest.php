<?php

declare(strict_types=1);

namespace MediaWiki\Extension\MediaWikiCustomFonts\Tests\Integration;

use MediaWiki\Extension\MediaWikiCustomFonts\SpecialCustomFonts;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWikiIntegrationTestCase;

/**
 * @group CustomFonts
 * @covers \MediaWiki\Extension\MediaWikiCustomFonts\SpecialCustomFonts
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
