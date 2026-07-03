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
		$updater->addExtensionField(
			'akn_meta',
			'am_fek_series',
			__DIR__ . '/../sql/patch-am_fek_series.sql'
		);
		$updater->addExtensionTable('akn_structure', __DIR__ . '/../sql/structure.sql');

		// akn_revision's row identity changed from "one row per MediaWiki
		// save" (PK ar_rev) to "one row per codified version" (PK
		// (ar_page, ar_effective)), and gained ar_fek_series/ar_fek_date —
		// not expressible as an additive patch. The table is a pure derived
		// index (rebuilt from page content by Indexer/BackfillIndex), so on
		// installs still running the old schema it's safe to drop and
		// recreate; run maintenance/BackfillIndex.php afterwards to
		// repopulate it. Guarded on ar_fek_date's absence so this only
		// fires once, not on every update.php run.
		if ($updater->tableExists('akn_revision') && !$updater->fieldExists('akn_revision', 'ar_fek_date')) {
			$updater->dropExtensionTable('akn_revision');
		}
		$updater->addExtensionTable('akn_revision', __DIR__ . '/../sql/revision.sql');
		$updater->addExtensionTable('akn_amendment', __DIR__ . '/../sql/amendment.sql');
		$updater->addExtensionTable('akn_gazette', __DIR__ . '/../sql/gazette.sql');
		$updater->addExtensionTable('akn_classification', __DIR__ . '/../sql/classification.sql');
	}
}
