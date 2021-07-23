<?php

namespace PropertySuggester;

use ApiResult;
use PropertySuggester\Suggesters\Suggestion;
use Wikibase\DataAccess\PrefetchingTermLookup;
use Wikibase\Lib\LanguageFallbackChainFactory;
use Wikibase\Lib\Store\EntityTitleLookup;
use Wikibase\Lib\Store\LanguageFallbackLabelDescriptionLookup;

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
	 * @var ApiResult
	 */
	private $result;

	/**
	 * @var string
	 */
	private $searchPattern;

	/**
	 * @var PrefetchingTermLookup
	 */
	private $prefetchingTermLookup;

	/**
	 * @var LanguageFallbackChainFactory
	 */
	private $languageFallbackChainFactory;

	/**
	 * @param ApiResult $result
	 * @param PrefetchingTermLookup $prefetchingTermLookup
	 * @param LanguageFallbackChainFactory $languageFallbackChainFactory
	 * @param EntityTitleLookup $entityTitleLookup
	 * @param string $search
	 */
	public function __construct(
		ApiResult $result,
		PrefetchingTermLookup $prefetchingTermLookup,
		LanguageFallbackChainFactory $languageFallbackChainFactory,
		EntityTitleLookup $entityTitleLookup,
		$search
	) {
		$this->entityTitleLookup = $entityTitleLookup;
		$this->prefetchingTermLookup = $prefetchingTermLookup;
		$this->languageFallbackChainFactory = $languageFallbackChainFactory;
		$this->result = $result;
		$this->searchPattern = '/^' . preg_quote( $search, '/' ) . '/i';
	}

	public function createResultArray( array $suggestions, string $language ): array {
		$ids = [];
		foreach ( $suggestions as $suggestion ) {
			$ids[] = $suggestion->getPropertyId();
		}
		$fallbackChain = $this->languageFallbackChainFactory->newFromLanguageCode( $language );

		$this->prefetchingTermLookup->prefetchTerms(
			$ids,
			[ 'label', 'description', 'alias' ],
			$fallbackChain->getFetchLanguageCodes()
		);

		$fallbackTermLookup = new LanguageFallbackLabelDescriptionLookup(
			$this->prefetchingTermLookup,
			$fallbackChain
		);
		return array_map( function ( Suggestion $suggestion ) use ( $language, $fallbackTermLookup ) {
			return $this->buildEntry( $suggestion, $language, $fallbackTermLookup );
		}, $suggestions );
	}

	/**
	 * @param Suggestion $suggestion
	 * @param string $language
	 * @param LanguageFallbackLabelDescriptionLookup $fallbackTermLookup
	 *
	 * @return array
	 */
	private function buildEntry(
		Suggestion $suggestion,
		string $language,
		LanguageFallbackLabelDescriptionLookup $fallbackTermLookup
	): array {
		$id = $suggestion->getPropertyId();
		$entry = [
			'id' => $id->getSerialization(),
			'url' => $this->entityTitleLookup->getTitleForId( $id )->getFullURL(),
			'rating' => $suggestion->getProbability(),
		];

		$label = $fallbackTermLookup->getLabel( $id );
		if ( $label !== null ) {
			$entry['label'] = $label->getText();
		}

		$description = $fallbackTermLookup->getDescription( $id );
		if ( $description !== null ) {
			$entry['description'] = $description->getText();
		}

		$aliases = $this->prefetchingTermLookup->getPrefetchedAliases( $id, $language );
		if ( is_array( $aliases ) && !empty( $aliases ) ) {
			$this->checkAndSetAlias( $entry, $aliases[0] );
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
	public function mergeWithTraditionalSearchResults(
		array $entries,
		array $searchResults,
		$resultSize
	) {
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
