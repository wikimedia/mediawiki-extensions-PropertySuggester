<?php

namespace PropertySuggester;

use InvalidArgumentException;
use Message;
use PropertySuggester\Suggesters\SuggesterEngine;
use PropertySuggester\Suggesters\Suggestion;
use Status;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\DataModel\Services\Lookup\UnresolvedEntityRedirectException;
use Wikibase\Repo\Api\EntitySearchException;
use Wikibase\Repo\Api\EntitySearchHelper;

/**
 * API module helper to generate property suggestions.
 *
 * @author BP2013N2
 * @license GPL-2.0-or-later
 */
class SuggestionGenerator {

	/**
	 * @var EntityLookup
	 */
	private $entityLookup;

	/**
	 * @var EntitySearchHelper
	 */
	private $entityTermSearchHelper;

	/**
	 * @var SuggesterEngine
	 */
	private $suggester;

	/**
	 * @var SuggesterEngine|null
	 */
	private $fallbackSuggester;

	public function __construct(
		EntityLookup $entityLookup,
		EntitySearchHelper $entityTermSearchHelper,
		SuggesterEngine $suggester,
		?SuggesterEngine $fallbackSuggester = null
	) {
		$this->entityLookup = $entityLookup;
		$this->entityTermSearchHelper = $entityTermSearchHelper;
		$this->suggester = $suggester;
		$this->fallbackSuggester = $fallbackSuggester;
	}

	/**
	 * @param string $itemIdString
	 * @param int $limit
	 * @param float $minProbability
	 * @param string $context
	 * @param string $include One of the SuggesterEngine::SUGGEST_* constants
	 * @return Status containing Suggestion[]
	 */
	public function generateSuggestionsByItem(
		$itemIdString,
		$limit,
		$minProbability,
		$context,
		$include
	): Status {
		try {
			$itemId = new ItemId( $itemIdString );
		} catch ( InvalidArgumentException $e ) {
			return Status::newFatal( 'wikibase-api-invalid-entity-id' );
		}
		/** @var Item $item */
		try {
			$item = $this->entityLookup->getEntity( $itemId );
		} catch ( UnresolvedEntityRedirectException $e ) {
			return Status::newFatal( 'wikibase-api-unresolved-redirect' );
		}

		if ( $item === null ) {
			return Status::newFatal( 'wikibase-api-no-such-entity',
				Message::plaintextParam( $itemIdString ) );
		}
		'@phan-var Item $item';

		$suggestions = $this->suggester->suggestByItem(
			$item,
			$limit,
			$minProbability,
			$context,
			$include
		);

		if ( $suggestions === null ) {
			if ( $this->fallbackSuggester === null ) {
				return Status::newGood( [] );
			}
			$suggestions = $this->fallbackSuggester->suggestByItem(
				$item,
				$limit,
				$minProbability,
				$context,
				$include
			);
		}
		return Status::newGood( $suggestions );
	}

	/**
	 * @param string[] $propertyIdList A list of property-id-strings
	 * @param string[] $typesIdList A list of types-id-strings
	 * @param int $limit
	 * @param float $minProbability
	 * @param string $context
	 * @param string $include One of the SuggesterEngine::SUGGEST_* constants
	 * @return Status containing Suggestion[]
	 */
	public function generateSuggestionsByPropertyList(
		array $propertyIdList,
		array $typesIdList,
		$limit,
		$minProbability,
		$context,
		$include
	): Status {
		$propertyIds = [];
		foreach ( $propertyIdList as $stringId ) {
			try {
				$propertyIds[] = new NumericPropertyId( $stringId );
			} catch ( InvalidArgumentException $e ) {
				return Status::newFatal( 'wikibase-api-invalid-property-id' );
			}
		}

		$typesIds = [];
		foreach ( $typesIdList as $stringId ) {
			try {
				$typesIds[] = new ItemId( $stringId );
			} catch ( InvalidArgumentException $e ) {
				return Status::newFatal( 'wikibase-api-invalid-entity-id' );
			}
		}

		$suggestions = $this->suggester->suggestByPropertyIds(
			$propertyIds,
			$typesIds,
			$limit,
			$minProbability,
			$context,
			$include
		);

		if ( $suggestions === null ) {
			if ( $this->fallbackSuggester === null ) {
				return Status::newGood( [] );
			}
			$suggestions = $this->fallbackSuggester->suggestByPropertyIds(
				$propertyIds,
				$typesIds,
				$limit,
				$minProbability,
				$context,
				$include
			);
		}

		return Status::newGood( $suggestions );
	}

	/**
	 * @param Suggestion[] $suggestions
	 * @param string $search
	 * @param string $language
	 * @param int $resultSize
	 * @return Suggestion[]
	 * @throws EntitySearchException
	 */
	public function filterSuggestions( array $suggestions, $search, $language, $resultSize ) {
		if ( !$search ) {
			return array_slice( $suggestions, 0, $resultSize );
		}

		// @phan-suppress-next-line PhanParamTooMany
		$searchResults = $this->entityTermSearchHelper->getRankedSearchResults(
			$search,
			$language,
			'property',
			$resultSize,
			true,
			null
		);

		$id_set = [];
		foreach ( $searchResults as $searchResult ) {
			// @phan-suppress-next-next-line PhanUndeclaredMethod getEntityId() returns PropertyId
			// as requested above and that implements getNumericId()
			$id_set[$searchResult->getEntityId()->getNumericId()] = true;
		}

		$matching_suggestions = [];
		$count = 0;
		foreach ( $suggestions as $suggestion ) {
			if ( array_key_exists( $suggestion->getPropertyId()->getNumericId(), $id_set ) ) {
				$matching_suggestions[] = $suggestion;
				if ( ++$count === $resultSize ) {
					break;
				}
			}
		}
		return $matching_suggestions;
	}

}
