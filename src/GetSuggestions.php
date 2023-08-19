<?php

namespace PropertySuggester;

use ApiBase;
use ApiMain;
use ApiResult;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\DerivativeRequest;
use PropertySuggester\Suggesters\SchemaTreeSuggester;
use PropertySuggester\Suggesters\SimpleSuggester;
use PropertySuggester\Suggesters\SuggesterEngine;
use Wikibase\DataAccess\PrefetchingTermLookup;
use Wikibase\DataModel\Entity\Property;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\Lib\LanguageFallbackChainFactory;
use Wikibase\Lib\Store\EntityTitleLookup;
use Wikibase\Repo\Api\EntitySearchException;
use Wikibase\Repo\Api\EntitySearchHelper;
use Wikibase\Repo\WikibaseRepo;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

/**
 * API module to get property suggestions.
 *
 * @author BP2013N2
 * @license GPL-2.0-or-later
 */
class GetSuggestions extends ApiBase {

	/**
	 * @var EntityLookup
	 */
	private $entityLookup;

	/**
	 * @var EntityTitleLookup
	 */
	private $entityTitleLookup;

	/**
	 * @var string[]
	 */
	private $languageCodes;

	/**
	 * @var SuggesterEngine
	 */
	private $suggester;

	/**
	 * @var SuggesterEngine
	 */
	private $schemaTreeSuggester;

	/**
	 * @var SuggesterParamsParser
	 */
	private $paramsParser;

	/**
	 * @var EntitySearchHelper
	 */
	private $entitySearchHelper;

	/**
	 * @var PrefetchingTermLookup
	 */
	private $prefetchingTermLookup;

	/**
	 * @var LanguageFallbackChainFactory
	 */
	private $languageFallbackChainFactory;

	/**
	 * @var bool
	 */
	private $abTestingState;

	/**
	 * @var SuggesterEngine
	 */
	private $defaultSuggester;

	/**
	 * @var float
	 */
	private $testingRatio;

	/**
	 * @param ApiMain $main
	 * @param string $name
	 * @param string $prefix
	 */
	public function __construct( ApiMain $main, $name, $prefix = '' ) {
		parent::__construct( $main, $name, $prefix );
		$config = $this->getConfig();

		$mwServices = MediaWikiServices::getInstance();
		$lb = $mwServices->getDBLoadBalancer();
		$httpFactory = $mwServices->getHttpRequestFactory();

		$this->prefetchingTermLookup = WikibaseRepo::getPrefetchingTermLookup( $mwServices );
		$this->languageFallbackChainFactory = WikibaseRepo::getLanguageFallbackChainFactory( $mwServices );
		$this->entitySearchHelper = WikibaseRepo::getEntitySearchHelper( $mwServices );
		$this->entityLookup = WikibaseRepo::getEntityLookup( $mwServices );
		$this->entityTitleLookup = WikibaseRepo::getEntityTitleLookup( $mwServices );
		$this->languageCodes = WikibaseRepo::getTermsLanguages( $mwServices )->getLanguages();
		$this->abTestingState = $config->get( 'PropertySuggesterABTestingState' );

		$deprecatedPropertyIds = $config->get( 'PropertySuggesterDeprecatedIds' );
		$classifyingPropertyIds = $config->get( 'PropertySuggesterClassifyingPropertyIds' );

		$this->suggester = new SimpleSuggester( $lb );
		$this->suggester->setDeprecatedPropertyIds( $deprecatedPropertyIds );
		$this->suggester->setClassifyingPropertyIds( $classifyingPropertyIds );
		$this->suggester->setInitialSuggestions( $config->get( 'PropertySuggesterInitialSuggestions' ) );

		$this->schemaTreeSuggester = new SchemaTreeSuggester( $httpFactory );
		$this->schemaTreeSuggester->setSchemaTreeSuggesterUrl( $config->get( 'PropertySuggesterSchemaTreeUrl' ) );
		$this->schemaTreeSuggester->setDeprecatedPropertyIds( $deprecatedPropertyIds );
		$this->schemaTreeSuggester->setClassifyingPropertyIds( $classifyingPropertyIds );

		if ( $config->get( 'PropertySuggesterDefaultSuggester' ) === 'PropertySuggester' ) {
			$this->defaultSuggester = $this->suggester;
		} else {
			$this->defaultSuggester = $this->schemaTreeSuggester;
		}

		$this->testingRatio = $config->get( 'PropertySuggesterTestingRatio' );
		$this->paramsParser = new SuggesterParamsParser( 500, $config->get( 'PropertySuggesterMinProbability' ) );
	}

