<?php

namespace PropertySuggester;

use MediaWikiTestCase;
use MockHttpTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PropertySuggester\Suggesters\SchemaTreeSuggester;
use PropertySuggester\Suggesters\SuggesterEngine;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Snak\PropertySomeValueSnak;

/**
 * @covers \PropertySuggester\Suggesters\SchemaTreeSuggester
 *
 * @group PropertySuggester
 * @group API
 * @group medium
 */
class SchemaTreeSuggesterTest extends MediaWikiTestCase {
	use MockHttpTrait;

	/**
	 * @var SchemaTreeSuggester
	 */
	private $suggester;

	/**
	 * @var EventLogger|MockObject
	 */
	private $eventLogger;

	public function setUp() : void {
		$response = json_encode( [
			'recommendations' => [ [ 'property' => '/prop/direct/P2', 'probability' => 0.1 ],
				[ 'property' => '/prop/direct/P3', 'probability' => 0.05 ],
				[ 'property' => '/prop/direct/P4', 'probability' => 0.25 ] ]
		] );

		$this->eventLogger = $this->createMock( EventLogger::class );

		$this->suggester = new SchemaTreeSuggester( $this->makeMockHttpRequestFactory( $response ) );

		$this->suggester->setEventLogger( $this->eventLogger );
		$this->suggester->setSchemaTreeSuggesterUrl( 'mockURL' );
		$this->suggester->setPropertyBaseUrl( '/prop/direct/' );
		$this->suggester->setTypesBaseUrl( '/entity/' );
	}

	public function testSuggestByPropertyIds() {
		$ids = [ new PropertyId( 'P1' ) ];

		$res = $this->suggester->suggestByPropertyIds(
			$ids,
			[],
			100,
			0.0,
			'item',
			SuggesterEngine::SUGGEST_NEW
		);

		$this->assertEquals( new PropertyId( 'P2' ), $res[0]->getPropertyId() );
		$this->assertEquals( 0.1, $res[0]->getProbability() );
		$this->assertEquals( new PropertyId( 'P3' ), $res[1]->getPropertyId() );
		$this->assertEquals( 0.05, $res[1]->getProbability() );
		$this->assertEquals( new PropertyId( 'P4' ), $res[2]->getPropertyId() );
		$this->assertEquals( 0.25, $res[2]->getProbability() );
	}

	public function testSuggestByItemId() {
		$item = new Item( new ItemId( 'Q42' ) );
		$snak = new PropertySomeValueSnak( new PropertyId( 'P1' ) );
		$item->getStatements()->addNewStatement( $snak, null, null, 'claim0' );
		$snak = new PropertySomeValueSnak( new PropertyId( 'P3' ) );
		$item->getStatements()->addNewStatement( $snak, null, null, 'claim1' );

		$res = $this->suggester->suggestByItem(
			$item,
			100,
			0.0,
			'item',
			SuggesterEngine::SUGGEST_ALL
		);

		$this->assertEquals( new PropertyId( 'P2' ), $res[0]->getPropertyId() );
		$this->assertEquals( new PropertyId( 'P3' ), $res[1]->getPropertyId() );
		$this->assertEquals( new PropertyId( 'P4' ), $res[2]->getPropertyId() );
	}

	public function testDeprecatedProperties() {
		$this->suggester->setDeprecatedPropertyIds( [ 2 ] );
		$ids = [ new PropertyId( 'P1' ) ];

		$res = $this->suggester->suggestByPropertyIds(
			$ids,
			[],
			100,
			0.0,
			'item',
			SuggesterEngine::SUGGEST_NEW
		);

		$this->assertEquals( new PropertyId( 'P3' ), $res[0]->getPropertyId() );
		$this->assertEquals( new PropertyId( 'P4' ), $res[1]->getPropertyId() );
	}

	public function testMinProbability() {
		$this->suggester->setDeprecatedPropertyIds( [ 2 ] );
		$ids = [ new PropertyId( 'P1' ) ];

		$res = $this->suggester->suggestByPropertyIds(
			$ids,
			[],
			100,
			0.1,
			'item',
			SuggesterEngine::SUGGEST_NEW
		);

		$this->assertEquals( new PropertyId( 'P4' ), $res[0]->getPropertyId() );
		$this->assertEquals( 0.25, $res[0]->getProbability() );
	}

	public function testReturnOnlyProperties() {
		$response = json_encode( [
			'recommendations' => [ [ 'property' => '/prop/direct/P2', 'probability' => 0.1 ],
				[ 'property' => '/entity/Q3', 'probability' => 0.05 ],
				[ 'property' => '/prop/direct/P4', 'probability' => 0.25 ] ]
		] );

		$this->suggester = new SchemaTreeSuggester( $this->makeMockHttpRequestFactory( $response ) );
		$this->suggester->setPropertyBaseUrl( '/prop/direct/' );
		$this->suggester->setTypesBaseUrl( '/entity/' );
		$this->suggester->setEventLogger( $this->eventLogger );

		$ids = [ new PropertyId( 'P1' ) ];

		$res = $this->suggester->suggestByPropertyIds(
			$ids,
			[],
			100,
			0.0,
			'item',
			SuggesterEngine::SUGGEST_NEW
		);

		$this->assertEquals( new PropertyId( 'P2' ), $res[0]->getPropertyId() );
		$this->assertEquals( new PropertyId( 'P4' ), $res[1]->getPropertyId() );
	}

	public function testResponseIsNotArray() {
		$response = json_encode( [ 'recommendations' => 'Incorrect response' ] );

		$this->suggester = new SchemaTreeSuggester( $this->makeMockHttpRequestFactory( $response ) );
		$this->suggester->setEventLogger( $this->eventLogger );

		$ids = [ new PropertyId( 'P1' ) ];

		$res = $this->suggester->suggestByPropertyIds(
			$ids,
			[],
			100,
			0.0,
			'item',
			SuggesterEngine::SUGGEST_NEW
		);

		$this->assertNull( $res );
	}

}
