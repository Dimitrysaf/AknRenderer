<?php
/**
 * Extracts recorded amendment relationships from a document's
 * <meta><analysis> block for the akn_amendment table.
 *
 * Akoma Ntoso records "this document changes that provision" (and the
 * reverse) as <textualMod> entries inside <activeModifications> (changes
 * this document makes to others) and <passiveModifications> (changes this
 * document has received), each pointing at a <source>/<destination> IRI and
 * optionally a <force period="#eventId"> resolved against a matching
 * <lifecycle><eventRef eId="eventId" date="..."> for the date it took
 * effect. None of this is visible in the rendered <body> alone.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

class AmendmentExtractor
{

	/**
	 * @param string $xml
	 * @param int $pageId
	 * @return list<array<string,mixed>> Rows ready for akn_amendment.
	 */
	public static function fromXml(string $xml, int $pageId): array
	{
		$dom = AknDom::parse($xml);
		if ($dom === null) {
			return [];
		}
		$meta = AknDom::findMeta($dom);
		if ($meta === null) {
			return [];
		}
		$analysis = self::first($meta, 'analysis');
		if ($analysis === null) {
			return [];
		}

		$eventDates = self::collectEventDates($meta);

		$rows = [];
		$order = 0;
		$active = self::first($analysis, 'activeModifications');
		if ($active !== null) {
			self::collectMods($active, 'active', $pageId, $eventDates, $rows, $order);
		}
		$passive = self::first($analysis, 'passiveModifications');
		if ($passive !== null) {
			self::collectMods($passive, 'passive', $pageId, $eventDates, $rows, $order);
		}
		return $rows;
	}

	/**
	 * Map <lifecycle><eventRef eId="..." date="..."> by eId, so a
	 * <force period="#eId"> can be resolved to an actual date.
	 *
	 * @param \DOMElement $meta
	 * @return array<string,string>
	 */
	private static function collectEventDates(\DOMElement $meta): array
	{
		$dates = [];
		$lifecycle = self::first($meta, 'lifecycle');
		if ($lifecycle === null) {
			return $dates;
		}
		foreach ($lifecycle->childNodes as $ev) {
			if ($ev instanceof \DOMElement && $ev->localName === 'eventRef') {
				$eId = $ev->getAttribute('eId');
				$date = $ev->getAttribute('date');
				if ($eId !== '' && $date !== '') {
					$dates[$eId] = $date;
				}
			}
		}
		return $dates;
	}

	/**
	 * @param \DOMElement $container <activeModifications> or <passiveModifications>
	 * @param string $direction 'active' or 'passive'
	 * @param int $pageId
	 * @param array<string,string> $eventDates
	 * @param list<array<string,mixed>> &$rows
	 * @param int &$order
	 */
	private static function collectMods(
		\DOMElement $container,
		string $direction,
		int $pageId,
		array $eventDates,
		array &$rows,
		int &$order
	): void {
		foreach ($container->childNodes as $mod) {
			if (!$mod instanceof \DOMElement || $mod->localName !== 'textualMod') {
				continue;
			}
			$source = self::firstHref($mod, 'source');
			$destination = self::firstHref($mod, 'destination');
			if ($source === '' && $destination === '') {
				// Nothing to relate; skip rather than store an empty link.
				continue;
			}

			$rows[] = [
				'ama_page' => $pageId,
				'ama_order' => $order++,
				'ama_direction' => $direction,
				'ama_type' => self::cut($mod->getAttribute('type'), 32),
				'ama_source_href' => self::cut($source, 512),
				'ama_dest_href' => self::cut($destination, 512),
				'ama_date' => self::cut(self::forceDate($mod, $eventDates), 32),
			];
		}
	}

	/**
	 * @param \DOMElement $mod
	 * @param array<string,string> $eventDates
	 * @return string
	 */
	private static function forceDate(\DOMElement $mod, array $eventDates): string
	{
		foreach ($mod->childNodes as $child) {
			if ($child instanceof \DOMElement && $child->localName === 'force') {
				$period = $child->getAttribute('period');
				if ($period !== '' && $period[0] === '#') {
					return $eventDates[substr($period, 1)] ?? '';
				}
			}
		}
		return '';
	}

	/** href of the first direct child with the given local name, or ''. */
	private static function firstHref(\DOMElement $mod, string $local): string
	{
		foreach ($mod->childNodes as $child) {
			if ($child instanceof \DOMElement && $child->localName === $local) {
				return $child->getAttribute('href');
			}
		}
		return '';
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

	private static function cut(string $s, int $bytes): string
	{
		return mb_strcut($s, 0, $bytes, 'UTF-8');
	}
}
