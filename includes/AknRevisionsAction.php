<?php
/**
 * action=revisions — the legal version table for a codified law.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

use MediaWiki\Actions\FormlessAction;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;

class AknRevisionsAction extends FormlessAction
{
	public function getName()
	{
		return 'revisions';
	}

	protected function getPageTitle()
	{
		return $this->msg('aknrenderer-revisions-title', $this->getTitle()->getPrefixedText());
	}

	protected function getDescription()
	{
		return '';
	}

	public function onView()
	{
		$title = $this->getTitle();
		$this->getOutput()->addModuleStyles(['ext.aknRenderer.styles']);

		if ($title->getContentModel() !== CONTENT_MODEL_AKN) {
			return Html::element('p', [], $this->msg('aknrenderer-revisions-notakn')->text());
		}

		$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
		$res = $dbr->newSelectQueryBuilder()
			->select(['ar_rev', 'ar_effective', 'ar_fek'])
			->from('akn_revision')
			->where(['ar_page' => $title->getArticleID()])
			->orderBy('ar_effective', 'DESC')
			->caller(__METHOD__)
			->fetchResultSet();

		$records = [];
		foreach ($res as $r) {
			$records[] = $r;
		}
		if ($records === []) {
			return Html::element('p', [], $this->msg('aknrenderer-revisions-empty')->text());
		}

		$today = date('Y-m-d');
		$activeRev = 0;
		$best = '';
		foreach ($records as $r) {
			$eff = (string) $r->ar_effective;
			if ($eff !== '' && $eff <= $today && $eff >= $best) {
				$best = $eff;
				$activeRev = (int) $r->ar_rev;
			}
		}

		$body = '';
		foreach ($records as $r) {
			$rev = (int) $r->ar_rev;
			$eff = (string) $r->ar_effective;
			$fek = (string) $r->ar_fek;

			if ($eff !== '' && $eff > $today) {
				$status = $this->msg('aknrenderer-revisions-status-future')->text();
				$cls = 'akn-rev-future';
			} elseif ($rev === $activeRev) {
				$status = $this->msg('aknrenderer-revisions-status-active')->text();
				$cls = 'akn-rev-active';
			} else {
				$status = $this->msg('aknrenderer-revisions-status-expired')->text();
				$cls = 'akn-rev-expired';
			}

			$label = $eff !== '' ? $eff : $this->msg('aknrenderer-revisions-version', $rev)->text();
			$dateLink = Html::element('a', ['href' => $title->getLocalURL(['oldid' => $rev])], $label);

			$body .= Html::rawElement(
				'tr',
				$rev === $activeRev ? ['class' => 'akn-rev-active-row'] : [],
				Html::rawElement('td', [], $dateLink)
				. Html::element('td', [], $fek !== '' ? $fek : '—')
				. Html::rawElement('td', ['class' => $cls], Html::element('span', [], $status))
			);
		}

		$table = Html::rawElement(
			'table',
			['class' => 'wikitable akn-revisions'],
			Html::rawElement(
				'thead',
				[],
				Html::rawElement(
					'tr',
					[],
					Html::element('th', [], $this->msg('aknrenderer-revisions-col-effective')->text())
					. Html::element('th', [], $this->msg('aknrenderer-revisions-col-fek')->text())
					. Html::element('th', [], $this->msg('aknrenderer-revisions-col-status')->text())
				)
			) . Html::rawElement('tbody', [], $body)
		);

		return Html::element('p', [], $this->msg('aknrenderer-revisions-intro')->text()) . $table;
	}
}
