<?php
/**
 * Backfill every akn_* index table (see Indexer) for AKN pages — in any
 * namespace, Law: or Gazette: — saved before indexing existed. The save
 * hook only indexes future edits, so existing pages need this one-off pass
 * (also handy after changing an extractor, to reindex everything under its
 * new logic).
 *
 * Run:
 *   php maintenance/run.php AknRenderer:BackfillIndex
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer\Maintenance;

use MediaWiki\Extension\AknRenderer\AknContent;
use MediaWiki\Extension\AknRenderer\Indexer;
use MediaWiki\Maintenance\Maintenance;

$IP = getenv('MW_INSTALL_PATH') ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

class BackfillIndex extends Maintenance
{
	public function __construct()
	{
		parent::__construct();
		$this->addDescription(
			'Rebuild all akn_* index tables for every existing AKN page (Law: and Gazette:).'
		);
		$this->requireExtension('AknRenderer');
		$this->setBatchSize(50);
	}

	public function execute()
	{
		$services = $this->getServiceContainer();
		$dbProvider = $services->getConnectionProvider();
		$wikiPageFactory = $services->getWikiPageFactory();
		$dbr = $dbProvider->getReplicaDatabase();
		$dbw = $dbProvider->getPrimaryDatabase();

		$scanned = 0;
		$indexed = 0;
		$lastId = 0;

		do {
			$ids = $dbr->newSelectQueryBuilder()
				->select('page_id')
				->from('page')
				->where([
					'page_content_model' => CONTENT_MODEL_AKN,
					'page_id > ' . (int) $lastId,
				])
				->orderBy('page_id')
				->limit($this->getBatchSize())
				->caller(__METHOD__)
				->fetchFieldValues();

			foreach ($ids as $id) {
				$id = (int) $id;
				$lastId = $id;
				$scanned++;

				$page = $wikiPageFactory->newFromID($id);
				$content = $page ? $page->getContent() : null;
				if (!$content instanceof AknContent) {
					continue;
				}

				$xml = $content->getText();
				Indexer::indexPage($dbw, $id, $xml);
				$latest = (int) $page->getLatest();
				if ($latest > 0) {
					Indexer::indexRevision($dbw, $latest, $id, $xml);
				}
				$indexed++;
			}

			$this->waitForReplication();
			if ($ids !== []) {
				$this->output("… scanned $scanned, indexed $indexed\n");
			}
		} while (count($ids) === $this->getBatchSize());

		$this->output("Done. Indexed $indexed AKN page(s) out of $scanned scanned.\n");
	}
}

$maintClass = BackfillIndex::class;
require_once RUN_MAINTENANCE_IF_MAIN;
