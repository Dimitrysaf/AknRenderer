<?php
/**
 * Applies a tagged amendment instruction to a target document's AKN XML,
 * returning the consolidated XML. A PURE transform: it does not validate the
 * result against the schema (the caller does that before saving) and it does
 * not touch the database or save a revision.
 *
 * Implemented:
 *   - repeal  — remove the target provision.
 *   - replace — substitute the target provision with the source provision
 *               (taken from the amending gazette), keeping the target's eId.
 *   - insert  — add the source provision immediately after the target.
 *   - renumber — regenerate canonical Greek num/eId across the whole body
 *     (via AknNumbering) and remap internal hrefs.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

class Consolidator
{
	/**
	 * @param string $targetXml the target document's current XML
	 * @param string $action replace|insert|repeal|renumber
	 * @param string|null $targetEid provision in the target being changed
	 * @param string|null $sourceXml the amending (gazette) document's XML —
	 *   required for replace/insert, ignored for repeal
	 * @param string|null $sourceEid provision in the source to pull in
	 * @return string the consolidated XML (no XML declaration)
	 * @throws ConsolidationException
	 */
	public static function apply(
		string $targetXml,
		string $action,
		?string $targetEid,
		?string $sourceXml = null,
		?string $sourceEid = null
	): string {
		switch ($action) {
			case 'repeal':
				return self::repeal($targetXml, $targetEid);
			case 'replace':
				return self::replace($targetXml, $targetEid, $sourceXml, $sourceEid);
			case 'insert':
				return self::insert($targetXml, $targetEid, $sourceXml, $sourceEid);
			case 'renumber':
				return self::renumber($targetXml);
			default:
				throw new ConsolidationException('pendingamendments-error-unsupported');
		}
	}

	/**
	 * Repeal: remove the element carrying $targetEid from the document.
	 */
	private static function repeal(string $xml, ?string $targetEid): string
	{
		$dom = self::parseTarget($xml);
		$el = self::requireTarget($dom, $targetEid);
		$el->parentNode->removeChild($el);
		return self::serialize($dom);
	}

	/**
	 * Replace: swap the target provision for the source provision (imported from
	 * the amending gazette), keeping the target's eId so citations stay stable.
	 *
	 * Note: descendant eIds of the imported provision are kept as authored in the
	 * gazette (not re-prefixed under the target eId) — a canonical-renumber pass
	 * is a separate concern. The result still passes schema validation; the
	 * reviewer sees it in the diff before approving.
	 */
	private static function replace(string $xml, ?string $targetEid, ?string $sourceXml, ?string $sourceEid): string
	{
		$dom = self::parseTarget($xml);
		$target = self::requireTarget($dom, $targetEid);
		$imported = self::importSource($dom, $sourceXml, $sourceEid);
		$imported->setAttribute('eId', $targetEid);
		$target->parentNode->replaceChild($imported, $target);
		return self::serialize($dom);
	}

	/**
	 * Insert: add the source provision immediately after the target provision.
	 * The inserted provision keeps its source eId (canonical renumber is a
	 * separate pass).
	 */
	private static function insert(string $xml, ?string $targetEid, ?string $sourceXml, ?string $sourceEid): string
	{
		$dom = self::parseTarget($xml);
		$target = self::requireTarget($dom, $targetEid);
		$imported = self::importSource($dom, $sourceXml, $sourceEid);
		if ($target->nextSibling !== null) {
			$target->parentNode->insertBefore($imported, $target->nextSibling);
		} else {
			$target->parentNode->appendChild($imported);
		}
		return self::serialize($dom);
	}

	/**
	 * Renumber: regenerate canonical num/eId across the target's whole body
	 * (via AknNumbering) and remap internal hrefs. targetEid is not used — a
	 * renumber amendment re-sequences the entire document.
	 */
	private static function renumber(string $xml): string
	{
		$dom = self::parseTarget($xml);
		$root = AknDom::findRoot($dom);
		if ($root === null) {
			throw new ConsolidationException('pendingamendments-error-parse');
		}
		$body = self::findBody($root);
		if ($body === null) {
			throw new ConsolidationException('pendingamendments-error-not-found');
		}
		AknNumbering::apply($dom, $body);
		return self::serialize($dom);
	}

	/** The document's main-body container (a direct child of the root). */
	private static function findBody(\DOMElement $root): ?\DOMElement
	{
		foreach ($root->childNodes as $child) {
			if (
				$child instanceof \DOMElement
				&& in_array($child->localName, AknVocabulary::MAIN_BODY_CONTAINERS, true)
			) {
				return $child;
			}
		}
		return null;
	}

	/** Parse the target document or fail. */
	private static function parseTarget(string $xml): \DOMDocument
	{
		$dom = AknDom::parse($xml);
		if ($dom === null || $dom->documentElement === null) {
			throw new ConsolidationException('pendingamendments-error-parse');
		}
		return $dom;
	}

	/** The target element (by eId), or fail. */
	private static function requireTarget(\DOMDocument $dom, ?string $targetEid): \DOMElement
	{
		if ($targetEid === null || $targetEid === '') {
			throw new ConsolidationException('pendingamendments-error-no-target');
		}
		$el = self::findByEid($dom, $targetEid);
		if ($el === null || $el->parentNode === null) {
			throw new ConsolidationException('pendingamendments-error-not-found');
		}
		return $el;
	}

	/** Import the source provision (by eId, from the gazette) into $dom. */
	private static function importSource(\DOMDocument $dom, ?string $sourceXml, ?string $sourceEid): \DOMElement
	{
		if ($sourceXml === null || $sourceXml === '' || $sourceEid === null || $sourceEid === '') {
			throw new ConsolidationException('pendingamendments-error-no-source');
		}
		$sdom = AknDom::parse($sourceXml);
		if ($sdom === null) {
			throw new ConsolidationException('pendingamendments-error-parse');
		}
		$source = self::findByEid($sdom, $sourceEid);
		if ($source === null) {
			throw new ConsolidationException('pendingamendments-error-no-source');
		}
		$imported = $dom->importNode($source, true);
		if (!$imported instanceof \DOMElement) {
			throw new ConsolidationException('pendingamendments-error-parse');
		}
		return $imported;
	}

	/** Serialise the root element (no XML prolog), matching stored form. */
	private static function serialize(\DOMDocument $dom): string
	{
		$out = $dom->saveXML($dom->documentElement);
		if ($out === false) {
			throw new ConsolidationException('pendingamendments-error-parse');
		}
		return $out;
	}

	/**
	 * The single AKN element bearing $eid, or null. eIds are restricted tokens
	 * ([A-Za-z0-9_.\-]); anything else can't match and is treated as not found
	 * (also closes off XPath-literal injection).
	 */
	private static function findByEid(\DOMDocument $dom, string $eid): ?\DOMElement
	{
		if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $eid)) {
			return null;
		}
		$xp = new \DOMXPath($dom);
		$xp->registerNamespace('a', AknSchema::NS);
		$nodes = $xp->query('//a:*[@eId="' . $eid . '"]');
		if ($nodes === false || $nodes->length === 0) {
			return null;
		}
		$node = $nodes->item(0);
		return $node instanceof \DOMElement ? $node : null;
	}
}
