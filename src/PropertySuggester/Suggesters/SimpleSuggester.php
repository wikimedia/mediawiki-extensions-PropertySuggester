<?php

namespace PropertySuggester\Suggesters;

use LoadBalancer;
use ProfileSection;
use InvalidArgumentException;
use \Wikibase\DataModel\Statement\Statement;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\PropertyId;
use ResultWrapper;

/**
 * a Suggester implementation that creates suggestion via MySQL
 * Needs the wbs_propertypairs table filled with pair probabilities.
 *
 * @licence GNU GPL v2+
 */
class SimpleSuggester implements SuggesterEngine {

	/**
	 * @var int[]
	 */
	private $deprecatedPropertyIds = array();

	/**
	 * @var int[]
	 */
	private $classifyingProperties = array();

	/**
	 * @var LoadBalancer
	 */
	private $lb;

	/**
	 * @param LoadBalancer $lb
	 */
	public function __construct( LoadBalancer $lb ) {
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
		$this->classifyingProperties = $classifyingPropertyIds;
	}

	/**
	 * @param int[] $propertyIds
	 * @param string[] $idTuples
	 * @param int $limit
	 * @param float $minProbability
	 * @param string $context
	 * @throws InvalidArgumentException
	 * @return Suggestion[]
	 */
	protected function getSuggestions( array $propertyIds, array $idTuples, $limit, $minProbability, $context ) {
		$profiler = new ProfileSection( __METHOD__ );
		if ( !is_int( $limit ) ) {
			throw new InvalidArgumentException( '$limit must be int!' );
		}
		if ( !is_float( $minProbability ) ) {
			throw new InvalidArgumentException( '$minProbability must be float!' );
		}
		if ( !$propertyIds ) {
			return array();
		}

		$excludedIds = array_merge( $propertyIds, $this->deprecatedPropertyIds );
		$count = count( $propertyIds );

		$dbr = $this->lb->getConnection( DB_SLAVE );
		if ( empty( $idTuples ) ){
			$condition = 'pid1 IN (' . $dbr->makeList( $propertyIds ) . ')';
		}
		else{
			$condition = str_replace( "'", '', $dbr->makeList( $idTuples, LIST_OR ) );
		}
		$res = $dbr->select(
			'wbs_propertypairs',
			array( 'pid' => 'pid2', 'prob' => "sum(probability)/$count" ),
			array( $condition,
				   'pid2 NOT IN (' . $dbr->makeList( $excludedIds ) . ')',
				   'context' => $context ),
			__METHOD__,
			array(
				'GROUP BY' => 'pid2',
				'ORDER BY' => 'prob DESC',
				'LIMIT'    => $limit,
				'HAVING'   => 'prob > ' . floatval( $minProbability )
				)
			);
		$this->lb->reuseConnection( $dbr );

		return $this->buildResult( $res );
	}

	/**
	 * @see SuggesterEngine::suggestByPropertyIds
	 *
	 * @param PropertyId[] $propertyIds
	 * @param int $limit
 	 * @param float $minProbability
	 * @param string $context
	 * @return Suggestion[]
	 */
	public function suggestByPropertyIds( array $propertyIds, $limit, $minProbability, $context ) {
		$numericIds = array_map( array( $this, 'getNumericIdFromPropertyId' ), $propertyIds );
		return $this->getSuggestions( $numericIds, array(), $limit, $minProbability, $context );
	}

	/**
	 * @see SuggesterEngine::suggestByEntity
	 *
	 * @param Item $item
	 * @param int $limit
	 * @param float $minProbability
	 * @param string $context
	 * @return Suggestion[]
	 */
	public function suggestByItem( Item $item, $limit, $minProbability, $context ) {
		$statements = $item->getStatements()->toArray();
		$ids = array();
		$idTuples = array();
		foreach ( $statements as $statement ) {
			$numericPropertyId = $this->getNumericIdFromPropertyId( $statement->getMainSnak()->getPropertyId() );
			$ids[] = $numericPropertyId;
			if (! in_array( $numericPropertyId, $this->classifyingProperties ) ) {
				$idTuples[] = $this->buildTupleCondition( $numericPropertyId, '0' );
			}
			else {
				if ( $statement->getMainSnak()->getType() === "value" ) {
					$dataValue = $statement->getMainSnak()->getDataValue();
					$numericEntityId = ( int )substr( $dataValue->getEntityId()->getSerialization(), 1 );
					$idTuples[] = $this->buildTupleCondition( $numericPropertyId, $numericEntityId );
				}
			}
		}
		return $this->getSuggestions( $ids, $idTuples, $limit, $minProbability, $context );
	}

	/**
	 * @param string $a
	 * @param string $b
	 * @return string
	 */
	public function buildTupleCondition( $pid, $qid ){
		$tuple = '(pid1 = '. $pid .' AND qid1 = '. $qid .')';
		return $tuple;
	}

	/**
	 * Converts the rows of the SQL result to Suggestion objects
	 *
	 * @param ResultWrapper $res
	 * @return Suggestion[]
	 */
	protected function buildResult( ResultWrapper $res ) {
		$resultArray = array();
		foreach ( $res as $row ) {
			$pid = PropertyId::newFromNumber( ( int )$row->pid );
			$suggestion = new Suggestion( $pid, $row->prob );
			$resultArray[] = $suggestion;
		}
		return $resultArray;
	}

	private function getNumericIdFromPropertyId( PropertyId $propertyId ) {
		return $propertyId->getNumericId();
	}

}
