<?php

namespace PropertySuggester;

use ApiMain;
use ApiUsageException;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityRedirect;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\Property;
use Wikibase\Repo\Api\EntitySearchException;
use Wikibase\Repo\Api\EntitySearchHelper;
use Wikibase\Repo\Tests\Api\WikibaseApiTestCase;
use Wikibase\Repo\WikibaseRepo;

/**
 * @covers \PropertySuggester\GetSuggestions
 * @covers \PropertySuggester\ResultBuilder
 *
 * @group PropertySuggester
 * @group API
 * @group Wikibase
 * @group WikibaseAPI
 * @group BreakingTheSlownessBarrier
 * @group Database
 * @group medium
 */
class GetSuggestionsTest extends WikibaseApiTestCase {
	/** @var EntityId[] */
	private static $idMap;

	/** @var bool */
	private static $hasSetup;

	/** @var GetSuggestions */
	public $getSuggestions;

	/** @var bool */
	private $simulateBackendFailure;

	protected function setUp(): void {
		parent::setUp();
		$this->simulateBackendFailure = false;

		$this->tablesUsed[] = 'wbs_propertypairs';
		$this->tablesUsed[] = 'redirect';

		$apiMain = $this->createMock( ApiMain::class );
		$apiMain->method( 'getContext' )->willReturn( new \RequestContext() );
		$apiMain->method( 'getRequest' )->willReturn( new \FauxRequest() );

		$this->getServiceContainer()->addServiceManipulator( 'WikibaseRepo.EntitySearchHelper',
			function ( EntitySearchHelper $entitySearchHelper ) {
				$entitySearchHelperMock = $this->createMock( EntitySearchHelper::class );
				$entitySearchHelperMock->method( 'getRankedSearchResults' )
					->willReturnCallback(
						function ( ...$args ) use ( $entitySearchHelper ) {
							if ( $this->simulateBackendFailure ) {
								throw new EntitySearchException( \Status::newFatal( 'search-backend-error' ) );
							} else {
								return $entitySearchHelper->getRankedSearchResults( ...$args );
							}
						}
					);
				return $entitySearchHelperMock;
			}
		);
		$this->getSuggestions = new GetSuggestions( $apiMain, 'wbgetsuggestion' );
	}

	public function addDBData() {
		if ( !self::$hasSetup ) {
			$store = WikibaseRepo::getEntityStore();

			$item = new Item( new ItemId( "Q1" ) );
			$item->setLabel( "en", "asdf" );

			$item2 = new Item( new ItemId( "Q2" ) );
			$item2->setLabel( "de", "asdf" );

			$item3 = new Item( new ItemId( "Q3" ) );
			$item3->setLabel( "sv", "asdf" );

			$editor = $this->getTestUser()->getUser();

			$redirect = new EntityRedirect( $item->getId(), $item2->getId() );
			$store->saveRedirect( $redirect, "RedirectNewItem1->Item2", $editor, EDIT_NEW, false );

			$redirect2 = new EntityRedirect( $item2->getId(), $item3->getId() );
			$store->saveRedirect( $redirect2, "RedirectNewItem1->Item2", $editor, EDIT_NEW, false );

			$prop = Property::newFromType( 'string' );
			$store->saveEntity( $prop, 'EditEntityTestP56', $editor, EDIT_NEW );
			self::$idMap['%P56%'] = $prop->getId()->getSerialization();

			$prop = Property::newFromType( 'string' );
			$store->saveEntity( $prop, 'EditEntityTestP72', $editor, EDIT_NEW );
			self::$idMap['%P72%'] = $prop->getId()->getSerialization();

			self::$hasSetup = true;
		}

		$p56 = self::$idMap['%P56%'];
		$p72 = self::$idMap['%P72%'];
		$ip56 = (int)substr( $p56, 1 );
		$ip72 = (int)substr( $p72, 1 );

		$row = [
			'pid1' => $ip56,
			'qid1' => 0,
			'pid2' => $ip72,
			'count' => 1,
			'probability' => 0.3,
			'context' => 'item',
		];

		$this->db->insert( 'wbs_propertypairs', [ $row ] );
	}

