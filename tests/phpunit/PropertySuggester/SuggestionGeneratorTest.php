<?php

namespace PropertySuggester;

use MediaWikiTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PropertySuggester\Suggesters\SuggesterEngine;
use PropertySuggester\Suggesters\Suggestion;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
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
class SuggestionGeneratorTest extends MediaWikiTestCase {

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
		$p7 = new PropertyId( 'P7' );
		$p10 = new PropertyId( 'P10' );
		$p12 = new PropertyId( 'P12' );
		$p15 = new PropertyId( 'P15' );
		$p23 = new PropertyId( 'P23' );

		$suggestions = [
			new Suggestion( $p12, 0.9 ), // this will stay at pos 0
			new Suggestion( $p23, 0.8 ), // this doesn't match
			new Suggestion( $p7, 0.7 ), // this will go to pos 1
			new Suggestion( $p15, 0.6 ) // this is outside of resultSize
		];

		$resultSize = 2;

		$this->entitySearchHelper->expects( $this->any() )
			->method( 'getRankedSearchResults' )
			->will( $this->returnValue(
				$this->getTermSearchResultArrayWithIds( [ $p7, $p10, $p15, $p12 ] )
			) );

		$result = $this->suggestionGenerator->filterSuggestions(
			$suggestions,
			'foo',
			'en',
			$resultSize
		);

		$this->assertEquals( [ $suggestions[0], $suggestions[2] ], $result );
	}

	/**
	 * @param PropertyId[] $ids
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
			new PropertyId( 'P12' ),
			new PropertyId( 'P13' ),
			new PropertyId( 'P14' ),
		];

		$this->suggester->expects( $this->any() )
			->method( 'suggestByPropertyIds' )
			->with( $this->equalTo( $properties ) )
			->will( $this->returnValue( [ 'foo' ] ) );

		$result1 = $this->suggestionGenerator->generateSuggestionsByPropertyList(
			[ 'P12', 'p13', 'P14' ],
			[],
			100,
			0.0,
			'item',
			SuggesterEngine::SUGGEST_NEW
		);
		$this->assertTrue( $result1->isGood() );
		$this->assertEquals( $result1->getValue(), [ 'foo' ] );
	}

	public function testGenerateSuggestionsWithItem() {
		$itemId = new ItemId( 'Q42' );
		$item = new Item( $itemId );
		$snak = new PropertySomeValueSnak( new PropertyId( 'P12' ) );
		$guid = 'claim0';
		$item->getStatements()->addNewStatement( $snak, null, null, $guid );

		$this->lookup->expects( $this->once() )
			->method( 'getEntity' )
			->with( $this->equalTo( $itemId ) )
			->will( $this->returnValue( $item ) );

		$this->suggester->expects( $this->any() )
			->method( 'suggestByItem' )
			->with( $this->equalTo( $item ) )
			->will( $this->returnValue( [ 'foo' ] ) );

		$result3 = $this->suggestionGenerator->generateSuggestionsByItem(
			'Q42',
			100,
			0.0,
			'item',
			SuggesterEngine::SUGGEST_NEW
		);

		$this->assertTrue( $result3->isGood() );
		$this->assertEquals( $result3->getValue(), [ 'foo' ] );
	}

	public function testGenerateSuggestionsWithNonExistentItem() {
		$itemId = new ItemId( 'Q41' );

		$this->lookup->expects( $this->once() )
			->method( 'getEntity' )
			->with( $this->equalTo( $itemId ) )
			->will( $this->returnValue( null ) );

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
		$snak = new PropertySomeValueSnak( new PropertyId( 'P12' ) );
		$guid = 'claim0';
		$item->getStatements()->addNewStatement( $snak, null, null, $guid );

		$this->lookup->expects( $this->once() )
			->method( 'getEntity' )
			->with( $this->equalTo( $itemId ) )
			->will( $this->returnValue( $item ) );

		$this->suggester->expects( $this->any() )
			->method( 'suggestByItem' )
			->with( $this->equalTo( $item ) )
			->will( $this->returnValue( null ) );

		$this->fallbackSuggester->expects( $this->any() )
			->method( 'suggestByItem' )
			->with( $this->equalTo( $item ) )
			->will( $this->returnValue( [ 'foo' ] ) );

		$result4 = $this->suggestionGenerator->generateSuggestionsByItem(
			'Q42',
			100,
			0.0,
			'item',
			SuggesterEngine::SUGGEST_NEW
		);

		$this->assertTrue( $result4->isGood() );
		$this->assertEquals( $result4->getValue(), [ 'foo' ] );
	}

	public function testFallbackBehaviourByPropertyIDs() {
		$properties = [
			new PropertyId( 'P12' ),
			new PropertyId( 'P13' ),
			new PropertyId( 'P14' ),
		];

		$this->suggester->expects( $this->any() )
			->method( 'suggestByPropertyIds' )
			->with( $this->equalTo( $properties ) )
			->will( $this->returnValue( null ) );

		$this->fallbackSuggester->expects( $this->any() )
			->method( 'suggestByPropertyIds' )
			->with( $this->equalTo( $properties ) )
			->will( $this->returnValue( [ 'foo' ] ) );

		$result5 = $this->suggestionGenerator->generateSuggestionsByPropertyList(
			[ 'P12', 'p13', 'P14' ],
			[],
			100,
			0.0,
			'item',
			SuggesterEngine::SUGGEST_NEW
		);
		$this->assertTrue( $result5->isGood() );
		$this->assertEquals( $result5->getValue(), [ 'foo' ] );
	}

}
