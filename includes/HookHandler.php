<?php
/**
 * Runtime hooks: show AKN metadata on action=info, and keep the index
 * tables (akn_meta, akn_structure) in sync on every save.
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
	 * Refresh the index tables for the saved page.
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

		Indexer::indexPage(
			$this->dbProvider->getPrimaryDatabase(),
			$wikiPage->getId(),
			$content->getText()
		);
	}
}
