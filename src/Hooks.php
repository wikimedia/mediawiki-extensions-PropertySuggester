<?php

namespace PropertySuggester;

use DatabaseUpdater;
use ExtensionRegistry;
use OutputPage;
use ResourceLoader;
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

	public static function onResourceLoaderRegisterModules( ResourceLoader $resourceLoader ) {
		$module = [
			'localBasePath' => dirname( __DIR__ ) . '/modules',
			'remoteExtPath' => 'PropertySuggester/modules',
			'packageFiles' => [
				'hook.js',
				'PropertySuggester.js',
				[
					'name' => 'config.json',
					'config' => [
						'PropertySuggesterABTestingState',
					],
				],
				[
					'name' => 'schemas.json',
					'content' => [],
				],
			],
			'dependencies' => [
				'wikibase.view.ControllerViewFactory',
			],
		];

		if ( ExtensionRegistry::getInstance()->isLoaded( 'EventLogging' ) ) {
			$module['dependencies'][] = 'ext.eventLogging';
			$schemas = ExtensionRegistry::getInstance()->getAttribute( 'EventLoggingSchemas' );
			$clientSchema = $schemas['PropertySuggesterClientSidePropertyRequest'];
			$module['packageFiles'][3]['content']['PropertySuggesterClientSidePropertyRequest'] = $clientSchema;
		}

		$resourceLoader->register( 'propertySuggester.suggestions', $module );
	}

}
