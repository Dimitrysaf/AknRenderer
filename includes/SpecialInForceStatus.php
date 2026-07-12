<?php
/**
 * Special:InForceStatus — one row per consolidated Law/Decree page, showing the
 * version currently in force (the greatest akn_revision effective date not after
 * today) and how many amendments have been applied to it. A thin read view over
 * akn_revision + akn_amendment_tag.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IConnectionProvider;

class SpecialInForceStatus extends SpecialPage
{
	private IConnectionProvider $dbProvider;

	public function __construct(IConnectionProvider $dbProvider)
	{
		parent::__construct('InForceStatus');
		$this->dbProvider = $dbProvider;
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
		$dbr = $this->dbProvider->getReplicaDatabase();
		$today = date('Y-m-d');

		// The in-force version of each page = its greatest effective date not
		// after today. Rows come back ascending, so the last seen per page wins.
		// Consolidated laws/decrees only — a gazette has akn_revision rows too
		// (its own FRBR dates), but it is authored fact, not an in-force codification.
		$rows = $dbr->newSelectQueryBuilder()
			->select(['ar_page', 'ar_effective', 'ar_fek'])
			->from('akn_revision')
			->join('page', null, 'ar_page = page_id')
			->where('ar_effective <= ' . $dbr->addQuotes($today))
			->where(['page_namespace' => [NS_LAW, NS_DECREE]])
			->orderBy(['ar_page', 'ar_effective'])
			->caller(__METHOD__)
			->fetchResultSet();

		$inForce = [];
		foreach ($rows as $row) {
			$inForce[(int)$row->ar_page] = $row;
		}
		if ($inForce === []) {
			$out->addHTML(Html::element('p', [], $this->msg('inforcestatus-empty')->text()));
			return;
		}

		// Applied-amendment counts per target page, in one grouped query.
		$counts = [];
		$countRows = $dbr->newSelectQueryBuilder()
			->select(['amt_target_page', 'cnt' => 'COUNT(*)'])
			->from('akn_amendment_tag')
			->where(['amt_status' => 'applied'])
			->groupBy('amt_target_page')
			->caller(__METHOD__)
			->fetchResultSet();
		foreach ($countRows as $c) {
			$counts[(int)$c->amt_target_page] = (int)$c->cnt;
		}

		$body = '';
		foreach ($inForce as $pageId => $row) {
			$title = Title::newFromID($pageId);
			$link = $title !== null ? $this->getLinkRenderer()->makeLink($title) : htmlspecialchars('#' . $pageId);
			$body .= Html::rawElement('tr', [], implode('', [
				Html::rawElement('td', [], $link),
				Html::element('td', [], (string)$row->ar_effective),
				Html::element('td', [], (string)($row->ar_fek ?? '—')),
				Html::element('td', [], (string)($counts[$pageId] ?? 0)),
			]));
		}

		$out->addHTML(Html::rawElement('table', ['class' => 'wikitable sortable'],
			Html::rawElement('tr', [], implode('', [
				Html::element('th', [], $this->msg('inforcestatus-col-page')->text()),
				Html::element('th', [], $this->msg('inforcestatus-col-inforce')->text()),
				Html::element('th', [], $this->msg('inforcestatus-col-fek')->text()),
				Html::element('th', [], $this->msg('inforcestatus-col-amendments')->text()),
			])) . $body
		));
	}
}
