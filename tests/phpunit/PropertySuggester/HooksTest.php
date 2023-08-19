<?php

namespace PropertySuggester;

use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use RequestContext;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\Repo\WikibaseRepo;

/**
 * @covers \PropertySuggester\Hooks
 *
 * @group PropertySuggester
 * @group Wikibase
 */
class HooksTest extends MediaWikiIntegrationTestCase {

	public function testOnBeforePageDisplay_resourceLoaderModuleAdded() {
		$title = self::getTitleForId( new ItemId( 'Q1' ) );

		$context = $this->getContext( $title );
		$output = $context->getOutput();
		$skin = $context->getSkin();

		Hooks::onBeforePageDisplay( $output, $skin );

		$this->assertContains( 'propertySuggester.suggestions', $output->getModules() );
	}

	/**
	 * @dataProvider onBeforePageDisplay_resourceLoaderModuleNotAddedProvider
	 */
	public function testOnBeforePageDisplay_resourceLoaderModuleNotAdded( Title $title = null ) {
		$context = $this->getContext( $title );
		$output = $context->getOutput();
		$skin = $context->getSkin();

		Hooks::onBeforePageDisplay( $output, $skin );

		$this->assertNotContains( 'propertySuggester.suggestions', $output->getModules() );
	}

	public static function onBeforePageDisplay_resourceLoaderModuleNotAddedProvider() {
		return [
			[ self::getTitleForId( new NumericPropertyId( 'P1' ) ) ],
			[ Title::makeTitle( NS_HELP, 'Contents' ) ],
			[ null ]
		];
	}

	private static function getTitleForId( EntityId $entityId ) {
		$lookup = WikibaseRepo::getEntityTitleLookup();
		return $lookup->getTitleForId( $entityId );
	}

	private function getContext( Title $title = null ) {
		$context = RequestContext::getMain();
		$context->setTitle( $title );

		return $context;
	}

}
