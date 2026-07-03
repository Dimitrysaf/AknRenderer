<?php
/**
 * Audits every akn_* index table for drift from the actual `page` table,
 * and reports AKN pages that have never been indexed at all.
 *
 * Two kinds of drift can build up over time, since nothing currently
 * removes index rows except a fresh save going through Indexer:
 *   - Orphaned rows: the page they reference no longer exists (e.g. it was
 *     deleted before the PageDeleteComplete hook existed) or no longer has
 *     the akn-xml content model (e.g. changed via Special:ChangeContentModel).
 *   - Unindexed pages: a page has the akn-xml content model right now, but
 *     has no row in akn_meta — either it predates indexing and was never
 *     backfilled, or its XML has no parseable <meta> (which is legitimate,
 *     not necessarily a problem).
 *
 * Run:
 *   php maintenance/run.php AknRenderer:CheckIndexIntegrity
 *   php maintenance/run.php AknRenderer:CheckIndexIntegrity --fix
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer\Maintenance;

use MediaWiki\Extension\AknRenderer\Indexer;
use MediaWiki\Maintenance\Maintenance;
use Wikimedia\Rdbms\Platform\ISQLPlatform;

$IP = getenv('MW_INSTALL_PATH') ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

class CheckIndexIntegrity extends Maintenance
{
	public function __construct()
	{
		parent::__construct();
		$this->addDescription(
			'Report (and optionally fix) drift between the akn_* index tables and the actual page table.'
		);
		$this->addOption('fix', 'Delete orphaned rows instead of only reporting them.');
		$this->requireExtension('AknRenderer');
	}

	public function execute()
	{
		$fix = $this->hasOption('fix');
		$dbProvider = $this->getServiceContainer()->getConnectionProvider();
		$dbr = $dbProvider->getReplicaDatabase();

		$totalOrphans = 0;
		foreach (Indexer::PAGE_INDEX_TABLES as $table => $column) {
			$totalOrphans += $this->checkTable($dbr, $table, $column, $fix);
		}

		$this->checkUnindexedPages($dbr);

		if ($totalOrphans === 0) {
			$this->output("No orphaned index rows found.\n");
		} elseif (!$fix) {
			$this->output("Run again with --fix to delete the orphaned rows listed above.\n");
		}
	}

	/**
	 * @param \Wikimedia\Rdbms\IReadableDatabase $dbr
	 * @param string $table
	 * @param string $column
	 * @param bool $fix
	 * @return int Number of distinct orphaned page ids found in this table.
	 */
	private function checkTable($dbr, string $table, string $column, bool $fix): int
	{
		$pageIds = $dbr->newSelectQueryBuilder()
			->select($column)
			->distinct()
			->from($table)
			->leftJoin('page', null, "$column = page_id")
			->where($dbr->makeList([
				'page_id IS NULL',
				'page_content_model != ' . $dbr->addQuotes(CONTENT_MODEL_AKN),
			], ISQLPlatform::LIST_OR))
			->caller(__METHOD__)
			->fetchFieldValues();

		if ($pageIds === []) {
			return 0;
		}

		$this->output(sprintf(
			"%s: %d orphaned page id(s): %s\n",
			$table,
			count($pageIds),
			implode(', ', $pageIds)
		));

		if ($fix) {
			$dbw = $this->getServiceContainer()->getConnectionProvider()->getPrimaryDatabase();
			$dbw->newDeleteQueryBuilder()
				->deleteFrom($table)
				->where([$column => $pageIds])
				->caller(__METHOD__)
				->execute();
			$this->output("  deleted.\n");
		}

		return count($pageIds);
	}

	/**
	 * Informational only — a missing akn_meta row can legitimately mean
	 * "this document has no parseable <meta>", not just "never indexed",
	 * so this doesn't offer to --fix; run BackfillIndex for that.
	 *
	 * @param \Wikimedia\Rdbms\IReadableDatabase $dbr
	 */
	private function checkUnindexedPages($dbr): void
	{
		$pageIds = $dbr->newSelectQueryBuilder()
			->select('page_id')
			->from('page')
			->leftJoin('akn_meta', null, 'am_page = page_id')
			->where([
				'page_content_model' => CONTENT_MODEL_AKN,
				'am_page' => null,
			])
			->caller(__METHOD__)
			->fetchFieldValues();

		if ($pageIds === []) {
			return;
		}
		$this->output(sprintf(
			"%d AKN page(s) have no akn_meta row (never indexed, or genuinely no parseable <meta>): %s\n"
			. "  Run maintenance/run.php AknRenderer:BackfillIndex to (re)index them.\n",
			count($pageIds),
			implode(', ', $pageIds)
		));
	}
}

$maintClass = CheckIndexIntegrity::class;
require_once RUN_MAINTENANCE_IF_MAIN;
