<?php

namespace PropertySuggester;

use ApiResult;
use PropertySuggester\Suggesters\Suggestion;
use Wikibase\DataModel\Services\Term\TermBuffer;
use Wikibase\Lib\Store\EntityTitleLookup;

/**
 * ResultBuilder builds Json-compatible array structure from suggestions
 *
 * @author BP2013N2
 * @license GPL-2.0-or-later
 */
class ResultBuilder {

	/**
	 * @var EntityTitleLookup
	 */
	private $entityTitleLookup;

	/**
	 * @var TermBuffer
	 */
	private $termBuffer;

	/**
	 * @var ApiResult
	 */
	private $result;

	/**
	 * @var string
	 */
	private $searchPattern;

	/**
	 * @param ApiResult $result
	 * @param TermBuffer $termBuffer
	 * @param EntityTitleLookup $entityTitleLookup
	 * @param string $search
	 */
	public function __construct(
		ApiResult $result,
		TermBuffer $termBuffer,
		EntityTitleLookup $entityTitleLookup,
		$search
	) {
		$this->entityTitleLookup = $entityTitleLookup;
		$this->termBuffer = $termBuffer;
		$this->result = $result;
		$this->searchPattern = '/^' . preg_quote( $search, '/' ) . '/i';
	}

	/**
	 * @param Suggestion[] $suggestions
	 * @param string $language
	 * @return array[]
	 */
	public function createResultArray( array $suggestions, $language ) {
		$ids = [];
		foreach ( $suggestions as $suggestion ) {
			$ids[] = $suggestion->getPropertyId();
		}

		$this->termBuffer->prefetchTerms(
			$ids,
			[ 'label', 'description', 'alias' ],
			[ $language ]
		);

		return array_map( function ( Suggestion $suggestion ) use ( $language ) {
			return $this->buildEntry( $suggestion, $language );
		}, $suggestions );
	}

	/**
	 * @param Suggestion $suggestion
	 * @param string $language
	 *
	 * @return array
	 */
	private function buildEntry( Suggestion $suggestion, $language ) {
		$id = $suggestion->getPropertyId();
		$entry = [
			'id' => $id->getSerialization(),
			'url' => $this->entityTitleLookup->getTitleForId( $id )->getFullURL(),
			'rating' => $suggestion->getProbability(),
		];

		$label = $this->termBuffer->getPrefetchedTerm( $id, 'label', $language );
		if ( is_string( $label ) ) {
			$entry['label'] = $label;
		}

		$description = $this->termBuffer->getPrefetchedTerm( $id, 'description', $language );
		if ( is_string( $description ) ) {
			$entry['description'] = $description;
		}

		$alias = $this->termBuffer->getPrefetchedTerm( $id, 'alias', $language );
		if ( is_string( $alias ) ) {
			$this->checkAndSetAlias( $entry, $alias );
		}

		if ( !isset( $entry['label'] ) ) {
			$entry['label'] = $id->getSerialization();
		} elseif ( preg_match( $this->searchPattern, $entry['label'] ) ) {
			// No aliases needed in the output when the label already is a successful match.
			unset( $entry['aliases'] );
		}

		return $entry;
	}

	/**
	 * @param array &$entry
	 * @param string $alias
	 */
	private function checkAndSetAlias( array &$entry, $alias ) {
		if ( preg_match( $this->searchPattern, $alias ) ) {
			if ( !isset( $entry['aliases'] ) ) {
				$entry['aliases'] = [];
				ApiResult::setIndexedTagName( $entry['aliases'], 'alias' );
			}
			$entry['aliases'][] = $alias;
		}
	}

	/**
	 * @param array[] $entries
	 * @param array[] $searchResults
	 * @param int $resultSize
	 * @return array[] representing Json
	 */
	public function mergeWithTraditionalSearchResults( array $entries, array $searchResults, $resultSize ) {
		// Avoid duplicates
		$existingKeys = [];
		foreach ( $entries as $entry ) {
			$existingKeys[$entry['id']] = true;
		}

		$distinctCount = count( $entries );
		foreach ( $searchResults as $result ) {
			if ( !array_key_exists( $result['id'], $existingKeys ) ) {
				$entries[] = $result;
				$distinctCount++;
				if ( $distinctCount >= $resultSize ) {
					break;
				}
			}
		}
		return $entries;
	}

}
