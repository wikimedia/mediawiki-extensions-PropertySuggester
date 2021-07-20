<?php

namespace PropertySuggester\Suggesters;

use Exception;
use InvalidArgumentException;
use LogicException;
use MediaWiki\Http\HttpRequestFactory;
use PropertySuggester\EventLogger;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Snak\PropertyValueSnak;

/**
 * a Suggester implementation that creates suggestions using
 * the SchemaTree suggester. Requires the PropertySuggesterSchemaTreeUrl
 * to be defined in the configuration.
 *
 * @license GPL-2.0-or-later
 */
class SchemaTreeSuggester implements SuggesterEngine {

	/**
	 * @var int[]
	 */
	private $deprecatedPropertyIds = [];

	/**
	 * @var array Numeric property ids as keys, values are meaningless.
	 */
	private $classifyingPropertyIds = [];

	/**
	 * @var string
	 */
	private $schemaTreeSuggesterUrl;

	/**
	 * @var string
	 */
	private $propertyBaseUrl;

	/**
	 * @var string
	 */
	private $typesBaseUrl;

	/**
	 * @var EventLogger|null
	 */
	private $eventLogger;

	/**
	 * @param int[] $deprecatedPropertyIds
	 */
	public function setDeprecatedPropertyIds( array $deprecatedPropertyIds ) {
		$this->deprecatedPropertyIds = $deprecatedPropertyIds;
	}

	/**
	 * @param int[] $classifyingPropertyIds
	 */
	public function setClassifyingPropertyIds( array $classifyingPropertyIds ) {
		$this->classifyingPropertyIds = array_flip( $classifyingPropertyIds );
	}

	/**
	 * @param string $schemaTreeSuggesterUrl
	 */
	public function setSchemaTreeSuggesterUrl( string $schemaTreeSuggesterUrl ) {
		$this->schemaTreeSuggesterUrl = $schemaTreeSuggesterUrl;
	}

	/**
	 * @param string $propertyBaseUrl
	 */
	public function setPropertyBaseUrl( string $propertyBaseUrl ) {
		$this->propertyBaseUrl = $propertyBaseUrl;
	}

	/**
	 * @param string $typesBaseUrl
	 */
	public function setTypesBaseUrl( string $typesBaseUrl ) {
		$this->typesBaseUrl = $typesBaseUrl;
	}

	/**
	 * @var HttpRequestFactory
	 */
	private $httpFactory;

	/**
	 * @param EventLogger $eventLogger
	 */
	public function setEventLogger( EventLogger $eventLogger ) {
		$this->eventLogger = $eventLogger;
	}

	public function __construct( HttpRequestFactory $httpFactory ) {
		$this->httpFactory = $httpFactory;
	}

	/**
	 * @param int[] $propertyIds
	 * @param int[] $typesIds
	 * @param int $limit
	 * @param float $minProbability
	 * @param string $include
	 * @return Suggestion[]|null
	 * @throws InvalidArgumentException
	 * @throws Exception
	 */
	private function getSuggestions(
		array $propertyIds,
		array $typesIds,
		int $limit,
		float $minProbability,
		string $include
	) {
		$this->eventLogger->setPropertySuggesterName( 'SchemaTreeSuggester' );
		$startTime = microtime( true );

		if ( !in_array( $include, [ self::SUGGEST_ALL, self::SUGGEST_NEW ] ) ) {
			throw new InvalidArgumentException( '$include must be one of the SUGGEST_* constants!' );
		}
		$excludedIds = [];
		if ( $include === self::SUGGEST_NEW ) {
			$excludedIds = array_merge( $propertyIds, $this->deprecatedPropertyIds );
		}
		$excludedIds = array_map( static function ( int $id ) {
			return 'P' . $id;
		}, $excludedIds );

		$properties = [];
		foreach ( $propertyIds as $id ) {
			$properties[] = $this->propertyBaseUrl . 'P' . $id;
		}

		$types = [];
		foreach ( $typesIds as $id ) {
			$types[] = $this->typesBaseUrl . 'Q' . $id;
		}

		$response = $this->httpFactory->post(
			$this->schemaTreeSuggesterUrl,
			[
				'postData' => json_encode( [
					'Properties' => $properties,
					'Types' => $types
				] ),
				'timeout' => 1
			],
			__METHOD__
		);

		// if request fails fall back to original property suggester
		if ( !$response ) {
			$this->eventLogger->setRequestDuration( -1 );
			$this->eventLogger->logEvent();
			return null;
		}

		$result = json_decode( $response, true );

		$result = $result['recommendations'] ?? null;
		if ( !is_array( $result ) ) {
			return null;
		}

		$results = $this->buildResult( $result, $minProbability, $excludedIds, $limit );
		$this->eventLogger->setRequestDuration( (int)( ( microtime( true ) - $startTime ) * 1000 ) );
		return $results;
	}

