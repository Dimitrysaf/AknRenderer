<?php
/**
 * Extracts a gazette issue's own identity (series/number/date, from its own
 * <publication>) for the akn_gazette table — populated only for documents
 * whose root is an <officialGazette>, regardless of which namespace they
 * happen to live in.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

class GazetteExtractor
{

	/**
	 * @param string $xml
	 * @param int $pageId
	 * @return array<string,mixed>|null Row for akn_gazette, or null if this
	 *   document isn't an <officialGazette> or has no <publication>.
	 */
	public static function fromXml(string $xml, int $pageId): ?array
	{
		$dom = AknDom::parse($xml);
		if ($dom === null) {
			return null;
		}
		$root = AknDom::findRoot($dom);
		if ($root === null || $root->localName !== 'officialGazette') {
			return null;
		}

		$meta = $root->getElementsByTagName('meta')->item(0);
		if (!$meta instanceof \DOMElement) {
			return null;
		}
		$pub = $meta->getElementsByTagName('publication')->item(0);
		if (!$pub instanceof \DOMElement) {
			return null;
		}

		return [
			'agz_page' => $pageId,
			'agz_series' => self::cut($pub->getAttribute('name'), 16),
			'agz_number' => self::cut($pub->getAttribute('number'), 64),
			'agz_date' => self::cut($pub->getAttribute('date'), 32),
			'agz_doc_type' => self::primaryDocType($root),
		];
	}

	/**
	 * Classify the issue by its FIRST published document (act | pd | other).
	 *
	 * A ΦΕΚ issue can carry several documents in its <collectionBody>; this
	 * records the primary (first, in document order) one: a law → 'act', a
	 * presidential decree (<doc name="proedrikoDiatagma">) → 'pd', anything
	 * else (a ministerial decision «απόφαση», etc.) → 'other'. Returns null
	 * when the collection has no classifiable published document.
	 *
	 * @param \DOMElement $root the <officialGazette> element
	 * @return string|null
	 */
	private static function primaryDocType(\DOMElement $root): ?string
	{
		$body = $root->getElementsByTagName('collectionBody')->item(0);
		if (!$body instanceof \DOMElement) {
			return null;
		}
		foreach ($body->getElementsByTagName('component') as $component) {
			// Document order: the component's own published document (a
			// <documentRef> or an inline <act>/<doc>/…) is the first element
			// encountered, before any references nested inside its body.
			foreach ($component->getElementsByTagName('*') as $el) {
				$type = self::classify($el);
				if ($type !== null) {
					return $type;
				}
			}
		}
		return null;
	}

	/**
	 * @param \DOMElement $el a candidate published-document element
	 * @return string|null 'act'|'pd'|'other', or null if $el isn't one
	 */
	private static function classify(\DOMElement $el): ?string
	{
		switch ($el->localName) {
			case 'documentRef':
				return self::classifyHref($el->getAttribute('href'));
			case 'act':
			case 'bill':
				return 'act';
			case 'doc':
				return self::isDecreeName($el->getAttribute('name')) ? 'pd' : 'other';
			default:
				return null;
		}
	}

	/**
	 * Classify an AKN Work URI: /akn/<country>/<doctype>/<subtype>/<date>/<num>.
	 *
	 * @param string $href
	 * @return string 'act'|'pd'|'other'
	 */
	private static function classifyHref(string $href): string
	{
		$parts = array_values(array_filter(
			explode('/', $href),
			static fn($p) => $p !== '' && $p !== 'akn'
		));
		$doctype = $parts[1] ?? '';
		$subtype = $parts[2] ?? '';
		if ($doctype === 'act' || $doctype === 'bill') {
			return 'act';
		}
		return self::isDecreeName($subtype) ? 'pd' : 'other';
	}

	private static function isDecreeName(string $name): bool
	{
		return str_contains(mb_strtolower($name), 'diatagma');
	}

	private static function cut(string $s, int $bytes): string
	{
		return mb_strcut($s, 0, $bytes, 'UTF-8');
	}
}
