<?php

namespace PropertySuggester;

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
		'types' => null,
		'continue' => 10,
		'limit' => 5,
		'language' => 'en',
		'search' => '',
		'context' => 'item',
		'include' => '',
		'event' => '',
	];

	public function setUp() : void {
		parent::setUp();
		$this->paramsParser = new SuggesterParamsParser(
			$this->defaultSuggesterResultSize,
			$this->defaultMinProbability
		);
	}

	public function testSuggesterParameters() {
		$paramsStatus = $this->paramsParser->parseAndValidate(
			array_merge( $this->defaultParams, [ 'entity' => 'Q1', 'search' => '*' ] )
		);
		$this->assertTrue( $paramsStatus->isGood() );
		$params = $paramsStatus->getValue();

		$this->assertEquals( 'Q1', $params->entity );
		$this->assertNull( $params->properties );
		$this->assertNull( $params->types );
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
		$paramsStatus = $this->paramsParser->parseAndValidate(
			array_merge( $this->defaultParams, [ 'properties' => [ 'P31' ], 'search' => 'asd' ] )
		);
		$this->assertTrue( $paramsStatus->isGood() );
		$params = $paramsStatus->getValue();

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
		$paramsStatus = $this->paramsParser->parseAndValidate(
			[ 'entity' => null, 'properties' => null ]
		);
		$this->assertFalse( $paramsStatus->isGood() );
		$this->assertSame( 'propertysuggester-wbsgetsuggestions-either-entity-or-properties',
			$paramsStatus->getErrors()[0]['message'] );
	}

	public function testSuggestionWithEntityAndProperties() {
		$paramsStatus = $this->paramsParser->parseAndValidate(
			[ 'entity' => 'Q1', 'properties' => [ 'P31' ] ]
		);
		$this->assertFalse( $paramsStatus->isGood() );
		$this->assertSame( 'propertysuggester-wbsgetsuggestions-either-entity-or-properties',
			$paramsStatus->getErrors()[0]['message'] );
	}

}
