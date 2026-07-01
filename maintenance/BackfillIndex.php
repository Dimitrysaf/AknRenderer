<?php
/**
 * Backfill akn_meta and akn_structure for AKN Law pages saved before indexing
 * existed. The save hook only indexes future edits, so existing pages need this
 * one-off pass (also handy after changing the extractors).
 *
 * Run:
 *   php maintenance/run.php extensions/AknRenderer/maintenance/BackfillIndex.php
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
			'Rebuild akn_meta and akn_structure for all existing AKN Law pages.'
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

				Indexer::indexPage($dbw, $id, $content->getText());
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
