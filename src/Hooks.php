<?php

namespace PropertySuggester;

use ExtensionRegistry;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\ResourceLoader\Hook\ResourceLoaderRegisterModulesHook;
use MediaWiki\ResourceLoader\ResourceLoader;
use Skin;
use Wikibase\DataModel\Entity\Item;
use Wikibase\Repo\WikibaseRepo;

/**
 * @author BP2013N2
 * @license GPL-2.0-or-later
 */
final class Hooks implements
	BeforePageDisplayHook,
	ResourceLoaderRegisterModulesHook
{

	/**
	 * Handler for the BeforePageDisplay hook, injects special behaviour
	 * for PropertySuggestions in the EntitySuggester (if page is in EntityNamespace)
	 *
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( $out->getRequest()->getCheck( 'nosuggestions' ) ) {
			return;
		}

		$entityNamespaceLookup = WikibaseRepo::getEntityNamespaceLookup();
		$itemNamespace = $entityNamespaceLookup->getEntityNamespace( Item::ENTITY_TYPE );

		if ( $out->getTitle() === null || $out->getTitle()->getNamespace() !== $itemNamespace ) {
			return;
		}

		if ( ExtensionRegistry::getInstance()->isLoaded( 'MobileFrontend' ) ) {
			$services = MediaWikiServices::getInstance();
			if ( $services->getService( 'MobileFrontend.Context' )->shouldDisplayMobileView() ) {
				// Wikibase currently does not support editing statements on mobile,
				// so no need for PropertySuggester either.
				return;
			}
		}

		$out->addModules( 'propertySuggester.suggestions' );
	}

	public function onResourceLoaderRegisterModules( ResourceLoader $resourceLoader ): void {
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