	public function testDatabaseHasRows() {
		$p56 = self::$idMap['%P56%'];
		$p72 = self::$idMap['%P72%'];
		$ip56 = (int)substr( $p56, 1 );
		$ip72 = (int)substr( $p72, 1 );

		$res = $this->db->select(
			'wbs_propertypairs',
			[ 'pid1', 'pid2' ],
			[ 'pid1' => $ip56, 'pid2' => $ip72 ]
		);
		$this->assertSame( 1, $res->numRows() );
	}

	public function testExecution() {
		$p56 = self::$idMap['%P56%'];
		$p72 = self::$idMap['%P72%'];

		$params = [
			'action' => 'wbsgetsuggestions',
			'properties' => $p56,
			'search' => '*',
			'context' => 'item'
		];
		$res = $this->doApiRequest( $params );
		$result = $res[0];

		$this->assertSame( 1, $result['success'] );
		$this->assertSame( '', $result['searchinfo']['search'] );
		$this->assertCount( 1, $result['search'] );
		$suggestions = $result['search'][0];
		$this->assertEquals( $p72, $suggestions['id'] );
	}

	public function testExecutionWithSearch() {
		$p56 = self::$idMap['%P56%'];

		$params = [
			'action' => 'wbsgetsuggestions',
			'properties' => $p56,
			'search' => 'IdontExist',
			'continue' => 0,
			'context' => 'item'
		];
		$res = $this->doApiRequest( $params );
		$result = $res[0];

		$this->assertSame( 1, $result['success'] );
		$this->assertEquals( 'IdontExist', $result['searchinfo']['search'] );
		$this->assertCount( 0, $result['search'] );
	}

	public function testExecutionWithSearchBackendFailure() {
		$p56 = self::$idMap['%P56%'];

		$params = [
			'action' => 'wbsgetsuggestions',
			'properties' => $p56,
			'search' => 'IdontExist',
			'continue' => 0,
			'context' => 'item'
		];
		try {
			$this->simulateBackendFailure = true;
			$this->doApiRequest( $params );
			$this->fail( "ApiUsageException should be thrown" );
		} catch ( ApiUsageException $aue ) {
			$this->assertTrue( $aue->getStatusValue()->hasMessage( 'search-backend-error' ) );
		}
	}

	public function provideExecutionWithInclude() {
		return [
			'include all' => [ 1, 'all' ],
			'include default' => [ 0, '' ],
		];
	}

	/**
	 * @dataProvider provideExecutionWithInclude
	 */
	public function testExecutionWithInclude( $expectedResultCount, $include ) {
		$p56 = self::$idMap['%P56%'];
		$p72 = self::$idMap['%P72%'];

		$params = [
			'action' => 'wbsgetsuggestions',
			'properties' => $p56 . '|' . $p72,
			'continue' => 0,
			'context' => 'item',
			'include' => $include
		];
		$res = $this->doApiRequest( $params );
		$result = $res[0];

		$this->assertSame( 1, $result['success'] );
		$this->assertSame( '', $result['searchinfo']['search'] );
		$this->assertCount( $expectedResultCount, $result['search'] );
	}

	public function testExecutionWithInvalidContext() {
		$p56 = self::$idMap['%P56%'];
		$params = [
			'action' => 'wbsgetsuggestions',
			'properties' => $p56,
			'context' => 'delete all the things!'
		];

		$this->expectException( ApiUsageException::class );
		$this->doApiRequest( $params );
	}

	public function testExecutionWithRedirect() {
		$expectedMessage = 'The given entity ID refers to a redirect, which is not supported in this context.';
		$params = [
			'action' => 'wbsgetsuggestions',
			'entity' => 'Q1',
		];

		$this->expectException( ApiUsageException::class );
		$this->expectExceptionMessage( $expectedMessage );
		$res = $this->doApiRequest( $params );
	}

	public function testGetAllowedParams() {
		$this->assertNotEmpty( $this->getSuggestions->getAllowedParams() );
	}

	public function testGetExamplesMessages() {
		$this->assertNotEmpty( $this->getSuggestions->getExamplesMessages() );
	}

}
