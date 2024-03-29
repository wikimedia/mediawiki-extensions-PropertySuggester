<?php

namespace PropertySuggester;

use MediaWiki\Status\Status;

/**
 * Parses the suggester parameters
 *
 * @author BP2013N2
 * @license GPL-2.0-or-later
 */
class SuggesterParamsParser {

	/**
	 * @var int
	 */
	private $defaultSuggestionLimit;

	/**
	 * @var float
	 */
	private $defaultMinProbability;

	/**
	 * @param int $defaultSuggestionLimit
	 * @param float $defaultMinProbability
	 */
	public function __construct( $defaultSuggestionLimit, $defaultMinProbability ) {
		$this->defaultSuggestionLimit = $defaultSuggestionLimit;
		$this->defaultMinProbability = $defaultMinProbability;
	}

	/**
	 * parses and validates the parameters of GetSuggestion
	 * @param array $params
	 * @return Status containing SuggesterParams
	 */
	public function parseAndValidate( array $params ): Status {
		$result = new SuggesterParams();

		$result->entity = $params['entity'];
		$result->properties = $params['properties'];

		if ( !( $result->entity xor $result->properties ) ) {
			return Status::newFatal( 'propertysuggester-wbsgetsuggestions-either-entity-or-properties' );
		}

		$result->types = $params['types'];

		// The entityselector doesn't allow a search for '' so '*' gets mapped to ''
		if ( $params['search'] !== '*' ) {
			$result->search = trim( $params['search'] );
		} else {
			$result->search = '';
		}

		$result->limit = $params['limit'];
		$result->continue = (int)$params['continue'];
		$result->resultSize = $result->limit + $result->continue;

		if ( $result->resultSize > $this->defaultSuggestionLimit ) {
			$result->resultSize = $this->defaultSuggestionLimit;
		}

		$result->language = $params['language'];
		$result->context = $params['context'];

		if ( $result->search ) {
			// the results matching '$search' can be at the bottom of the list
			// however very low ranked properties are not interesting and can
			// still be found during the merge with search result later.
			$result->suggesterLimit = $this->defaultSuggestionLimit;
			$result->minProbability = 0.0;
		} else {
			$result->suggesterLimit = $result->resultSize;
			$result->minProbability = $this->defaultMinProbability;
		}

		if ( $params['include'] === 'all' ) {
			$result->include = 'all';
		} else {
			$result->include = '';
		}

		$result->event = $params['event'];

		return Status::newGood( $result );
	}

}
