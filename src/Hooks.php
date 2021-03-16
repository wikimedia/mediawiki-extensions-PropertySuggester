<?php

namespace PropertySuggester;

use DatabaseUpdater;
use OutputPage;
use Skin;
use Wikibase\DataModel\Entity\Item;
use Wikibase\Repo\WikibaseRepo;

/**
 * @author BP2013N2
 * @license GPL-2.0-or-later
 */
final class Hooks {

	/**
	 * Handler for the BeforePageDisplay hook, injects special behaviour
	 * for PropertySuggestions in the EntitySuggester (if page is in EntityNamespace)
	 *
	 * @param OutputPage &$out
	 * @param Skin &$skin
	 */
	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		if ( $out->getRequest()->getCheck( 'nosuggestions' ) ) {
			return;
		}

		$entityNamespaceLookup = WikibaseRepo::getEntityNamespaceLookup();
		$itemNamespace = $entityNamespaceLookup->getEntityNamespace( Item::ENTITY_TYPE );

		if ( $out->getTitle() === null || $out->getTitle()->getNamespace() !== $itemNamespace ) {
			return;
		}

		$out->addModules( 'propertySuggester.suggestions' );
	}

	public static function onCreateSchema( DatabaseUpdater $updater ) {
		$updater->addExtensionTable(
			'wbs_propertypairs',
			dirname( __DIR__ ) . "/sql/{$updater->getDB()->getType()}/tables-generated.sql"
		);
	}

}
