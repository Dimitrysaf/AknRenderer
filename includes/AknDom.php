<?php
/**
 * Shared DOM helpers for AKN documents.
 *
 * Every extractor (and the content handler) needs to parse the page's XML
 * and find its real document-type root before doing anything else — kept
 * in one place so the "don't get fooled by a <component>'s embedded
 * document" rule can't drift out of sync between them the way it briefly
 * did (see MetaExtractor's docType bug, fixed by restricting the root
 * search to a direct child of <akomaNtoso>).
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

class AknDom
{

	/**
	 * Parse $xml into a DOMDocument, or null if it isn't well-formed.
	 *
	 * @param string $xml
	 * @return \DOMDocument|null
	 */
	public static function parse(string $xml): ?\DOMDocument
	{
		if (trim($xml) === '') {
			return null;
		}
		$dom = new \DOMDocument();
		$prev = libxml_use_internal_errors(true);
		$ok = $dom->loadXML($xml, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		return $ok ? $dom : null;
	}

	/**
	 * The document-type element (any of the schema's documentType roots:
	 * act, bill, doc, officialGazette, judgment, debate, …) — a DIRECT
	 * child of <akomaNtoso>. Deliberately not a getElementsByTagName() over
	 * the whole document: that searches the entire subtree, and would
	 * wrongly match a nested <doc>/<act> that a <component> embeds deep
	 * inside a gazette's <collectionBody> instead of the real, outer root.
	 *
	 * @param \DOMDocument $dom
	 * @return \DOMElement|null
	 */
	public static function findRoot(\DOMDocument $dom): ?\DOMElement
	{
		$aknRoot = $dom->documentElement;
		if (!$aknRoot instanceof \DOMElement) {
			return null;
		}
		$rootTypes = AknSchema::documentTypes();
		foreach ($aknRoot->childNodes as $child) {
			if ($child instanceof \DOMElement && in_array($child->localName, $rootTypes, true)) {
				return $child;
			}
		}
		return null;
	}

	/**
	 * The document's own <meta>, scoped to findRoot() so it likewise can't
	 * match a <meta> nested inside a <component>'s embedded document.
	 *
	 * @param \DOMDocument $dom
	 * @return \DOMElement|null
	 */
	public static function findMeta(\DOMDocument $dom): ?\DOMElement
	{
		$root = self::findRoot($dom);
		if ($root === null) {
			return null;
		}
		$meta = $root->getElementsByTagName('meta')->item(0);
		return $meta instanceof \DOMElement ? $meta : null;
	}
}
