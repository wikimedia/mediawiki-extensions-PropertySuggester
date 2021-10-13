<?php

namespace PropertySuggester\Suggesters;

use Wikibase\DataModel\Entity\NumericPropertyId;

/**
 * Suggestion returned by a SuggesterEngine
 *
 * @author BP2013N2
 * @license GPL-2.0-or-later
 */
class Suggestion {

	/**
	 * @var NumericPropertyId
	 */
	private $propertyId;

	/**
	 * @var float
	 * average probability that an already existing property is used with the suggested property
	 */
	private $probability;

	/**
	 * @param NumericPropertyId $propertyId
	 * @param float $probability
	 */
	public function __construct( NumericPropertyId $propertyId, $probability ) {
		$this->propertyId = $propertyId;
		$this->probability = $probability;
	}

	/**
	 * @return NumericPropertyId
	 */
	public function getPropertyId() {
		return $this->propertyId;
	}

	/**
	 * average probability that an already existing property is used with the suggested property
	 * @return float
	 */
	public function getProbability() {
		return $this->probability;
	}

}
