<?php
/**
 * Extracts structured subject classification (<meta><classification>
 * <keyword>) for the akn_classification table.
 *
 * MetaExtractor's am_keywords is a flat, comma-joined string of @showAs
 * labels — fine for display, useless for query. A <keyword>'s real identity
 * is @dictionary (the controlled vocabulary/thesaurus it's drawn from, e.g.
 * EuroVoc) plus @value (the concept's code within that vocabulary);
 * @showAs is only the display label. @href optionally scopes the keyword
 * to a fragment of the document rather than the whole thing.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

class ClassificationExtractor
{

	/**
	 * @param string $xml
	 * @param int $pageId
	 * @return list<array<string,mixed>> Rows ready for akn_classification.
	 */
	public static function fromXml(string $xml, int $pageId): array
	{
		if (trim($xml) === '') {
			return [];
		}
		$dom = new \DOMDocument();
		$prev = libxml_use_internal_errors(true);
		$ok = $dom->loadXML($xml, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		if (!$ok) {
			return [];
		}

		// The document-type element must be a DIRECT child of <akomaNtoso> —
		// a deep tag-name search would also match a <keyword> nested inside
		// a <component>'s embedded document in a gazette's <collectionBody>.
		$root = null;
		$aknRoot = $dom->documentElement;
		if ($aknRoot instanceof \DOMElement) {
			foreach ($aknRoot->childNodes as $child) {
				if (
					$child instanceof \DOMElement
					&& in_array($child->localName, ['act', 'bill', 'doc', 'officialGazette'], true)
				) {
					$root = $child;
					break;
				}
			}
		}
		if ($root === null) {
			return [];
		}

		$meta = $root->getElementsByTagName('meta')->item(0);
		if (!$meta instanceof \DOMElement) {
			return [];
		}

		$rows = [];
		$order = 0;
		foreach ($meta->getElementsByTagName('keyword') as $kw) {
			if (!$kw instanceof \DOMElement) {
				continue;
			}
			$showAs = $kw->getAttribute('showAs');
			$value = $kw->getAttribute('value');
			if ($showAs === '' && $value === '') {
				continue;
			}
			$rows[] = [
				'acl_page' => $pageId,
				'acl_order' => $order++,
				'acl_dictionary' => self::cut($kw->getAttribute('dictionary'), 255),
				'acl_value' => self::cut($value, 255),
				'acl_showas' => self::cut($showAs, 255),
				'acl_href' => self::cut($kw->getAttribute('href'), 512),
			];
		}
		return $rows;
	}

	private static function cut(string $s, int $bytes): string
	{
		return mb_strcut($s, 0, $bytes, 'UTF-8');
	}
}
