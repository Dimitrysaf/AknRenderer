<?php
/**
 * Single source of truth for (re)indexing one AKN page into akn_meta and
 * akn_structure. Called both from the save hook and the backfill script, so the
 * two paths never drift.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

use Wikimedia\Rdbms\IDatabase;

class Indexer
{

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
	}

	/**
	 * @param IDatabase $dbw Primary connection.
	 * @param int $revId
	 * @param int $pageId
	 * @param string $xml
	 */
	public static function indexRevision(IDatabase $dbw, int $revId, int $pageId, string $xml): void
	{
		$data = MetaExtractor::fromXml($xml);
		if ($data === null) {
			$dbw->newDeleteQueryBuilder()
				->deleteFrom('akn_revision')
				->where(['ar_rev' => $revId])
				->caller(__METHOD__)
				->execute();
			return;
		}

		$effective = $data['exprDate'] !== '' ? $data['exprDate'] : $data['enacted'];
		$dbw->newReplaceQueryBuilder()
			->replaceInto('akn_revision')
			->uniqueIndexFields(['ar_rev'])
			->row([
				'ar_rev' => $revId,
				'ar_page' => $pageId,
				'ar_effective' => self::cut((string) $effective, 32),
				'ar_fek' => self::cut((string) $data['pubShowAs'], 255),
				'ar_fek_number' => self::cut((string) $data['pubNumber'], 64),
			])
			->caller(__METHOD__)
			->execute();
	}

	private static function cut(string $s, int $bytes): string
	{
		return mb_strcut($s, 0, $bytes, 'UTF-8');
	}
}
