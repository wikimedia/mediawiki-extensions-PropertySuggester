<?php

namespace PropertySuggester;

use MediaWikiIntegrationTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PropertySuggester\Suggesters\SuggesterEngine;
use PropertySuggester\Suggesters\Suggestion;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\DataModel\Snak\PropertySomeValueSnak;
use Wikibase\DataModel\Term\Term;
use Wikibase\Lib\Interactors\TermSearchResult;
use Wikibase\Repo\Api\EntitySearchHelper;

/**
 * @covers \PropertySuggester\SuggestionGenerator
 *
 * @group PropertySuggester
 * @group API
 * @group medium
 */
class SuggestionGeneratorTest extends MediaWikiIntegrationTestCase {

	/**
	 * @var SuggestionGenerator
	 */
	private $suggestionGenerator;

	/**
	 * @var SuggesterEngine|MockObject
	 */
	private $suggester;

	/**
	 * @var SuggesterEngine|MockObject
	 */
	private $fallbackSuggester;

	/**
	 * @var EntityLookup|MockObject
	 */
	private $lookup;

	/**
	 * @var EntitySearchHelper|MockObject
	 */
	private $entitySearchHelper;

	public function setUp(): void {
		parent::setUp();

		$this->lookup = $this->createMock( EntityLookup::class );
		$this->entitySearchHelper = $this->createMock( EntitySearchHelper::class );
		$this->suggester = $this->createMock( SuggesterEngine::class );
		$this->fallbackSuggester = $this->createMock( SuggesterEngine::class );

		$this->suggestionGenerator = new SuggestionGenerator(
			$this->lookup,
			$this->entitySearchHelper,
			$this->suggester,
			$this->fallbackSuggester
		);
	}

	public function testFilterSuggestions() {
		$p7 = new NumericPropertyId( 'P7' );
		$p10 = new NumericPropertyId( 'P10' );
		$p12 = new NumericPropertyId( 'P12' );
		$p15 = new NumericPropertyId( 'P15' );
		$p23 = new NumericPropertyId( 'P23' );

		$suggestions = [
			new Suggestion( $p12, 0.9 ), // this will stay at pos 0
			new Suggestion( $p23, 0.8 ), // this doesn't match
			new Suggestion( $p7, 0.7 ), // this will go to pos 1
			new Suggestion( $p15, 0.6 ) // this is outside of resultSize
		];

		$resultSize = 2;

		$this->entitySearchHelper->expects( $this->any() )
			->method( 'getRankedSearchResults' )
			->willReturn(
				$this->getTermSearchResultArrayWithIds( [ $p7, $p10, $p15, $p12 ] )
			);

		$result = $this->suggestionGenerator->filterSuggestions(
			$suggestions,
			'foo',
			'en',
			$resultSize
		);

		$this->assertEquals( [ $suggestions[0], $suggestions[2] ], $result );
	}

	/**
	 * @param NumericPropertyId[] $ids
	 *
	 * @return TermSearchResult[]
	 */
	private function getTermSearchResultArrayWithIds( $ids ) {
		$termSearchResults = [];
		foreach ( $ids as $i => $id ) {
			$termSearchResults[] = new TermSearchResult(
				new Term( "kitten$i", 'en' ),
				'label',
				$id,
				new Term( "kitten$i", 'en' ),
				null
			);
		}
		return $termSearchResults;
	}

	public function testFilterSuggestionsWithoutSearch() {
		$resultSize = 2;

		$result = $this->suggestionGenerator->filterSuggestions(
			[ 1, 2, 3, 4 ],
			'',
			'en',
			$resultSize
		);

		$this->assertEquals( [ 1, 2 ], $result );
	}

