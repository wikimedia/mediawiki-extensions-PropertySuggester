<?php

namespace PropertySuggester;

use DatabaseUpdater;
use OutputPage;
use Skin;
use Wikibase\DataModel\Entity\Item;
use Wikibase\Repo\WikibaseRepo;

/**
 * @author BP2013N2
 * @license GNU GPL v2+
 */
final class Hooks {

	/**
	 * Handler for the BeforePageDisplay hook, injects special behaviour
	 * for PropertySuggestions in the EntitySuggester (if page is in EntityNamespace)
	 *
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		if ( $out->getRequest()->getCheck( 'nosuggestions' ) ) {
			return;
		}

		$entityNamespaceLookup = WikibaseRepo::getDefaultInstance()->getEntityNamespaceLookup();
		$itemNamespace = $entityNamespaceLookup->getEntityNamespace( Item::ENTITY_TYPE );

		if ( !is_int( $itemNamespace ) ) {
			// try looking up namespace by content model, for any instances of PropertySuggester
			// running with older Wikibase prior to ef622b1bc.
			$itemNamespace = $entityNamespaceLookup->getEntityNamespace(
				CONTENT_MODEL_WIKIBASE_ITEM
			);
		}

		if ( $out->getTitle() === null || $out->getTitle()->getNamespace() !== $itemNamespace ) {
			return;
		}

		$out->addModules( 'ext.PropertySuggester.EntitySelector' );
	}

	/**
	 * @param DatabaseUpdater $updater
	 */
	public static function onCreateSchema( DatabaseUpdater $updater ) {
		$updater->addExtensionTable(
			'wbs_propertypairs',
			__DIR__ . '/../sql/create_propertypairs.sql'
		);
	}

}
