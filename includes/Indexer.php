<?php
/**
 * Single source of truth for (re)indexing one AKN page into akn_meta,
 * akn_structure, akn_amendment, akn_gazette, akn_classification (all
 * rebuilt wholesale from the page's current XML, via indexPage) and
 * akn_revision (one row per distinct codified version, via indexRevision).
 * Called both from the save hook and the backfill script, so the two paths
 * never drift.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

use Wikimedia\Rdbms\IDatabase;

class Indexer
{

	/**
	 * Every akn_* table indexed per-page, and its page-id column. Single
	 * source of truth for "what needs cleaning up when a page goes away"
	 * (deletePage()) and "what to audit for drift" (the CheckIndexIntegrity
	 * maintenance script) — both need the full table/column list, and it
	 * must never fall out of sync with indexPage()/indexRevision() below.
	 *
	 * @var array<string,string>
	 */
	public const PAGE_INDEX_TABLES = [
		'akn_meta' => 'am_page',
		'akn_structure' => 'ast_page',
		'akn_amendment' => 'ama_page',
		'akn_gazette' => 'agz_page',
		'akn_classification' => 'acl_page',
		'akn_revision' => 'ar_page',
	];

	/**
	 * Remove every index row for a page — called when the page itself is
	 * deleted, since none of the akn_* tables otherwise notice and would
	 * otherwise keep orphaned rows forever.
	 *
	 * @param IDatabase $dbw Primary connection.
	 * @param int $pageId
	 */
	public static function deletePage(IDatabase $dbw, int $pageId): void
	{
		foreach (self::PAGE_INDEX_TABLES as $table => $column) {
			$dbw->newDeleteQueryBuilder()
				->deleteFrom($table)
				->where([$column => $pageId])
				->caller(__METHOD__)
				->execute();
		}
	}

	/**
	 * Rebuild both index tables for a page from its AKN XML.
	 *
	 * @param IDatabase $dbw Primary connection.
	 * @param int $pageId
	 * @param string $xml
	 */
	public static function indexPage(IDatabase $dbw, int $pageId, string $xml): void
	{
		// --- akn_meta: document identity/metadata (one row per page) ---
		$data = MetaExtractor::fromXml($xml);
		if ($data === null) {
			// No parseable metadata: drop any stale row.
			$dbw->newDeleteQueryBuilder()
				->deleteFrom('akn_meta')
				->where(['am_page' => $pageId])
				->caller(__METHOD__)
				->execute();
		} else {
			$row = MetaExtractor::dbRow($data, $pageId);
			$row['am_updated'] = $dbw->timestamp();

			$dbw->newReplaceQueryBuilder()
				->replaceInto('akn_meta')
				->uniqueIndexFields(['am_page'])
				->row($row)
				->caller(__METHOD__)
				->execute();
		}

		// --- akn_structure: the eId tree (rebuild wholesale) ---
		$dbw->newDeleteQueryBuilder()
			->deleteFrom('akn_structure')
			->where(['ast_page' => $pageId])
			->caller(__METHOD__)
			->execute();

		$rows = StructureExtractor::fromXml($xml, $pageId);
		if ($rows !== []) {
			$dbw->newInsertQueryBuilder()
				->insertInto('akn_structure')
				->rows($rows)
				->caller(__METHOD__)
				->execute();
		}

		// --- akn_amendment: recorded modification relationships (rebuild wholesale) ---
		$dbw->newDeleteQueryBuilder()
			->deleteFrom('akn_amendment')
			->where(['ama_page' => $pageId])
			->caller(__METHOD__)
			->execute();

		$amendments = AmendmentExtractor::fromXml($xml, $pageId);
		if ($amendments !== []) {
			$dbw->newInsertQueryBuilder()
				->insertInto('akn_amendment')
				->rows($amendments)
				->caller(__METHOD__)
				->execute();
		}

		// --- akn_gazette: a gazette issue's own identity (rebuild wholesale) ---
		$dbw->newDeleteQueryBuilder()
			->deleteFrom('akn_gazette')
			->where(['agz_page' => $pageId])
			->caller(__METHOD__)
			->execute();

		$gazette = GazetteExtractor::fromXml($xml, $pageId);
		if ($gazette !== null) {
			$dbw->newInsertQueryBuilder()
				->insertInto('akn_gazette')
				->row($gazette)
				->caller(__METHOD__)
				->execute();
		}

		// --- akn_classification: structured subject keywords (rebuild wholesale) ---
		$dbw->newDeleteQueryBuilder()
			->deleteFrom('akn_classification')
			->where(['acl_page' => $pageId])
			->caller(__METHOD__)
			->execute();

		$classifications = ClassificationExtractor::fromXml($xml, $pageId);
		if ($classifications !== []) {
			$dbw->newInsertQueryBuilder()
				->insertInto('akn_classification')
				->rows($classifications)
				->caller(__METHOD__)
				->execute();
		}
	}

	/**
	 * A codified "version" is identified by its (page, effective date), not by
	 * the MediaWiki revision that produced it. A save whose XML declares the
	 * same effective date as an already-indexed version (e.g. a typo fix that
	 * doesn't touch FRBRExpression/FRBRWork's date) updates that version's
	 * ar_rev in place instead of adding a row, so the version table reflects
	 * genuinely new codified texts, not every wiki save. If the XML is
	 * unparseable, the save is simply not indexed — prior, still-valid
	 * versions for the page are left untouched.
	 *
	 * @param IDatabase $dbw Primary connection.
	 * @param int $revId
	 * @param int $pageId
	 * @param string $xml
	 */
	public static function indexRevision(IDatabase $dbw, int $revId, int $pageId, string $xml): void
	{
		$data = MetaExtractor::fromXml($xml);
		if ($data === null) {
			return;
		}

		$effective = $data['exprDate'] !== '' ? $data['exprDate'] : $data['enacted'];
		$dbw->newReplaceQueryBuilder()
			->replaceInto('akn_revision')
			->uniqueIndexFields(['ar_page', 'ar_effective'])
			->row([
				'ar_page' => $pageId,
				'ar_effective' => self::cut((string) $effective, 32),
				'ar_rev' => $revId,
				'ar_fek' => self::cut((string) $data['pubShowAs'], 255),
				'ar_fek_series' => self::cut((string) $data['pubSeries'], 16),
				'ar_fek_number' => self::cut((string) $data['pubNumber'], 64),
				'ar_fek_date' => self::cut((string) $data['pubDate'], 32),
			])
			->caller(__METHOD__)
			->execute();
	}

	private static function cut(string $s, int $bytes): string
	{
		return mb_strcut($s, 0, $bytes, 'UTF-8');
	}
}
