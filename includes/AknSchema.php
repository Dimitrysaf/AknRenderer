<?php
/**
 * The Akoma Ntoso schema, as the single source of truth for the whole
 * extension.
 *
 * Everything the renderer and the extractors need to know about the AKN
 * vocabulary — which elements are document roots, which are hierarchical
 * containers, which are semantic inlines, and so on — is DERIVED here by
 * reading the vendored schema/akomantoso30.xsd, never hand-maintained. The
 * same file also drives real XSD validation (validate()/isValid()), so a
 * document is "AKN" iff libxml says it satisfies this schema.
 *
 * The schema is Akoma Ntoso 3.0 WD17 (OASIS, 15/10/2016). Its target
 * namespace — and therefore the namespace every document MUST use — is
 * exposed as self::NS. The lists are read from the schema's <xsd:group>
 * definitions (documentType, ANhier, ANsemanticInline, ...) so that adding
 * or removing an element in the schema automatically flows through to the
 * code with no edit here.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

class AknSchema
{

	/** The AKN target namespace declared by the vendored schema (WD17). */
	public const NS = 'http://docs.oasis-open.org/legaldocml/ns/akn/3.0/WD17';

	/** The W3C XML Schema namespace, for reading the .xsd itself. */
	private const XSD_NS = 'http://www.w3.org/2001/XMLSchema';

	/** Parsed schema document, loaded once per process. */
	private static ?\DOMDocument $schemaDom = null;

	/** Memoised group-name → list<string> of element local names. */
	private static array $groupCache = [];

	/** Absolute path to the vendored schema (the source of truth). */
	public static function path(): string
	{
		return dirname(__DIR__) . '/schema/akomantoso30.xsd';
	}

	// ------------------------------------------------------------ vocabulary

	/** Document-type root elements (the schema's documentType group). */
	public static function documentTypes(): array
	{
		return self::elementsInGroup('documentType');
	}

	/**
	 * Native hierarchical elements (the schema's ANhier group): the elements
	 * that make up the AKN legislative hierarchy (article, chapter, point,
	 * ...). The generic <hcontainer> is deliberately not in this list — the
	 * schema keeps it separate (group hierElements = ANhier + hcontainer) and
	 * so does the renderer.
	 */
	public static function hierarchicalTypes(): array
	{
		return self::elementsInGroup('ANhier');
	}

	/**
	 * Semantic inline elements (the schema's ANsemanticInline group): the
	 * ontology-bearing inlines (person, organization, date, term, ...).
	 */
	public static function semanticInlines(): array
	{
		return self::elementsInGroup('ANsemanticInline');
	}

	/**
	 * Every element local name of a named <xsd:group>, resolving nested
	 * <xsd:group ref="..."> recursively. Order follows the schema; duplicates
	 * are removed. Unknown group name → empty list.
	 *
	 * @param string $group
	 * @return list<string>
	 */
	public static function elementsInGroup(string $group): array
	{
		if (array_key_exists($group, self::$groupCache)) {
			return self::$groupCache[$group];
		}
		$out = [];
		self::collectGroup($group, $out, []);
		$result = array_values(array_unique($out));
		self::$groupCache[$group] = $result;
		return $result;
	}

	/**
	 * @param string $group
	 * @param list<string> &$out
	 * @param array<string,true> $seen Group names already expanded (cycle guard).
	 */
	private static function collectGroup(string $group, array &$out, array $seen): void
	{
		if (isset($seen[$group])) {
			return;
		}
		$seen[$group] = true;

		$node = self::groupNode($group);
		if ($node === null) {
			return;
		}

		foreach ($node->getElementsByTagNameNS(self::XSD_NS, 'element') as $el) {
			$ref = $el->getAttribute('ref');
			if ($ref !== '') {
				$out[] = self::localName($ref);
			}
		}
		foreach ($node->getElementsByTagNameNS(self::XSD_NS, 'group') as $g) {
			$ref = $g->getAttribute('ref');
			if ($ref !== '') {
				self::collectGroup(self::localName($ref), $out, $seen);
			}
		}
	}

	/** The <xsd:group name="$name"> definition node, or null. */
	private static function groupNode(string $name): ?\DOMElement
	{
		$dom = self::schemaDom();
		if ($dom === null) {
			return null;
		}
		foreach ($dom->documentElement->getElementsByTagNameNS(self::XSD_NS, 'group') as $g) {
			if ($g instanceof \DOMElement && $g->getAttribute('name') === $name) {
				return $g;
			}
		}
		return null;
	}

	/** Strip any namespace prefix from a QName ("akn:clause" → "clause"). */
	private static function localName(string $qname): string
	{
		$pos = strpos($qname, ':');
		return $pos === false ? $qname : substr($qname, $pos + 1);
	}

	private static function schemaDom(): ?\DOMDocument
	{
		if (self::$schemaDom === null) {
			$dom = new \DOMDocument();
			$prev = libxml_use_internal_errors(true);
			$ok = @$dom->load(self::path(), LIBXML_NONET);
			libxml_clear_errors();
			libxml_use_internal_errors($prev);
			self::$schemaDom = $ok ? $dom : null;
		}
		return self::$schemaDom;
	}

	// ------------------------------------------------------------ validation

	/**
	 * Validate $xml against the schema.
	 *
	 * @param string $xml
	 * @return list<string> Human-readable error lines; empty means valid.
	 *   An empty/whitespace string is treated as having no schema errors
	 *   (blankness is a concern for the caller, not for the schema gate).
	 */
	public static function validate(string $xml): array
	{
		if (trim($xml) === '') {
			return [];
		}

		$prev = libxml_use_internal_errors(true);
		libxml_clear_errors();

		$dom = new \DOMDocument();
		if (!$dom->loadXML($xml, LIBXML_NONET)) {
			$errors = self::collectLibxmlErrors();
			libxml_use_internal_errors($prev);
			return $errors !== [] ? $errors : ['The document is not well-formed XML.'];
		}

		$ok = @$dom->schemaValidate(self::path());
		$errors = $ok ? [] : self::collectLibxmlErrors();

		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		// schemaValidate can fail without emitting a structured error (e.g. the
		// schema file itself is unreadable). Never report "valid" in that case.
		if (!$ok && $errors === []) {
			return ['The document could not be validated against the Akoma Ntoso schema.'];
		}
		return $errors;
	}

	/** True iff $xml validates against the schema. */
	public static function isValid(string $xml): bool
	{
		return self::validate($xml) === [];
	}

	/**
	 * Drain libxml's error buffer into "line N: message" strings.
	 *
	 * @return list<string>
	 */
	private static function collectLibxmlErrors(): array
	{
		$out = [];
		foreach (libxml_get_errors() as $err) {
			$msg = trim($err->message);
			$out[] = $err->line > 0 ? 'line ' . $err->line . ': ' . $msg : $msg;
		}
		return $out;
	}
}
