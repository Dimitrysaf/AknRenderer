<?php
/**
 * Extracts metadata from an AKN <meta> block.
 *
 * Two consumers: the action=info page (full display list) and the akn_meta
 * table (an indexed subset). Both derive from one parse.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

class MetaExtractor
{

	/**
	 * Parse the document's <meta> into a flat data array, namespace-tolerant.
	 *
	 * @param string $xml
	 * @return array<string,mixed>|null Null when the XML is unparseable or has no <meta>.
	 */
	public static function fromXml(string $xml): ?array
	{
		$dom = AknDom::parse($xml);
		if ($dom === null) {
			return null;
		}
		$root = AknDom::findRoot($dom);
		if ($root === null) {
			return null;
		}
		$meta = self::first($root, 'meta');
		if ($meta === null) {
			return null;
		}

		$work = self::first($meta, 'FRBRWork');
		$expr = self::first($meta, 'FRBRExpression');
		$manif = self::first($meta, 'FRBRManifestation');
		$pub = self::first($meta, 'publication');

		$keywords = [];
		foreach (self::all($meta, 'keyword') as $kw) {
			$v = $kw->getAttribute('showAs');
			if ($v === '') {
				$v = $kw->getAttribute('value');
			}
			if ($v !== '') {
				$keywords[] = $v;
			}
		}

		$events = [];
		foreach (self::all($meta, 'eventRef') as $ev) {
			$line = trim($ev->getAttribute('date') . ' ' . $ev->getAttribute('type'));
			if ($line !== '') {
				$events[] = $line;
			}
		}

		// Map eId → showAs for every TLC reference, to resolve hrefs like #vouli.
		$refs = [];
		$references = self::first($meta, 'references');
		if ($references !== null) {
			foreach ($references->childNodes as $r) {
				if (
					$r instanceof \DOMElement
					&& $r->getAttribute('eId') !== ''
					&& $r->getAttribute('showAs') !== ''
				) {
					$refs[$r->getAttribute('eId')] = $r->getAttribute('showAs');
				}
			}
		}
		$authorHref = self::attr($work, 'FRBRauthor', 'href');

		return [
			'docType' => $root ? $root->getAttribute('name') : '',
			'alias' => self::attr($work, 'FRBRalias', 'value'),
			'workUri' => self::attr($work, 'FRBRuri', 'value'),
			'number' => self::attr($work, 'FRBRnumber', 'value'),
			'subtype' => self::attr($work, 'FRBRsubtype', 'value'),
			'country' => self::attr($work, 'FRBRcountry', 'value'),
			'enacted' => self::attr($work, 'FRBRdate', 'date'),
			'author' => $authorHref,
			'authorLabel' => self::resolveRef($refs, $authorHref),
			'exprUri' => self::attr($expr, 'FRBRuri', 'value'),
			'exprDate' => self::attr($expr, 'FRBRdate', 'date'),
			'language' => self::attr($expr, 'FRBRlanguage', 'language'),
			'manifUri' => self::attr($manif, 'FRBRuri', 'value'),
			'pubShowAs' => $pub ? $pub->getAttribute('showAs') : '',
			// The ΦΕΚ τεύχος (Α, Β, Γ, Δ, ΑΑΠ, ΥΟΔΔ, ...) has no dedicated
			// attribute in the Akoma Ntoso <publication> element — by
			// convention @name carries it (machine-readable), mirroring
			// @showAs for the display label, e.g.
			// <publication name="Α" showAs="Εφημερίδα της Κυβερνήσεως" .../>.
			'pubSeries' => $pub ? $pub->getAttribute('name') : '',
			'pubNumber' => $pub ? $pub->getAttribute('number') : '',
			'pubDate' => $pub ? $pub->getAttribute('date') : '',
			'keywords' => $keywords,
			'events' => $events,
		];
	}

	/**
	 * Ordered [label, value] pairs for the action=info page (empties dropped).
	 *
	 * @param array<string,mixed> $d
	 * @return list<array{0:string,1:string}>
	 */
	public static function displayItems(array $d): array
	{
		$rows = [];
		$add = static function (string $label, string $value) use (&$rows) {
			if ($value !== '') {
				$rows[] = [$label, $value];
			}
		};
		$add(wfMessage('aknrenderer-info-title')->text(), (string) $d['alias']);
		$add(wfMessage('aknrenderer-info-doctype')->text(), self::humanize(AknVocabulary::DOC_TYPES, (string) $d['docType']));
		$add(wfMessage('aknrenderer-info-number')->text(), (string) $d['number']);
		$add(wfMessage('aknrenderer-info-enacted')->text(), (string) $d['enacted']);
		$add(wfMessage('aknrenderer-info-fek')->text(), (string) $d['pubShowAs']);
		$add(wfMessage('aknrenderer-info-fek-series')->text(), (string) $d['pubSeries']);
		$add(wfMessage('aknrenderer-info-fek-number')->text(), (string) $d['pubNumber']);
		$add(wfMessage('aknrenderer-info-pub-date')->text(), (string) $d['pubDate']);
		$add(wfMessage('aknrenderer-info-country')->text(), self::humanize(AknVocabulary::COUNTRIES, (string) $d['country']));
		$add(wfMessage('aknrenderer-info-language')->text(), self::humanize(AknVocabulary::LANGUAGES, (string) $d['language']));
		$add(wfMessage('aknrenderer-info-subtype')->text(), self::humanize(AknVocabulary::DOC_TYPES, (string) $d['subtype']));
		$add(wfMessage('aknrenderer-info-author')->text(), (string) $d['authorLabel']);
		$add(wfMessage('aknrenderer-info-work-uri')->text(), (string) $d['workUri']);
		$add(wfMessage('aknrenderer-info-expr-uri')->text(), (string) $d['exprUri']);
		$add(wfMessage('aknrenderer-info-manif-uri')->text(), (string) $d['manifUri']);
		if ($d['keywords']) {
			$add(wfMessage('aknrenderer-info-keywords')->text(), implode(', ', $d['keywords']));
		}
		if ($d['events']) {
			$add(wfMessage('aknrenderer-info-history')->text(), implode(' · ', $d['events']));
		}
		return $rows;
	}

	/**
	 * The indexed subset stored in akn_meta (am_updated added by the caller).
	 *
	 * @param array<string,mixed> $d
	 * @param int $pageId
	 * @return array<string,mixed>
	 */
	public static function dbRow(array $d, int $pageId): array
	{
		return [
			'am_page' => $pageId,
			// Canonical Work URI (see AknUri) so a <ref>/<documentRef> href
			// resolves to this page with a plain equality match.
			'am_work_uri' => self::cut(AknUri::work((string) $d['workUri']), 255),
			'am_expr_uri' => self::cut((string) $d['exprUri'], 255),
			'am_alias' => self::cut((string) $d['alias'], 255),
			'am_doc_type' => self::cut((string) $d['docType'], 64),
			'am_number' => self::cut((string) $d['number'], 64),
			'am_country' => self::cut((string) $d['country'], 8),
			'am_language' => self::cut((string) $d['language'], 16),
			'am_subtype' => self::cut((string) $d['subtype'], 64),
			'am_enacted' => self::cut((string) $d['enacted'], 32),
			'am_fek' => self::cut((string) $d['pubShowAs'], 255),
			'am_fek_series' => self::cut((string) $d['pubSeries'], 16),
			'am_fek_number' => self::cut((string) $d['pubNumber'], 64),
			'am_pub_date' => self::cut((string) $d['pubDate'], 32),
			'am_keywords' => self::cut(implode(', ', $d['keywords']), 255),
		];
	}

	private static function cut(string $s, int $bytes): string
	{
		return mb_strcut($s, 0, $bytes, 'UTF-8');
	}

	/** Map a controlled-vocabulary code to a Greek label, or return it raw. */
	private static function humanize(array $map, string $value): string
	{
		if ($value === '') {
			return '';
		}
		return $map[mb_strtolower($value, 'UTF-8')] ?? $value;
	}

	/** Resolve a "#eId" reference to its TLC showAs label, or return it raw. */
	private static function resolveRef(array $refs, string $href): string
	{
		if ($href !== '' && $href[0] === '#') {
			return $refs[substr($href, 1)] ?? $href;
		}
		return $href;
	}

	/**
	 * @param \DOMDocument|\DOMElement|null $ctx
	 * @param string $local
	 * @return \DOMElement|null
	 */
	private static function first($ctx, string $local): ?\DOMElement
	{
		if ($ctx === null) {
			return null;
		}
		$nodes = $ctx->getElementsByTagName($local);
		$item = $nodes->item(0);
		return $item instanceof \DOMElement ? $item : null;
	}

	/**
	 * @param \DOMDocument|\DOMElement|null $ctx
	 * @param string $local
	 * @return \DOMElement[]
	 */
	private static function all($ctx, string $local): array
	{
		if ($ctx === null) {
			return [];
		}
		$out = [];
		foreach ($ctx->getElementsByTagName($local) as $n) {
			if ($n instanceof \DOMElement) {
				$out[] = $n;
			}
		}
		return $out;
	}

	private static function attr(?\DOMElement $parent, string $childLocal, string $attr): string
	{
		$el = self::first($parent, $childLocal);
		return $el ? $el->getAttribute($attr) : '';
	}
}
