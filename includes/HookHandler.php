<?php
/**
 * Runtime hooks: reject saves that don't validate against the schema
 * (onEditFilterMergedContent — the schema in schema/akomantoso30.xsd is the
 * source of truth for what counts as Akoma Ntoso), show AKN metadata on
 * action=info, keep the index tables (see Indexer) in sync on every save,
 * remove them again on delete — without onPageDeleteComplete, a deleted
 * page's rows would stay in every akn_* table forever, since nothing else
 * notices the page is gone — and expose AknVocabulary to JS (for AknEditor
 * and any other client-side tooling, so they never hand-duplicate the
 * structural taxonomy).
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

use MediaWiki\Actions\Hook\InfoActionHook;
use MediaWiki\Config\Config;
use MediaWiki\EditPage\EditPage;
use MediaWiki\Hook\EditFilterMergedContentHook;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\ResourceLoader\Hook\ResourceLoaderGetConfigVarsHook;
use MediaWiki\Skin\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use Wikimedia\Rdbms\IConnectionProvider;

class HookHandler implements
	EditFilterMergedContentHook,
	InfoActionHook,
	PageDeleteCompleteHook,
	PageSaveCompleteHook,
	ResourceLoaderGetConfigVarsHook,
	SkinTemplateNavigation__UniversalHook
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
	 * Gate saves on schema validity: an AknContent edit that does not satisfy
	 * schema/akomantoso30.xsd is rejected with the schema's own error messages.
	 * The schema is the single source of truth (see AknSchema), so this is the
	 * one place that decides whether stored content is "Akoma Ntoso".
	 *
	 * @param \IContextSource $context
	 * @param \MediaWiki\Content\Content $content
	 * @param \MediaWiki\Status\Status $status
	 * @param string $summary
	 * @param \MediaWiki\User\User $user
	 * @param bool $minoredit
	 * @return bool
	 */
	public function onEditFilterMergedContent($context, $content, $status, $summary, $user, $minoredit)
	{
		if (!$content instanceof AknContent) {
			return true;
		}

		$errors = AknSchema::validate($content->getText());
		if ($errors === []) {
			return true;
		}

		$limit = 10;
		$shown = array_slice($errors, 0, $limit);
		if (count($errors) > $limit) {
			$shown[] = $context->msg('aknrenderer-schema-more')
				->numParams(count($errors) - $limit)->text();
		}
		$list = "\n* " . implode("\n* ", $shown);

		$status->fatal('aknrenderer-save-invalidschema', $list);
		// Make the API and edit form show the schema errors instead of a
		// generic "hook aborted" message.
		$status->value = EditPage::AS_HOOK_ERROR_EXPECTED;
		return false;
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

		$dbw = $this->dbProvider->getPrimaryDatabase();
		$pageId = $wikiPage->getId();
		$xml = $content->getText();

		Indexer::indexPage($dbw, $pageId, $xml);
		Indexer::indexRevision($dbw, $revisionRecord->getId(), $pageId, $xml);
	}

	/**
	 * Remove the deleted page's rows from every akn_* index table. Gated
	 * on the deleted revision's own content model (not, say, the current
	 * content model of $page, since the page no longer exists to ask) —
	 * cheap enough to always attempt for AKN pages, and a no-op elsewhere.
	 *
	 * @param \MediaWiki\Page\ProperPageIdentity $page
	 * @param \MediaWiki\Permissions\Authority $deleter
	 * @param string $reason
	 * @param int $pageID
	 * @param \MediaWiki\Revision\RevisionRecord $deletedRev
	 * @param \MediaWiki\Logging\ManualLogEntry $logEntry
	 * @param int $archivedRevisionCount
	 */
	public function onPageDeleteComplete(
		$page,
		$deleter,
		$reason,
		$pageID,
		$deletedRev,
		$logEntry,
		$archivedRevisionCount
	) {
		$content = $deletedRev->getContent(SlotRecord::MAIN);
		if (!$content instanceof AknContent) {
			return;
		}

		Indexer::deletePage($this->dbProvider->getPrimaryDatabase(), $pageID);
	}

	/**
	 * Export AknVocabulary's structural taxonomy to mw.config, so client-side
	 * tooling (AknEditor, if installed) reads it from the same PHP constants
	 * the renderer/indexer use instead of hand-copying the list — this is
	 * static, site-wide data with no per-request variance, so it belongs in
	 * this hook rather than MakeGlobalVariablesScript.
	 *
	 * @param array &$vars
	 * @param string $skin
	 * @param Config $config
	 */
	public function onResourceLoaderGetConfigVars(array &$vars, $skin, Config $config): void
	{
		$vars['wgAknVocabulary'] = [
			'namespace' => AknVocabulary::NS,
			// The exact XML namespace URI every AKN element MUST carry, read
			// straight from the schema (AknSchema::NS). Client tooling builds
			// new nodes with this so it can never drift from the .xsd — the
			// single source of truth — the way a hand-copied constant would.
			'ns' => AknSchema::NS,
			'documentTypes' => AknVocabulary::documentTypes(),
			'structureTypes' => AknVocabulary::structureTypes(),
			'inlineSpans' => AknVocabulary::inlineSpans(),
			'mainBodyContainers' => AknVocabulary::MAIN_BODY_CONTAINERS,
			'headingLevels' => AknVocabulary::HEADING_LEVELS,
			'hcontainerLabels' => AknVocabulary::HCONTAINER_LABELS,
			'docTypes' => AknVocabulary::DOC_TYPES,
			'countries' => AknVocabulary::COUNTRIES,
			'languages' => AknVocabulary::LANGUAGES,
		];
	}

	/**
	 * @param \MediaWiki\Skin\SkinTemplate $sktemplate
	 * @param array &$links
	 */
	public function onSkinTemplateNavigation__Universal($sktemplate, &$links): void
	{
		$title = $sktemplate->getTitle();
		if ($title === null || $title->getContentModel() !== CONTENT_MODEL_AKN) {
			return;
		}

		unset($links['views']['history'], $links['actions']['history']);

		$action = $sktemplate->getRequest()->getVal('action');
		$links['views']['revisions'] = [
			'text' => $sktemplate->msg('aknrenderer-revisions-tab')->text(),
			'href' => $title->getLocalURL(['action' => 'revisions']),
			'class' => $action === 'revisions' ? 'selected' : '',
		];
	}
}
