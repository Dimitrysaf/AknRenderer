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
		if (trim($xml) === '') {
			return null;
		}
		$dom = new \DOMDocument();
		$prev = libxml_use_internal_errors(true);
		$ok = $dom->loadXML($xml, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		if (!$ok) {
			return null;
		}

		// The document-type element must be a DIRECT child of <akomaNtoso> —
		// a <component> inside a gazette's <collectionBody> can embed its
		// own nested document, and a deep tag-name search would wrongly
		// match that instead of the outer root.
		$root = null;
		$aknRoot = $dom->documentElement;
		if ($aknRoot instanceof \DOMElement) {
			foreach ($aknRoot->childNodes as $child) {
				if ($child instanceof \DOMElement && $child->localName === 'officialGazette') {
					$root = $child;
					break;
				}
			}
		}
		if ($root === null) {
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
		];
	}

	private static function cut(string $s, int $bytes): string
	{
		return mb_strcut($s, 0, $bytes, 'UTF-8');
	}
}
