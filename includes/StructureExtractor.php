<?php
/**
 * Extracts the eId tree of a document for the akn_structure table.
 *
 * One row per eId-bearing structural element, carrying its parent eId (nearest
 * eId-bearing ancestor), type, num, heading and document order. The page is the
 * root: top-level provisions have a NULL parent.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

class StructureExtractor
{

	/**
	 * @param string $xml
	 * @param int $pageId
	 * @return list<array<string,mixed>> Rows ready for akn_structure.
	 */
	public static function fromXml(string $xml, int $pageId): array
	{
		$dom = AknDom::parse($xml);
		if ($dom === null) {
			return [];
		}
		$body = self::firstBody($dom);
		if ($body === null) {
			return [];
		}

		$rows = [];
		$order = 0;
		self::walk($body, null, $pageId, $rows, $order);
		return $rows;
	}

	/**
	 * @param \DOMNode $node
	 * @param string|null $parentEid Nearest eId-bearing ancestor, or null at the root.
	 * @param int $pageId
	 * @param list<array<string,mixed>> &$rows
	 * @param int &$order
	 */
	private static function walk(\DOMNode $node, ?string $parentEid, int $pageId, array &$rows, int &$order): void
	{
		foreach ($node->childNodes as $child) {
			if (!$child instanceof \DOMElement) {
				continue;
			}
			$local = $child->localName;
			$eid = $child->getAttribute('eId');
			$childParent = $parentEid;

			if ($eid !== '' && in_array($local, AknVocabulary::structureTypes(), true)) {
				$rows[] = [
					'ast_page' => $pageId,
					'ast_eid' => self::cut($eid, 255),
					'ast_parent' => $parentEid,
					'ast_type' => self::cut($local, 32),
					'ast_num' => self::childText($child, 'num', 128),
					'ast_heading' => self::childText($child, 'heading', 255),
					'ast_order' => $order++,
				];
				// Descendants hang off this provision.
				$childParent = $eid;
			}

			self::walk($child, $childParent, $pageId, $rows, $order);
		}
	}

	/**
	 * The document's main-body container, whichever the document type uses
	 * (see AknVocabulary::MAIN_BODY_CONTAINERS) — scoped to the real document
	 * root (see AknDom::findRoot()) so it can't match a body nested inside a
	 * gazette component's embedded document instead.
	 */
	private static function firstBody(\DOMDocument $dom): ?\DOMElement
	{
		$root = AknDom::findRoot($dom);
		if ($root === null) {
			return null;
		}
		foreach (AknVocabulary::MAIN_BODY_CONTAINERS as $tag) {
			$n = $root->getElementsByTagName($tag)->item(0);
			if ($n instanceof \DOMElement) {
				return $n;
			}
		}
		return null;
	}

	/** Text of a direct child element (num/heading), trimmed and capped, or null. */
	private static function childText(\DOMElement $el, string $local, int $bytes): ?string
	{
		foreach ($el->childNodes as $c) {
			if ($c instanceof \DOMElement && $c->localName === $local) {
				$t = trim($c->textContent);
				return $t === '' ? null : self::cut($t, $bytes);
			}
		}
		return null;
	}

	private static function cut(string $s, int $bytes): string
	{
		return mb_strcut($s, 0, $bytes, 'UTF-8');
	}
}