	/**
	 * @see ApiBase::execute()
	 */
	public function execute() {
		$extracted = $this->extractRequestParams();
		$paramsStatus = $this->paramsParser->parseAndValidate( $extracted );
		if ( !$paramsStatus->isGood() ) {
			$this->dieStatus( $paramsStatus );
		}
		/** @var SuggesterParams $params */
		$params = $paramsStatus->getValue();

		$eventLogger = new EventLogger(
			$params->event,
			$params->language
		);

		if ( $params->context === 'item' ) {
			if ( $this->abTestingState && $params->entity !== null ) {
				$hashId = $this->hasher( $params->entity );
				if ( $hashId % $this->testingRatio === 0 ) {
					$suggester = $this->schemaTreeSuggester;
				} else {
					$suggester = $this->suggester;
				}
			} else {
				$suggester = $this->defaultSuggester;
			}
		} else {
			$suggester = $this->suggester;
		}

		$suggester->setEventLogger( $eventLogger );
		$this->suggester->setEventLogger( $eventLogger );

		$suggestionGenerator = new SuggestionGenerator(
			$this->entityLookup,
			$this->entitySearchHelper,
			$suggester,
			$this->suggester // used in cases where schema tree recommender request fails
		);

		$suggest = SuggesterEngine::SUGGEST_NEW;
		if ( $params->include === 'all' ) {
			$suggest = SuggesterEngine::SUGGEST_ALL;
		}
		if ( $params->entity !== null ) {
			$suggestionsStatus = $suggestionGenerator->generateSuggestionsByItem(
				$params->entity,
				$params->suggesterLimit,
				$params->minProbability,
				$params->context,
				$suggest
			);
		} else {
			if ( $params->types === null ) {
				$params->types = [];
			}
			$suggestionsStatus = $suggestionGenerator->generateSuggestionsByPropertyList(
				$params->properties,
				$params->types,
				$params->suggesterLimit,
				$params->minProbability,
				$params->context,
				$suggest
			);
		}
		if ( !$suggestionsStatus->isGood() ) {
			$this->dieStatus( $suggestionsStatus );
		}
		$suggestions = $suggestionsStatus->getValue();

		try {
			$suggestions = $suggestionGenerator->filterSuggestions(
				$suggestions,
				$params->search,
				$params->language,
				$params->resultSize
			);
		} catch ( EntitySearchException $ese ) {
			$this->dieStatus( $ese->getStatus() );
		}

		$addSuggestions = [];
		foreach ( $suggestions as $suggestion ) {
			$addSuggestions[] = strval( $suggestion->getPropertyId()->getNumericId() );
		}
		$eventLogger->setAddSuggestions( $addSuggestions );
		$eventLogger->logEvent();

		// Build result array
		$resultBuilder = new ResultBuilder(
			$this->getResult(),
			$this->prefetchingTermLookup,
			$this->languageFallbackChainFactory,
			$this->entityTitleLookup,
			$params->search
		);

		$entries = $resultBuilder->createResultArray( $suggestions, $params->language );

		// merge with search result if possible and necessary
		if ( count( $entries ) < $params->resultSize && $params->search !== '' ) {
			$searchResult = $this->querySearchApi(
				$params->resultSize,
				$params->search,
				$params->language
			);
			$entries = $resultBuilder->mergeWithTraditionalSearchResults(
				$entries,
				$searchResult,
				$params->resultSize
			);
		}

		// Define Result
		$slicedEntries = array_slice( $entries, $params->continue, $params->limit );
		ApiResult::setIndexedTagName( $slicedEntries, 'search' );
		$this->getResult()->addValue( null, 'search', $slicedEntries );

		$this->getResult()->addValue( null, 'success', 1 );
		if ( count( $entries ) >= $params->resultSize ) {
			$this->getResult()->addValue( null, 'search-continue', $params->resultSize );
		}
		$this->getResult()->addValue( 'searchinfo', 'search', $params->search );
	}

