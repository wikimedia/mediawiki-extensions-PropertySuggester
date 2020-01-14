<?php

namespace PropertySuggester;

use InvalidArgumentException;
use MediaWikiUnitTestCase;

/**
 * @covers \PropertySuggester\SuggesterParams
 * @covers \PropertySuggester\SuggesterParamsParser
 *
 * @group PropertySuggester
 * @group API
 * @group medium
 */
class SuggesterParamsParserTest extends MediaWikiUnitTestCase {

	/**
	 * @var SuggesterParamsParser
	 */
	private $paramsParser;

	private $defaultSuggesterResultSize = 100;
	private $defaultMinProbability = 0.01;
	private $defaultParams = [
		'entity' => null,
		'properties' => null,
		'continue' => 10,
		'limit' => 5,
		'language' => 'en',
		'search' => '',
		'context' => 'item',
		'include' => '',
	];

	public function setUp() : void {
		parent::setUp();
		$this->paramsParser = new SuggesterParamsParser(
			$this->defaultSuggesterResultSize,
			$this->defaultMinProbability
		);
	}

	public function testSuggesterParameters() {
		$params = $this->paramsParser->parseAndValidate(
			array_merge( $this->defaultParams, [ 'entity' => 'Q1', 'search' => '*' ] )
		);

		$this->assertEquals( 'Q1', $params->entity );
		$this->assertNull( $params->properties );
		$this->assertEquals( 'en', $params->language );
		$this->assertEquals( 10, $params->continue );
		$this->assertEquals( 5, $params->limit );
		$this->assertEquals( 5 + 10, $params->suggesterLimit );
		$this->assertEquals( $this->defaultMinProbability, $params->minProbability );
		$this->assertSame( '', $params->search );
		$this->assertEquals( 'item', $params->context );
		$this->assertSame( '', $params->include );
	}

	public function testSuggesterWithSearchParameters() {
		$params = $this->paramsParser->parseAndValidate(
			array_merge( $this->defaultParams, [ 'properties' => [ 'P31' ], 'search' => 'asd' ] )
		);

		$this->assertNull( $params->entity );
		$this->assertEquals( [ 'P31' ], $params->properties );
		$this->assertEquals( 'en', $params->language );
		$this->assertEquals( 10, $params->continue );
		$this->assertEquals( 5, $params->limit );
		$this->assertEquals( $this->defaultSuggesterResultSize, $params->suggesterLimit );
		$this->assertSame( 0.0, $params->minProbability );
		$this->assertEquals( 'asd', $params->search );
		$this->assertEquals( 'item', $params->context );
		$this->assertSame( '', $params->include );
	}

	public function testSuggestionWithoutEntityOrProperties() {
		$this->expectException( InvalidArgumentException::class );
		$this->paramsParser->parseAndValidate(
			[ 'entity' => null, 'properties' => null ]
		);
	}

	public function testSuggestionWithEntityAndProperties() {
		$this->expectException( InvalidArgumentException::class );
		$this->paramsParser->parseAndValidate(
			[ 'entity' => 'Q1', 'properties' => [ 'P31' ] ]
		);
	}

	public function testSuggestionWithNonNumericContinue() {
		$this->expectException( InvalidArgumentException::class );
		$this->paramsParser->parseAndValidate(
			[ 'entity' => 'Q1', 'properties' => null, 'continue' => 'drop' ]
		);
	}

}
