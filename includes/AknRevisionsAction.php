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
				$fekText = 'ΦΕΚ ' . $fekNumber . $fekSeries;
				if ($fekDate !== '') {
					$fekText .= '/' . $fekDate;
				}
			} else {
				$fekText = $fek;
			}

			$gazettePage = $this->resolveGazettePage($dbr, $fekSeries, $fekNumber, $fekDate);
			$fekLabel = $gazettePage !== null
				? Html::element('a', ['href' => $gazettePage->getLocalURL()], $fekText)
				: htmlspecialchars($fekText, ENT_QUOTES);

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
				[],
				Html::rawElement('td', [], $dateLink)
				. Html::rawElement('td', [], $fekLabel !== '' ? $fekLabel : '—')
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

		return Html::element('p', [], $this->msg('aknrenderer-revisions-intro')->text())
			. $table
			. $this->renderAmendments($dbr, $title);
	}

	/**
	 * Amendment relationships recorded in the document's own
	 * <analysis><activeModifications>/<passiveModifications> — not derivable
	 * from the rendered text or the version table alone.
	 *
	 * @param \Wikimedia\Rdbms\IReadableDatabase $dbr
	 * @param \MediaWiki\Title\Title $title
	 * @return string HTML, empty if nothing is recorded.
	 */
	private function renderAmendments($dbr, $title): string
	{
		$res = $dbr->newSelectQueryBuilder()
			->select(['ama_direction', 'ama_type', 'ama_source_href', 'ama_dest_href', 'ama_date'])
			->from('akn_amendment')
			->where(['ama_page' => $title->getArticleID()])
			->orderBy('ama_order')
			->caller(__METHOD__)
			->fetchResultSet();

		$passiveRows = '';
		$activeRows = '';
		foreach ($res as $r) {
			$type = $this->modTypeLabel((string) $r->ama_type);
			$date = (string) $r->ama_date;
			// Within a direction, one side of the relationship is always a
			// fragment inside this document (linkable), the other an IRI
			// pointing elsewhere (shown as-is; resolving it to a wiki page
			// is a separate concern from just surfacing the relationship).
			if ($r->ama_direction === 'passive') {
				$local = $this->localEidLink($title, (string) $r->ama_dest_href);
				$other = (string) $r->ama_source_href;
				$passiveRows .= Html::rawElement(
					'tr',
					[],
					Html::rawElement('td', [], $local)
					. Html::element('td', [], $other)
					. Html::element('td', [], $type)
					. Html::element('td', [], $date !== '' ? $date : '—')
				);
			} else {
				$local = $this->localEidLink($title, (string) $r->ama_source_href);
				$other = (string) $r->ama_dest_href;
				$activeRows .= Html::rawElement(
					'tr',
					[],
					Html::rawElement('td', [], $local)
					. Html::element('td', [], $other)
					. Html::element('td', [], $type)
					. Html::element('td', [], $date !== '' ? $date : '—')
				);
			}
		}

		if ($passiveRows === '' && $activeRows === '') {
			return '';
		}

		$out = '';
		if ($passiveRows !== '') {
			$out .= Html::element('h2', [], $this->msg('aknrenderer-amendments-received')->text())
				. $this->amendmentTable($passiveRows, 'aknrenderer-amendments-col-provision', 'aknrenderer-amendments-col-source');
		}
		if ($activeRows !== '') {
			$out .= Html::element('h2', [], $this->msg('aknrenderer-amendments-made')->text())
				. $this->amendmentTable($activeRows, 'aknrenderer-amendments-col-provision', 'aknrenderer-amendments-col-target');
		}
		return $out;
	}

	/** Human label for a <textualMod @type> value, or the raw value if unrecognised. */
	private function modTypeLabel(string $type): string
	{
		if ($type === '') {
			return '';
		}
		$msg = $this->msg('aknrenderer-modtype-' . $type);
		return $msg->exists() ? $msg->text() : $type;
	}

	private function amendmentTable(string $rows, string $provisionColMsg, string $otherColMsg): string
	{
		return Html::rawElement(
			'table',
			['class' => 'wikitable akn-amendments'],
			Html::rawElement(
				'thead',
				[],
				Html::rawElement(
					'tr',
					[],
					Html::element('th', [], $this->msg($provisionColMsg)->text())
					. Html::element('th', [], $this->msg($otherColMsg)->text())
					. Html::element('th', [], $this->msg('aknrenderer-amendments-col-type')->text())
					. Html::element('th', [], $this->msg('aknrenderer-amendments-col-date')->text())
				)
			) . Html::rawElement('tbody', [], $rows)
		);
	}

	/**
	 * Resolve a codified version's ΦΕΚ citation to the Gazette: page that
	 * documents that issue, if one exists in this wiki. Matched against
	 * akn_gazette — populated by the Indexer only for pages whose own XML
	 * root is an <officialGazette> — not akn_meta, which records a LAW's
	 * citation of the ΦΕΚ that published it; that's a different fact about
	 * a different kind of page, so it lives in a different table.
	 *
	 * @param \Wikimedia\Rdbms\IReadableDatabase $dbr
	 * @param string $series
	 * @param string $number
	 * @param string $date
	 * @return \MediaWiki\Title\Title|null
	 */
	private function resolveGazettePage($dbr, string $series, string $number, string $date)
	{
		if ($number === '') {
			return null;
		}
		$row = $dbr->newSelectQueryBuilder()
			->select(['page_namespace', 'page_title'])
			->from('akn_gazette')
			->join('page', null, 'agz_page = page_id')
			->where([
				'agz_series' => $series,
				'agz_number' => $number,
				'agz_date' => $date,
			])
			->caller(__METHOD__)
			->fetchRow();

		if (!$row) {
			return null;
		}
		return MediaWikiServices::getInstance()->getTitleFactory()->makeTitle(
			(int) $row->page_namespace,
			$row->page_title
		);
	}

	/** A '#eId' href becomes a link into this page; anything else is shown as plain text. */
	private function localEidLink($title, string $href): string
	{
		if ($href === '') {
			return '—';
		}
		if ($href[0] === '#') {
			return Html::element(
				'a',
				['href' => $title->getLocalURL() . $href],
				substr($href, 1)
			);
		}
		return htmlspecialchars($href, ENT_QUOTES);
	}
}
