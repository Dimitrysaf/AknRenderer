<?php
/**
 * Schema updates. Kept separate from other hooks because
 * LoadExtensionSchemaUpdates handlers must not use injected services.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaHooks implements LoadExtensionSchemaUpdatesHook
{

	/**
	 * @param mixed $updater DatabaseUpdater
	 */
	public function onLoadExtensionSchemaUpdates($updater)
	{
		$updater->addExtensionTable('akn_meta', __DIR__ . '/../sql/tables.sql');
		$updater->addExtensionTable('akn_structure', __DIR__ . '/../sql/structure.sql');
	}
}
