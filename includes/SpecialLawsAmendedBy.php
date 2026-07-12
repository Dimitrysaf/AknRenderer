<?php
/**
 * Special:LawsAmendedBy/<gazette> — the Law/Decree provisions amended by a given
 * gazette issue. A thin read view over akn_amendment_tag (applied rows), the
 * mirror of the per-provision provenance footnote.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IConnectionProvider;

class SpecialLawsAmendedBy extends SpecialPage
{
	private AmendmentTagStore $store;

	/** action enum → an existing aknrenderer-modtype-* label key. */
	private const ACTION_MSG = [
		'repeal' => 'aknrenderer-modtype-repeal',
		'replace' => 'aknrenderer-modtype-replacement',
		'insert' => 'aknrenderer-modtype-insertion',
		'renumber' => 'aknrenderer-modtype-renumbering',
	];

	public function __construct(IConnectionProvider $dbProvider)
	{
		parent::__construct('LawsAmendedBy');
		$this->store = new AmendmentTagStore($dbProvider);
	}

	protected function getGroupName()
	{
		return 'pages';
	}

	public function execute($subPage)
	{
		$this->setHeaders();
		$this->outputHeader();
		$out = $this->getOutput();

		$gazette = ($subPage !== null && $subPage !== '') ? Title::newFromText($subPage) : null;
		if ($gazette === null || !$gazette->exists() || $gazette->getNamespace() !== NS_GAZETTE) {
			$out->addHTML(Html::noticeBox($this->msg('lawsamendedby-prompt')->parse(), ''));
			return;
		}

		$out->addWikiMsg('lawsamendedby-intro', $gazette->getPrefixedText());

		$tags = $this->store->appliedBySource($gazette->getArticleID());
		if ($tags === []) {
			$out->addHTML(Html::element('p', [], $this->msg('lawsamendedby-empty')->text()));
			return;
		}

		$groups = [];
		foreach ($tags as $tag) {
			$groups[$tag['target_page'] ?? 0][] = $tag;
		}

		foreach ($groups as $targetPage => $rows) {
			$heading = $targetPage
				? $this->getLinkRenderer()->makeLink(Title::newFromID((int)$targetPage) ?? Title::makeTitle(NS_MAIN, (string)$targetPage))
				: $this->msg('lawsamendedby-untargeted')->text();
			$out->addHTML(Html::rawElement('h2', [], $heading));

			$body = '';
			foreach ($rows as $tag) {
				$body .= Html::rawElement('tr', [], implode('', [
					Html::element('td', [], (string)($tag['target_eid'] ?? '—')),
					Html::element('td', [], $this->actionLabel($tag['action'])),
					Html::element('td', [], (string)($tag['effective'] ?? '—')),
				]));
			}
			$out->addHTML(Html::rawElement('table', ['class' => 'wikitable'],
				Html::rawElement('tr', [], implode('', [
					Html::element('th', [], $this->msg('lawsamendedby-col-provision')->text()),
					Html::element('th', [], $this->msg('lawsamendedby-col-action')->text()),
					Html::element('th', [], $this->msg('lawsamendedby-col-effective')->text()),
				])) . $body
			));
		}
	}

	private function actionLabel(string $action): string
	{
		return isset(self::ACTION_MSG[$action]) ? $this->msg(self::ACTION_MSG[$action])->text() : $action;
	}
}
