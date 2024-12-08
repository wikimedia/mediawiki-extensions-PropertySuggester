<?php

namespace PropertySuggester;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

/**
 * @license GPL-2.0-or-later
 */
final class SchemaHooks implements LoadExtensionSchemaUpdatesHook {
	/** @inheritDoc */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$updater->addExtensionTable(
			'wbs_propertypairs',
			dirname( __DIR__ ) . "/sql/{$updater->getDB()->getType()}/tables-generated.sql"
		);
	}
}
