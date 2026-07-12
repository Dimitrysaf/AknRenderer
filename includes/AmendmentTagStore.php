<?php
/**
 * Data-access for akn_amendment_tag — the AUTHORED amendment-tagging workflow.
 *
 * Unlike the extractor-driven akn_* tables (rebuilt wholesale from page XML by
 * the Indexer), these rows are durable authored state: an editor creates a
 * pending tag on a Gazette page, and Special:PendingAmendments later marks it
 * applied (with the revision it produced) or rejected (with a reason). The
 * Indexer must never touch this table.
 *
 * Owned here (with the schema) so every consumer — the tagging API, the
 * consolidation Special page, and read-side provenance — goes through one place.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

use Wikimedia\Rdbms\IConnectionProvider;

class AmendmentTagStore
{
	private IConnectionProvider $dbProvider;

	public function __construct(IConnectionProvider $dbProvider)
	{
		$this->dbProvider = $dbProvider;
	}

	/**
	 * Create a pending amendment tag. The order is a stable per-source-page id
	 * (max + 1), assigned once and never regenerated.
	 *
	 * @param int $sourcePage Gazette page declaring the change
	 * @param string|null $sourceEid provision in the Gazette that makes the change
	 * @param int|null $targetPage resolved Law/Decree page (null until matched)
	 * @param string|null $targetEid provision in the target being changed
	 * @param string $action replace|insert|repeal|renumber
	 * @param string|null $effective effective date (YYYY-MM-DD)
	 * @return int the assigned amt_order
	 */
	public function add(
		int $sourcePage,
		?string $sourceEid,
		?int $targetPage,
		?string $targetEid,
		string $action,
		?string $effective
	): int {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$max = $dbw->newSelectQueryBuilder()
			->select('MAX(amt_order)')
			->from('akn_amendment_tag')
			->where(['amt_source_page' => $sourcePage])
			->caller(__METHOD__)
			->fetchField();
		$order = $max === null || $max === false ? 0 : ((int)$max + 1);

		$dbw->newInsertQueryBuilder()
			->insertInto('akn_amendment_tag')
			->row([
				'amt_source_page' => $sourcePage,
				'amt_order' => $order,
				'amt_source_eid' => $sourceEid,
				'amt_target_page' => $targetPage,
				'amt_target_eid' => $targetEid,
				'amt_action' => $action,
				'amt_effective' => $effective,
				'amt_status' => 'pending',
				'amt_timestamp' => $dbw->timestamp(),
			])
			->caller(__METHOD__)
			->execute();

		return $order;
	}

	/**
	 * All pending tags, oldest first, grouped-friendly (ordered by target page
	 * then creation time) for Special:PendingAmendments.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function listPending(): array
	{
		$dbr = $this->dbProvider->getReplicaDatabase();
		$rows = $dbr->newSelectQueryBuilder()
			->select('*')
			->from('akn_amendment_tag')
			->where(['amt_status' => 'pending'])
			->orderBy(['amt_target_page', 'amt_timestamp', 'amt_order'])
			->caller(__METHOD__)
			->fetchResultSet();

		$out = [];
		foreach ($rows as $row) {
			$out[] = self::rowToArray($row);
		}
		return $out;
	}

	/**
	 * @param int $sourcePage
	 * @param int $order
	 * @return array<string,mixed>|null
	 */
	public function get(int $sourcePage, int $order): ?array
	{
		$dbr = $this->dbProvider->getReplicaDatabase();
		$row = $dbr->newSelectQueryBuilder()
			->select('*')
			->from('akn_amendment_tag')
			->where(['amt_source_page' => $sourcePage, 'amt_order' => $order])
			->caller(__METHOD__)
			->fetchRow();
		return $row ? self::rowToArray($row) : null;
	}

	/**
	 * Applied amendments targeting a page, keyed by target eId → the LATEST one
	 * (greatest effective date) for that provision. For per-paragraph provenance
	 * on Law/Decree pages. Rows with no target eId (whole-document) are skipped.
	 *
	 * @param int $pageId
	 * @return array<string,array<string,mixed>>
	 */
	public function appliedForPage(int $pageId): array
	{
		$dbr = $this->dbProvider->getReplicaDatabase();
		$rows = $dbr->newSelectQueryBuilder()
			->select('*')
			->from('akn_amendment_tag')
			->where(['amt_target_page' => $pageId, 'amt_status' => 'applied'])
			// ASC so a later effective date overwrites an earlier one in the map.
			->orderBy(['amt_effective', 'amt_order'])
			->caller(__METHOD__)
			->fetchResultSet();

		$out = [];
		foreach ($rows as $row) {
			if ($row->amt_target_eid === null || $row->amt_target_eid === '') {
				continue;
			}
			$out[$row->amt_target_eid] = self::rowToArray($row);
		}
		return $out;
	}

	/**
	 * Applied amendments MADE BY a gazette page (for Special:LawsAmendedBy),
	 * ordered by the page they target.
	 *
	 * @param int $sourcePage
	 * @return list<array<string,mixed>>
	 */
	public function appliedBySource(int $sourcePage): array
	{
		$dbr = $this->dbProvider->getReplicaDatabase();
		$rows = $dbr->newSelectQueryBuilder()
			->select('*')
			->from('akn_amendment_tag')
			->where(['amt_source_page' => $sourcePage, 'amt_status' => 'applied'])
			->orderBy(['amt_target_page', 'amt_order'])
			->caller(__METHOD__)
			->fetchResultSet();

		$out = [];
		foreach ($rows as $row) {
			$out[] = self::rowToArray($row);
		}
		return $out;
	}

	/**
	 * Mark a tag applied and record the Law/Decree revision it produced.
	 */
	public function markApplied(int $sourcePage, int $order, int $revId): void
	{
		$this->setStatus($sourcePage, $order, 'applied', ['amt_applied_rev' => $revId]);
	}

	/**
	 * Mark a tag rejected with a required human-readable reason.
	 */
	public function markRejected(int $sourcePage, int $order, string $reason): void
	{
		$this->setStatus($sourcePage, $order, 'rejected', ['amt_reason' => $reason]);
	}

	/**
	 * Stamp the in-force date onto the akn_revision row the approval produced.
	 * The row itself is created by the Indexer when the target page is saved;
	 * this records when the consolidated version takes effect (from the tag).
	 * Looked up by ar_rev (unique), not the composite (ar_page, ar_effective).
	 */
	public function setRevisionInForce(int $revId, ?string $inForceFrom): void
	{
		if ($revId <= 0) {
			return;
		}
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newUpdateQueryBuilder()
			->update('akn_revision')
			->set(['ar_in_force_from' => $inForceFrom])
			->where(['ar_rev' => $revId])
			->caller(__METHOD__)
			->execute();
	}

	/**
	 * @param array<string,mixed> $extra additional columns to set
	 */
	private function setStatus(int $sourcePage, int $order, string $status, array $extra = []): void
	{
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newUpdateQueryBuilder()
			->update('akn_amendment_tag')
			->set(['amt_status' => $status] + $extra)
			->where(['amt_source_page' => $sourcePage, 'amt_order' => $order])
			->caller(__METHOD__)
			->execute();
	}

	/**
	 * @param \stdClass $row
	 * @return array<string,mixed>
	 */
	private static function rowToArray($row): array
	{
		return [
			'source_page' => (int) $row->amt_source_page,
			'order' => (int) $row->amt_order,
			'source_eid' => $row->amt_source_eid,
			'target_page' => $row->amt_target_page !== null ? (int) $row->amt_target_page : null,
			'target_eid' => $row->amt_target_eid,
			'action' => $row->amt_action,
			'effective' => $row->amt_effective,
			'status' => $row->amt_status,
			'applied_rev' => $row->amt_applied_rev !== null ? (int) $row->amt_applied_rev : null,
			'reason' => $row->amt_reason,
			'timestamp' => $row->amt_timestamp,
		];
	}
}
