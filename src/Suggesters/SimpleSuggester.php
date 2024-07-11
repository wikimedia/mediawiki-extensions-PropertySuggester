<?php

namespace PropertySuggester\Suggesters;

use InvalidArgumentException;
use LogicException;
use PropertySuggester\EventLogger;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * a Suggester implementation that creates suggestion via MySQL
 * Needs the wbs_propertypairs table filled with pair probabilities.
 *
 * @author BP2013N2
 * @license GPL-2.0-or-later
 */
class SimpleSuggester implements SuggesterEngine {

	/**
	 * @var int[]
	 */
	private $deprecatedPropertyIds = [];

	/**
	 * @var array Numeric property ids as keys, values are meaningless.
	 */
	private $classifyingPropertyIds = [];

	/**
	 * @var Suggestion[]
	 */
	private $initialSuggestions = [];

	/**
	 * @var ILoadBalancer
	 */
	private $lb;

	/**
	 * @var EventLogger|null
	 */
	private $eventLogger;

	public function __construct( ILoadBalancer $lb ) {
		$this->lb = $lb;
	}

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
	 * @param int[] $initialSuggestionIds
	 */
	public function setInitialSuggestions( array $initialSuggestionIds ) {
		$suggestions = [];
		foreach ( $initialSuggestionIds as $id ) {
			$suggestions[] = new Suggestion( NumericPropertyId::newFromNumber( $id ), 1.0 );
		}

		$this->initialSuggestions = $suggestions;
	}

	/**
	 * @param EventLogger $eventLogger
	 */
	public function setEventLogger( EventLogger $eventLogger ) {
		$this->eventLogger = $eventLogger;
	}

	/**
	 * @param int[] $propertyIds
	 * @param array[] $idTuples Array of ( int property ID, int item ID ) tuples
	 * @param int $limit
	 * @param float $minProbability
	 * @param string $context
	 * @param string $include
	 * @throws InvalidArgumentException
	 * @return Suggestion[]
	 */
	private function getSuggestions(
		array $propertyIds,
		array $idTuples,
		$limit,
		$minProbability,
		$context,
		$include
	) {
		$this->eventLogger->setPropertySuggesterName( 'PropertySuggester' );
		$startTime = microtime( true );

		if ( !is_int( $limit ) ) {
			throw new InvalidArgumentException( '$limit must be int!' );
		}
		if ( !is_float( $minProbability ) ) {
			throw new InvalidArgumentException( '$minProbability must be float!' );
		}
		if ( !in_array( $include, [ self::SUGGEST_ALL, self::SUGGEST_NEW ] ) ) {
			throw new InvalidArgumentException( '$include must be one of the SUGGEST_* constants!' );
		}
		if ( !$propertyIds ) {
			$this->eventLogger->setRequestDuration( (int)( ( microtime( true ) - $startTime ) * 1000 ) );
			return $this->initialSuggestions;
		}

		$excludedIds = [];
		if ( $include === self::SUGGEST_NEW ) {
			$excludedIds = array_merge( $propertyIds, $this->deprecatedPropertyIds );
		}

		$count = count( $propertyIds );

		$dbr = $this->lb->getConnection( DB_REPLICA );

		$tupleConditions = [];
		foreach ( $idTuples as [ $pid, $qid ] ) {
			$tupleConditions[] = $dbr->expr( 'pid1', '=', (int)$pid )->and( 'qid1', '=', (int)$qid );
		}

		if ( !$tupleConditions ) {
			$condition = $dbr->expr( 'pid1', '=', $propertyIds );
		} else {
			$condition = $dbr->orExpr( $tupleConditions );
		}
		$res = $dbr->newSelectQueryBuilder()
			->select( [
				'pid' => 'pid2',
				'prob' => "sum(probability)/$count",
			] )
			->from( 'wbs_propertypairs' )
			->where( $condition )
			->andWhere( [ 'context' => $context ] )
			->andWhere( $excludedIds ? $dbr->expr( 'pid2', '!=', $excludedIds ) : [] )
			->groupBy( 'pid2' )
			->having( 'prob > ' . $minProbability )
			->orderBy( 'prob', SelectQueryBuilder::SORT_DESC )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchResultSet();

		$results = $this->buildResult( $res );
		$this->eventLogger->setRequestDuration( (int)( ( microtime( true ) - $startTime ) * 1000 ) );
		return $results;
	}

	/**
	 * @see SuggesterEngine::suggestByPropertyIds
	 * @param NumericPropertyId[] $propertyIds
	 * @param ItemId[] $typesIds
	 * @param int $limit
	 * @param float $minProbability
	 * @param string $context
	 * @param string $include One of the self::SUGGEST_* constants
	 * @return Suggestion[]
	 */
	public function suggestByPropertyIds(
		array $propertyIds,
		array $typesIds,
		$limit,
		$minProbability,
		$context,
		$include
	) {
		$numericIds = array_map( static function ( NumericPropertyId $propertyId ) {
			return $propertyId->getNumericId();
		}, $propertyIds );

		return $this->getSuggestions(
			$numericIds,
			[],
			$limit,
			$minProbability,
			$context,
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
	 * @throws LogicException
	 * @return Suggestion[]
	 */
	public function suggestByItem( Item $item, $limit, $minProbability, $context, $include ) {
		$ids = [];
		$idTuples = [];
		$types = [];

		foreach ( $item->getStatements()->toArray() as $statement ) {
			$mainSnak = $statement->getMainSnak();

			$id = $mainSnak->getPropertyId();
			if ( !( $id instanceof NumericPropertyId ) ) {
				throw new LogicException( 'PropertySuggester is incompatible with non-numeric Property IDs' );
			}

			$numericPropertyId = $id->getNumericId();
			$ids[] = $numericPropertyId;

			if ( !isset( $this->classifyingPropertyIds[$numericPropertyId] ) ) {
				$idTuples[] = [ $numericPropertyId, 0 ];
			} elseif ( $mainSnak instanceof PropertyValueSnak ) {
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
				$idTuples[] = [ $numericPropertyId, $numericEntityId ];
				$types[] = $numericEntityId;
			}
		}

		$this->eventLogger->setExistingProperties( array_map( 'strval', $ids ) );
		$this->eventLogger->setExistingTypes( array_map( 'strval', $types ) );

		return $this->getSuggestions(
			$ids,
			$idTuples,
			$limit,
			$minProbability,
			$context,
			$include
		);
	}

	/**
	 * Converts the rows of the SQL result to Suggestion objects
	 *
	 * @param IResultWrapper $res
	 * @return Suggestion[]
	 */
	private function buildResult( IResultWrapper $res ) {
		$resultArray = [];
		foreach ( $res as $row ) {
			$pid = NumericPropertyId::newFromNumber( $row->pid );
			$suggestion = new Suggestion( $pid, $row->prob );
			$resultArray[] = $suggestion;
		}
		return $resultArray;
	}

}
