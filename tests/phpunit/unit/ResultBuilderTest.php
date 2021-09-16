<?php

namespace PropertySuggester;

use ApiResult;
use MediaWikiUnitTestCase;
use PropertySuggester\Suggesters\Suggestion;
use Title;
use Wikibase\DataAccess\PrefetchingTermLookup;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\Lib\LanguageFallbackChainFactory;
use Wikibase\Lib\LanguageWithConversion;
use Wikibase\Lib\StaticContentLanguages;
use Wikibase\Lib\Store\EntityTitleLookup;
use Wikibase\Lib\TermLanguageFallbackChain;

/**
 * @covers \PropertySuggester\ResultBuilder
 *
 * @group PropertySuggester
 * @group API
 * @group medium
 */
class ResultBuilderTest extends MediaWikiUnitTestCase {

	/**
	 * @var EntityTitleLookup
	 */
	private $titleLookup;

	/**
	 * @var PrefetchingTermLookup
	 */
	private $prefetchingTermLookup;

	/**
	 * @var LanguageFallbackChainFactory
	 */
	private $languageFallbackChainFactory;

	public function setUp(): void {
		parent::setUp();

		$this->titleLookup = $this->createMock( EntityTitleLookup::class );
		$this->prefetchingTermLookup = $this->createMock( PrefetchingTermLookup::class );
		$this->languageFallbackChainFactory = $this->createMock( LanguageFallbackChainFactory::class );
		$this->titleLookup->expects( $this->any() )
			->method( 'getTitleForId' )
			->willReturn( $this->createMock( Title::class ) );
	}

	private function newResultBuilder( $search ) {
		return new ResultBuilder(
			new ApiResult( false ),
			$this->prefetchingTermLookup,
			$this->languageFallbackChainFactory,
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
		$propertyId = new NumericPropertyId( 'P123' );
		$label = 'is potato';
		$description = 'boolean potato check';
		$alias = 'isPotato';

		$this->prefetchingTermLookup->expects( $this->once() )
			->method( 'prefetchTerms' )
			->with(
				[ $propertyId ],
				[ 'label', 'description', 'alias' ],
				[ $language ]
			);
		$this->prefetchingTermLookup->method( 'getLabels' )
			->with( $propertyId )
			->willReturn( [ $language => $label ] );
		$this->prefetchingTermLookup->method( 'getDescriptions' )
			->with( $propertyId )
			->willReturn( [ $language => $description ] );
		$this->prefetchingTermLookup->method( 'getPrefetchedAliases' )
			->with( $propertyId, $language )
			->willReturn( [ $alias ] );

		$this->languageFallbackChainFactory->method( 'newFromLanguageCode' )
			->with( $language )
			->willReturn( new TermLanguageFallbackChain(
				[ $this->newLanguageWithConversion( $language ) ],
				new StaticContentLanguages( [ $language ] )
			) );

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

	public function testGivenMissingTermsForRequestedLanguage_createResultsArrayUsesFallback() {
		$language = 'en-gb';
		$fallbackLanguage = 'en';
		$propertyId = new NumericPropertyId( 'P123' );
		$fallbackLabel = 'is potato';
		$fallbackDescription = 'boolean potato check';

		$this->prefetchingTermLookup->expects( $this->once() )
			->method( 'prefetchTerms' )
			->with(
				[ $propertyId ],
				[ 'label', 'description', 'alias' ],
				[ $language, $fallbackLanguage ]
			);
		$this->prefetchingTermLookup->method( 'getLabels' )
			->with( $propertyId )
			->willReturn( [
				$language => null,
				$fallbackLanguage => $fallbackLabel
			] );
		$this->prefetchingTermLookup->method( 'getDescriptions' )
			->with( $propertyId )
			->willReturn( [
				$language => null,
				$fallbackLanguage => $fallbackDescription
			] );

		$this->languageFallbackChainFactory->method( 'newFromLanguageCode' )
			->with( $language )
			->willReturn( new TermLanguageFallbackChain( [
					$this->newLanguageWithConversion( $language ),
					$this->newLanguageWithConversion( $fallbackLanguage )
				], new StaticContentLanguages( [ $language, $fallbackLanguage ] ) ) );

		$result = $this->newResultBuilder(
			'is Potato'
		)->createResultArray(
			[ new Suggestion( $propertyId, 1 ) ],
			$language
		);

		$this->assertSame( $fallbackLabel, $result[0]['label'] );
		$this->assertSame( $fallbackDescription, $result[0]['description'] );
	}

	private function newLanguageWithConversion( string $code ): LanguageWithConversion {
		$stub = $this->createStub( LanguageWithConversion::class );
		$stub->method( 'getLanguageCode' )->willReturn( $code );
		$stub->method( 'getFetchLanguageCode' )->willReturn( $code );
		$stub->method( 'translate' )->willReturnCallback( static function ( $value ) {
			return $value;
		} );

		return $stub;
	}

}