	public function testGenerateSuggestionsWithPropertyList() {
		$properties = [
			new NumericPropertyId( 'P12' ),
			new NumericPropertyId( 'P13' ),
			new NumericPropertyId( 'P14' ),
		];

		$this->suggester->expects( $this->any() )
			->method( 'suggestByPropertyIds' )
			->with( $properties )
			->willReturn( [ 'foo' ] );

		$result1 = $this->suggestionGenerator->generateSuggestionsByPropertyList(
			[ 'P12', 'p13', 'P14' ],
			[],
			100,
			0.0,
			'item',
			SuggesterEngine::SUGGEST_NEW
		);
		$this->assertTrue( $result1->isGood() );
		$this->assertEquals( [ 'foo' ], $result1->getValue() );
	}

	public function testGenerateSuggestionsWithItem() {
		$itemId = new ItemId( 'Q42' );
		$item = new Item( $itemId );
		$snak = new PropertySomeValueSnak( new NumericPropertyId( 'P12' ) );
		$guid = 'claim0';
		$item->getStatements()->addNewStatement( $snak, null, null, $guid );

		$this->lookup->expects( $this->once() )
			->method( 'getEntity' )
			->with( $itemId )
			->willReturn( $item );

		$this->suggester->expects( $this->any() )
			->method( 'suggestByItem' )
			->with( $item )
			->willReturn( [ 'foo' ] );

		$result3 = $this->suggestionGenerator->generateSuggestionsByItem(
			'Q42',
			100,
			0.0,
			'item',
			SuggesterEngine::SUGGEST_NEW
		);

		$this->assertTrue( $result3->isGood() );
		$this->assertEquals( [ 'foo' ], $result3->getValue() );
	}

	public function testGenerateSuggestionsWithNonExistentItem() {
		$itemId = new ItemId( 'Q41' );

		$this->lookup->expects( $this->once() )
			->method( 'getEntity' )
			->with( $itemId )
			->willReturn( null );

		$result = $this->suggestionGenerator->generateSuggestionsByItem(
			'Q41',
			100,
			0.0,
			'item',
			SuggesterEngine::SUGGEST_NEW
		);
		$this->assertFalse( $result->isGood() );
		$this->assertSame( 'wikibase-api-no-such-entity',
			$result->getErrors()[0]['message'] );
	}

	public function testFallbackBehaviourByItem() {
		$itemId = new ItemId( 'Q42' );
		$item = new Item( $itemId );
		$snak = new PropertySomeValueSnak( new NumericPropertyId( 'P12' ) );
		$guid = 'claim0';
		$item->getStatements()->addNewStatement( $snak, null, null, $guid );

		$this->lookup->expects( $this->once() )
			->method( 'getEntity' )
			->with( $itemId )
			->willReturn( $item );

		$this->suggester->expects( $this->any() )
			->method( 'suggestByItem' )
			->with( $item )
			->willReturn( null );

		$this->fallbackSuggester->expects( $this->any() )
			->method( 'suggestByItem' )
			->with( $item )
			->willReturn( [ 'foo' ] );

		$result4 = $this->suggestionGenerator->generateSuggestionsByItem(
			'Q42',
			100,
			0.0,
			'item',
			SuggesterEngine::SUGGEST_NEW
		);

		$this->assertTrue( $result4->isGood() );
		$this->assertEquals( [ 'foo' ], $result4->getValue() );
	}

	public function testFallbackBehaviourByPropertyIDs() {
		$properties = [
			new NumericPropertyId( 'P12' ),
			new NumericPropertyId( 'P13' ),
			new NumericPropertyId( 'P14' ),
		];

		$this->suggester->expects( $this->any() )
			->method( 'suggestByPropertyIds' )
			->with( $properties )
			->willReturn( null );

		$this->fallbackSuggester->expects( $this->any() )
			->method( 'suggestByPropertyIds' )
			->with( $properties )
			->willReturn( [ 'foo' ] );

		$result5 = $this->suggestionGenerator->generateSuggestionsByPropertyList(
			[ 'P12', 'p13', 'P14' ],
			[],
			100,
			0.0,
			'item',
			SuggesterEngine::SUGGEST_NEW
		);
		$this->assertTrue( $result5->isGood() );
		$this->assertEquals( [ 'foo' ], $result5->getValue() );
	}

}