	/**
	 * @see SuggesterEngine::suggestByPropertyIds
	 *
	 * @param PropertyId[] $propertyIds
	 * @param ItemId[] $typesIds
	 * @param int $limit
	 * @param float $minProbability
	 * @param string $context
	 * @param string $include One of the self::SUGGEST_* constants
	 * @return Suggestion[]|null
	 */
	public function suggestByPropertyIds(
		array $propertyIds,
		array $typesIds,
		$limit,
		$minProbability,
		$context,
		$include
	) {
		$numericIds = array_map( static function ( PropertyId $propertyId ) {
			return $propertyId->getNumericId();
		}, $propertyIds );

		$numericTypeIds = array_map( static function ( ItemId $typeId ) {
			return $typeId->getNumericId();
		}, $typesIds );

		return $this->getSuggestions(
			$numericIds,
			$numericTypeIds,
			$limit,
			$minProbability,
			$include
		);
	}

	/**
	 * @see SuggesterEngine::suggestByEntity
	 *
	 * @param Item $item
	 * @param int $limit
	 * @param float $minProbability
	 * @param string $context
	 * @param string $include One of the self::SUGGEST_* constants
	 * @return Suggestion[]|null
	 * @throws LogicException|Exception
	 */
	public function suggestByItem( Item $item, $limit, $minProbability, $context, $include ) {
		$ids = [];
		$types = [];

		foreach ( $item->getStatements()->toArray() as $statement ) {
			$mainSnak = $statement->getMainSnak();
			$numericPropertyId = $mainSnak->getPropertyId()->getNumericId();
			$ids[] = $numericPropertyId;

			if ( isset( $this->classifyingPropertyIds[$numericPropertyId] )
				&& ( $mainSnak instanceof PropertyValueSnak ) ) {
				$dataValue = $mainSnak->getDataValue();

				if ( !( $dataValue instanceof EntityIdValue ) ) {
					throw new LogicException(
						"Property $numericPropertyId in wgPropertySuggesterClassifyingPropertyIds"
						. ' does not have value type wikibase-entityid'
					);
				}

				$entityId = $dataValue->getEntityId();

				if ( !( $entityId instanceof ItemId ) ) {
					throw new LogicException(
						"PropertyValueSnak for $numericPropertyId, configured in " .
						' wgPropertySuggesterClassifyingPropertyIds, has an unexpected value ' .
						'and data type (not wikibase-item).'
					);
				}
				$numericEntityId = $entityId->getNumericId();
				$types[] = $numericEntityId;
			}
		}

		$this->eventLogger->setExistingProperties( array_map( 'strval', $ids ) );
		$this->eventLogger->setExistingTypes( array_map( 'strval', $types ) );

		return $this->getSuggestions(
			$ids,
			$types,
			$limit,
			$minProbability,
			$include
		);
	}

	/**
	 * Converts the JSON object results to Suggestion objects
	 * @param array $response
	 * @param float $minProbability
	 * @param array $excludedIds
	 * @param int $limit
	 * @return Suggestion[]
	 */
	private function buildResult( array $response, float $minProbability, array $excludedIds, int $limit ): array {
		$resultArray = [];
		foreach ( $response as $pos => $res ) {
			if ( $pos > $limit ) {
				break;
			}
			if ( $res['probability'] > $minProbability && strpos( $res['property'], $this->propertyBaseUrl ) === 0 ) {
				$id = str_replace( $this->propertyBaseUrl, '', $res['property'] );
				if ( !in_array( $id, $excludedIds ) ) {
					$pid = new PropertyId( $id );
					$suggestion = new Suggestion( $pid, $res["probability"] );
					$resultArray[] = $suggestion;
				}
			}
		}
		return $resultArray;
	}
}
