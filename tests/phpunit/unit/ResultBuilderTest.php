<?php

namespace PropertySuggester;

use ApiResult;
use MediaWikiUnitTestCase;
use PropertySuggester\Suggesters\Suggestion;
use Title;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Services\Term\TermBuffer;
use Wikibase\Lib\Store\EntityTitleLookup;

/**
 * @covers \PropertySuggester\ResultBuilder
 *
 * @group PropertySuggester
 * @group API
 * @group medium
 */
class ResultBuilderTest extends MediaWikiUnitTestCase {

	/**
	 * @var TermBuffer
	 */
	private $termBuffer;

	/**
	 * @var EntityTitleLookup
	 */
	private $titleLookup;

	public function setUp() : void {
		parent::setUp();

		$this->termBuffer = $this->createMock( TermBuffer::class );
		$this->titleLookup = $this->getMockForAbstractClass( EntityTitleLookup::class );
		$this->titleLookup->expects( $this->any() )
			->method( 'getTitleForId' )
			->willReturn( $this->createMock( Title::class ) );
	}

	private function newResultBuilder( $search ) {
		return new ResultBuilder(
			new ApiResult( false ),
			$this->termBuffer,
			$this->titleLookup,
			$search
		);
	}

	public function testMergeWithTraditionalSearchResults() {
		$suggesterResult = [
			[ 'id' => '8' ],
			[ 'id' => '14' ],
			[ 'id' => '20' ],
		];

		$searchResult = [
			[ 'id' => '7' ],
			[ 'id' => '8' ],
			[ 'id' => '13' ],
			[ 'id' => '14' ],
			[ 'id' => '15' ],
			[ 'id' => '16' ],
		];

		$mergedResult = $this->newResultBuilder( '' )->mergeWithTraditionalSearchResults(
			$suggesterResult,
			$searchResult,
			5
		);

		$expected = [
			[ 'id' => '8' ],
			[ 'id' => '14' ],
			[ 'id' => '20' ],
			[ 'id' => '7' ],
			[ 'id' => '13' ],
		];

		$this->assertEquals( $mergedResult, $expected );
	}

	public function testCreateResultsArray() {
		$language = 'en';
		$propertyId = new PropertyId( 'P123' );
		$label = 'is potato';
		$description = 'boolean potato check';
		$alias = 'isPotato';

		$this->termBuffer = $this->createMock( TermBuffer::class );
		$this->termBuffer->expects( $this->once() )
			->method( 'prefetchTerms' )
			->with(
				[ $propertyId ],
				[ 'label', 'description', 'alias' ],
				[ $language ]
			);
		$this->termBuffer->expects( $this->any() )
			->method( 'getPrefetchedTerm' )
			->withConsecutive(
				[ $propertyId, 'label', $language ],
				[ $propertyId, 'description', $language ],
				[ $propertyId, 'alias', $language ]
			)
			->willReturnOnConsecutiveCalls( $label, $description, $alias );

		$result = $this->newResultBuilder(
			'isPotat' // matching the alias to make it appear in the result
		)->createResultArray(
			[ new Suggestion( $propertyId, 1 ) ],
			$language
		);

		$this->assertSame( $label, $result[0]['label'] );
		$this->assertSame( $description, $result[0]['description'] );
		$this->assertSame( $alias, $result[0]['aliases'][0] );
	}

}
