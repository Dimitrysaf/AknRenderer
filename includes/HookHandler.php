<?php
/**
 * Runtime hooks: show AKN metadata on action=info, and keep the akn_meta
 * table in sync on every save.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

use MediaWiki\Actions\Hook\InfoActionHook;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use Wikimedia\Rdbms\IConnectionProvider;

class HookHandler implements InfoActionHook, PageSaveCompleteHook
{

	private IConnectionProvider $dbProvider;
	private WikiPageFactory $wikiPageFactory;

	public function __construct(
		IConnectionProvider $dbProvider,
		WikiPageFactory $wikiPageFactory
	) {
		$this->dbProvider = $dbProvider;
		$this->wikiPageFactory = $wikiPageFactory;
	}

	/**
	 * Add an "Akoma Ntoso metadata" group to action=info.
	 *
	 * @param \IContextSource $context
	 * @param array &$pageInfo
	 */
	public function onInfoAction($context, &$pageInfo)
	{
		$title = $context->getTitle();
		if ($title === null || $title->getContentModel() !== CONTENT_MODEL_AKN) {
			return;
		}

		$content = $this->wikiPageFactory->newFromTitle($title)->getContent();
		if (!$content instanceof AknContent) {
			return;
		}

		$data = MetaExtractor::fromXml($content->getText());
		if ($data === null) {
			return;
		}

		foreach (MetaExtractor::displayItems($data) as [$label, $value]) {
			$pageInfo['aknrenderer'][] = [
				htmlspecialchars($label),
				htmlspecialchars($value),
			];
		}
	}

	/**
	 * Refresh akn_meta for the saved page.
	 *
	 * @param \WikiPage $wikiPage
	 * @param mixed $user
	 * @param string $summary
	 * @param int $flags
	 * @param \MediaWiki\Revision\RevisionRecord $revisionRecord
	 * @param mixed $editResult
	 */
	public function onPageSaveComplete($wikiPage, $user, $summary, $flags, $revisionRecord, $editResult)
	{
		$content = $revisionRecord->getContent(SlotRecord::MAIN);
		if (!$content instanceof AknContent) {
			return;
		}

		$pageId = $wikiPage->getId();
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$data = MetaExtractor::fromXml($content->getText());

		if ($data === null) {
			// No parseable metadata: drop any stale row.
			$dbw->newDeleteQueryBuilder()
				->deleteFrom('akn_meta')
				->where(['am_page' => $pageId])
				->caller(__METHOD__)
				->execute();
			return;
		}

		$row = MetaExtractor::dbRow($data, $pageId);
		$row['am_updated'] = $dbw->timestamp();

		$dbw->newReplaceQueryBuilder()
			->replaceInto('akn_meta')
			->uniqueIndexFields(['am_page'])
			->row($row)
			->caller(__METHOD__)
			->execute();
	}
}
