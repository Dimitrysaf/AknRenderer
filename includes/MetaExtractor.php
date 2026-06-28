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

	/** Greek document-type labels, keyed by lowercased AKN @name/@subtype. */
	private const DOC_TYPES = [
		'nomos' => 'Νόμος',
		'νόμος' => 'Νόμος',
		'act' => 'Νομοθετική πράξη',
		'pd' => 'Προεδρικό Διάταγμα',
		'proedrikodiatagma' => 'Προεδρικό Διάταγμα',
		'pnp' => 'Πράξη Νομοθετικού Περιεχομένου',
		'ya' => 'Υπουργική Απόφαση',
		'kya' => 'Κοινή Υπουργική Απόφαση',
		'nomosplaisio' => 'Νόμος-Πλαίσιο',
	];

	/** Country labels, keyed by lowercased ISO code. */
	private const COUNTRIES = [
		'gr' => 'Ελλάδα',
		'cy' => 'Κύπρος',
		'eu' => 'Ευρωπαϊκή Ένωση',
	];

	/** Language labels, keyed by lowercased ISO 639 code. */
	private const LANGUAGES = [
		'ell' => 'Ελληνικά',
		'el' => 'Ελληνικά',
		'eng' => 'Αγγλικά',
		'en' => 'Αγγλικά',
		'fra' => 'Γαλλικά',
		'fre' => 'Γαλλικά',
		'deu' => 'Γερμανικά',
		'ger' => 'Γερμανικά',
	];

	/**
	 * Parse the document's <meta> into a flat data array, namespace-tolerant.
	 *
	 * @param string $xml
	 * @return array<string,mixed>|null Null when the XML is unparseable or has no <meta>.
	 */
	public static function fromXml(string $xml): ?array
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

		$meta = self::first($dom, 'meta');
		if ($meta === null) {
			return null;
		}

		$work = self::first($meta, 'FRBRWork');
		$expr = self::first($meta, 'FRBRExpression');
		$manif = self::first($meta, 'FRBRManifestation');
		$pub = self::first($meta, 'publication');
		$root = self::first($dom, 'act') ?? self::first($dom, 'bill') ?? self::first($dom, 'doc');

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
			'language' => self::attr($expr, 'FRBRlanguage', 'language'),
			'manifUri' => self::attr($manif, 'FRBRuri', 'value'),
			'pubShowAs' => $pub ? $pub->getAttribute('showAs') : '',
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
		$add('Τίτλος', (string) $d['alias']);
		$add('Τύπος εγγράφου', self::humanize(self::DOC_TYPES, (string) $d['docType']));
		$add('Αριθμός', (string) $d['number']);
		$add('Ημερομηνία θέσπισης', (string) $d['enacted']);
		$add('ΦΕΚ', (string) $d['pubShowAs']);
		$add('Αριθμός ΦΕΚ', (string) $d['pubNumber']);
		$add('Ημερομηνία δημοσίευσης', (string) $d['pubDate']);
		$add('Χώρα', self::humanize(self::COUNTRIES, (string) $d['country']));
		$add('Γλώσσα', self::humanize(self::LANGUAGES, (string) $d['language']));
		$add('Υποτύπος', self::humanize(self::DOC_TYPES, (string) $d['subtype']));
		$add('Εκδούσα αρχή', (string) $d['authorLabel']);
		$add('URI έργου (Work)', (string) $d['workUri']);
		$add('URI έκφρασης (Expression)', (string) $d['exprUri']);
		$add('URI εκδήλωσης (Manifestation)', (string) $d['manifUri']);
		if ($d['keywords']) {
			$add('Λέξεις-κλειδιά', implode(', ', $d['keywords']));
		}
		if ($d['events']) {
			$add('Ιστορικό', implode(' · ', $d['events']));
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
			'am_work_uri' => self::cut((string) $d['workUri'], 255),
			'am_expr_uri' => self::cut((string) $d['exprUri'], 255),
			'am_alias' => self::cut((string) $d['alias'], 255),
			'am_doc_type' => self::cut((string) $d['docType'], 64),
			'am_number' => self::cut((string) $d['number'], 64),
			'am_country' => self::cut((string) $d['country'], 8),
			'am_language' => self::cut((string) $d['language'], 16),
			'am_subtype' => self::cut((string) $d['subtype'], 64),
			'am_enacted' => self::cut((string) $d['enacted'], 32),
			'am_fek' => self::cut((string) $d['pubShowAs'], 255),
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
