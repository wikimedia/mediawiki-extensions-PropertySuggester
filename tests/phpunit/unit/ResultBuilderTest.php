<?php

namespace PropertySuggester;

use ApiResult;
use MediaWikiUnitTestCase;
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
	 * @var ResultBuilder
	 */
	private $resultBuilder;

	public function setUp() : void {
		parent::setUp();

		$entityTitleLookup = $this->getMockBuilder( EntityTitleLookup::class )->getMock();
		$termBuffer = $this->createMock( TermBuffer::class );
		$result = new ApiResult( false );

		$this->resultBuilder = new ResultBuilder( $result, $termBuffer, $entityTitleLookup, '' );
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

		$mergedResult = $this->resultBuilder->mergeWithTraditionalSearchResults(
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

}
