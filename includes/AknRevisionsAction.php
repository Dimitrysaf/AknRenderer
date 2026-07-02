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
			->select(['ar_rev', 'ar_effective', 'ar_fek', 'ar_fek_series', 'ar_fek_number', 'ar_fek_date'])
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
		// $records is one row per distinct effective date (akn_revision's PK is
		// now (page, effective)), ordered DESC — so the first dated row that
		// isn't in the future is unambiguously the active one.
		$activeRev = 0;
		foreach ($records as $r) {
			$eff = (string) $r->ar_effective;
			if ($eff !== '' && $eff <= $today) {
				$activeRev = (int) $r->ar_rev;
				break;
			}
		}

		$body = '';
		foreach ($records as $r) {
			$rev = (int) $r->ar_rev;
			$eff = (string) $r->ar_effective;
			$fek = (string) $r->ar_fek;
			$fekSeries = (string) $r->ar_fek_series;
			$fekNumber = (string) $r->ar_fek_number;
			$fekDate = (string) $r->ar_fek_date;
			// ar_fek alone (the gazette's generic showAs, e.g. "Εφημερίδα της
			// Κυβερνήσεως") identifies nothing — every law shares the same
			// name. Build the standard Greek citation (ΦΕΚ <number><series>/
			// <date>, e.g. "ΦΕΚ 91Α/2025-06-13") from the structured fields,
			// falling back to the generic name only when none of them exist.
			if ($fekNumber !== '') {
				$fekLabel = 'ΦΕΚ ' . $fekNumber . $fekSeries;
				if ($fekDate !== '') {
					$fekLabel .= '/' . $fekDate;
				}
			} else {
				$fekLabel = $fek;
			}

			if ($eff === '') {
				$status = $this->msg('aknrenderer-revisions-status-unknown')->text();
				$cls = 'akn-rev-unknown';
			} elseif ($eff > $today) {
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
				. Html::element('td', [], $fekLabel !== '' ? $fekLabel : '—')
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
