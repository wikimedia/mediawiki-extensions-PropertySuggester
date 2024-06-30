<?php

namespace PropertySuggester\Suggesters;

use InvalidArgumentException;
use MediaWikiIntegrationTestCase;
use PropertySuggester\EventLogger;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Snak\PropertySomeValueSnak;
use Wikimedia\Rdbms\LoadBalancerSingle;

/**
 * @covers \PropertySuggester\Suggesters\SimpleSuggester
 * @covers \PropertySuggester\Suggesters\Suggestion
 *
 * @group PropertySuggester
 * @group API
 * @group Database
 * @group medium
 */
class SimpleSuggesterTest extends MediaWikiIntegrationTestCase {

	/**
	 * @var SimpleSuggester
	 */
	private $suggester;

	private function row( $pid1, $qid1, $pid2, $count, $probability, $context ) {
		return [
			'pid1' => $pid1,
			'qid1' => $qid1,
			'pid2' => $pid2,
			'count' => $count,
			'probability' => $probability,
			'context' => $context
		];
	}

	public function addDBData() {
		$rows = [
			$this->row( 1, 0, 2, 100, 0.1, 'item' ),
			$this->row( 1, 0, 3, 50, 0.05, 'item' ),
			$this->row( 2, 0, 3, 100, 0.3, 'item' ),
			$this->row( 2, 0, 4, 200, 0.2, 'item' ),
			$this->row( 3, 0, 1, 100, 0.5, 'item' ),
		];

		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'wbs_propertypairs' )
			->rows( $rows )
			->caller( __METHOD__ )
			->execute();
	}

	public function setUp(): void {
		parent::setUp();

		$lb = new LoadBalancerSingle( [ 'connection' => $this->db ] );
		$this->suggester = new SimpleSuggester( $lb );
		$this->suggester->setEventLogger( $this->createMock( EventLogger::class ) );
	}

	public function testDatabaseHasRows() {
		$res = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'pid1', 'pid2' ] )
			->from( 'wbs_propertypairs' )
			->caller( __METHOD__ )
			->fetchResultSet();
		$this->assertEquals( 5, $res->numRows() );
	}

	public function testSuggestByPropertyIds() {
		$ids = [ new NumericPropertyId( 'p1' ) ];

		$res = $this->suggester->suggestByPropertyIds(
			$ids,
			[],
			100,
			0.0,
			'item',
			SuggesterEngine::SUGGEST_NEW
		);

		$this->assertEquals( new NumericPropertyId( 'p2' ), $res[0]->getPropertyId() );
		$this->assertEqualsWithDelta( 0.1, $res[0]->getProbability(), 0.0001 );
		$this->assertEquals( new NumericPropertyId( 'p3' ), $res[1]->getPropertyId() );
		$this->assertEqualsWithDelta( 0.05, $res[1]->getProbability(), 0.0001 );
	}

	public function testSuggestByPropertyIdsAll() {
		$ids = [ new NumericPropertyId( 'P1' ), new NumericPropertyId( 'P3' ) ];

		$res = $this->suggester->suggestByPropertyIds(
			$ids,
			[],
			100,
			0.0,
			'item',
			SuggesterEngine::SUGGEST_ALL
		);

		$this->assertEquals( new NumericPropertyId( 'P1' ), $res[0]->getPropertyId() );
		$this->assertEqualsWithDelta( 0.25, $res[0]->getProbability(), 0.0001 );
		$this->assertEquals( new NumericPropertyId( 'P2' ), $res[1]->getPropertyId() );
		$this->assertEqualsWithDelta( 0.05, $res[1]->getProbability(), 0.0001 );
		$this->assertEquals( new NumericPropertyId( 'P3' ), $res[2]->getPropertyId() );
		$this->assertEqualsWithDelta( 0.025, $res[2]->getProbability(), 0.0001 );
	}

	public function testSuggestByItem() {
		$item = new Item( new ItemId( 'Q42' ) );
		$snak = new PropertySomeValueSnak( new NumericPropertyId( 'P1' ) );
		$guid = 'claim0';
		$item->getStatements()->addNewStatement( $snak, null, null, $guid );

		$res = $this->suggester->suggestByItem(
			$item,
			100,
			0.0,
			'item',
			SuggesterEngine::SUGGEST_NEW
		);

		$this->assertEquals( new NumericPropertyId( 'p2' ), $res[0]->getPropertyId() );
		$this->assertEquals( new NumericPropertyId( 'p3' ), $res[1]->getPropertyId() );
	}

	public function testSuggestByItemAll() {
		$item = new Item( new ItemId( 'Q42' ) );
		$snak = new PropertySomeValueSnak( new NumericPropertyId( 'P1' ) );
		$item->getStatements()->addNewStatement( $snak, null, null, 'claim0' );
		$snak = new PropertySomeValueSnak( new NumericPropertyId( 'P3' ) );
		$item->getStatements()->addNewStatement( $snak, null, null, 'claim1' );

		// Make sure even deprecated properties are included
		$suggester = clone $this->suggester;
		$suggester->setDeprecatedPropertyIds( [ 2 ] );

		$res = $suggester->suggestByItem(
			$item,
			100,
			0.0,
			'item',
			SuggesterEngine::SUGGEST_ALL
		);

		$this->assertEquals( new NumericPropertyId( 'P1' ), $res[0]->getPropertyId() );
		$this->assertEquals( new NumericPropertyId( 'P2' ), $res[1]->getPropertyId() );
		$this->assertEquals( new NumericPropertyId( 'P3' ), $res[2]->getPropertyId() );
	}

	public function testDeprecatedProperties() {
		$ids = [ new NumericPropertyId( 'p1' ) ];

		$this->suggester->setDeprecatedPropertyIds( [ 2 ] );

		$res = $this->suggester->suggestByPropertyIds(
			$ids,
			[],
			100,
			0.0,
			'item',
			SuggesterEngine::SUGGEST_NEW
		);

		$resultIds = array_map( static function ( Suggestion $r ) {
			return $r->getPropertyId()->getNumericId();
		}, $res );
		$this->assertNotContains( 2, $resultIds );
		$this->assertContains( 3, $resultIds );
	}

	public function testEmptyResult() {
		$this->assertSame(
			[],
			$this->suggester->suggestByPropertyIds(
				[],
				[],
				10,
				0.01,
				'item',
				SuggesterEngine::SUGGEST_NEW
			)
		);
	}

	public function testInitialSuggestionsResult() {
		$this->suggester->setInitialSuggestions( [ 42 ] );
		$this->assertEquals(
			[ new Suggestion( new NumericPropertyId( 'P42' ), 1.0 ) ],
			$this->suggester->suggestByPropertyIds(
				[],
				[],
				10,
				0.01,
				'item',
				SuggesterEngine::SUGGEST_NEW
			)
		);
	}

	public function testInvalidLimit() {
		$this->expectException( InvalidArgumentException::class );
		$this->suggester->suggestByPropertyIds(
			[],
			[],
			'10',
			0.01,
			'item',
			SuggesterEngine::SUGGEST_NEW
		);
	}

	public function testInvalidMinProbability() {
		$this->expectException( InvalidArgumentException::class );
		$this->suggester->suggestByPropertyIds(
			[],
			[],
			10,
			'0.01',
			'item',
			SuggesterEngine::SUGGEST_NEW
		);
	}

}