	/**
	 * @param int $resultSize
	 * @param string $search
	 * @param string $language
	 * @return array[]
	 */
	private function querySearchApi( $resultSize, $search, $language ) {
		$searchEntitiesParameters = new DerivativeRequest(
			$this->getRequest(),
			[
				'limit' => $resultSize + 1,
				'continue' => 0,
				'search' => $search,
				'action' => 'wbsearchentities',
				'language' => $language,
				'uselang' => $language,
				'type' => Property::ENTITY_TYPE
			]
		);

		$api = new ApiMain( $searchEntitiesParameters );
		$api->execute();

		$apiResult = $api->getResult()->getResultData(
			null,
			[
				'BC' => [],
				'Types' => [],
				'Strip' => 'all'
			]
		);

		return $apiResult['search'];
	}

	/**
	 * Creates a numeric hash for the entity IDs
	 *
	 * @param string $code
	 * @return int a 64 bit decimal hash
	 */
	private function hasher( $code ) {
		$hex16 = substr( hash( 'sha256', $code ), 0, 16 );

		$hexhi = substr( $hex16, 0, 8 );
		$hexlo = substr( $hex16, 8, 8 );

		$int = hexdec( $hexlo ) | ( hexdec( $hexhi ) << 32 );
		return $int;
	}

	/**
	 * @inheritDoc
	 */
	public function getAllowedParams() {
		return [
			'entity' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'properties' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_ISMULTI => true
			],
			'types' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_ISMULTI => true
			],
			'limit' => [
				ParamValidator::PARAM_TYPE => 'limit',
				ParamValidator::PARAM_DEFAULT => 7,
				IntegerDef::PARAM_MAX => ApiBase::LIMIT_SML1,
				IntegerDef::PARAM_MAX2 => ApiBase::LIMIT_SML2,
				IntegerDef::PARAM_MIN => 0,
			],
			'continue' => [
				ParamValidator::PARAM_TYPE => 'integer',
			],
			'language' => [
				ParamValidator::PARAM_TYPE => $this->languageCodes,
				ParamValidator::PARAM_DEFAULT => $this->getContext()->getLanguage()->getCode(),
			],
			'context' => [
				ParamValidator::PARAM_TYPE => [ 'item', 'qualifier', 'reference' ],
				ParamValidator::PARAM_DEFAULT => 'item',
			],
			'include' => [
				ParamValidator::PARAM_TYPE => [ '', 'all' ],
				ParamValidator::PARAM_DEFAULT => '',
			],
			'search' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
			],
			'event' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getExamplesMessages() {
		return [
			'action=wbsgetsuggestions&entity=Q4'
			=> 'apihelp-wbsgetsuggestions-example-1',
			'action=wbsgetsuggestions&entity=Q4&continue=10&limit=5'
			=> 'apihelp-wbsgetsuggestions-example-2',
			'action=wbsgetsuggestions&properties=P31|P21'
			=> 'apihelp-wbsgetsuggestions-example-3',
			'action=wbsgetsuggestions&properties=P21&context=qualifier'
			=> 'apihelp-wbsgetsuggestions-example-4',
			'action=wbsgetsuggestions&properties=P21&context=reference'
			=> 'apihelp-wbsgetsuggestions-example-5'
		];
	}

}
