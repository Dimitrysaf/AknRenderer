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
		];
	}

	private static function cut(string $s, int $bytes): string
	{
		return mb_strcut($s, 0, $bytes, 'UTF-8');
	}
}
