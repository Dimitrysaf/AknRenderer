<?php
/**
 * Builds a fresh, schema-valid Akoma Ntoso document seed.
 *
 * The creation wizard (AknEditor's Special:NewAkn) and the blank-page editor
 * path both need to start from a document that already satisfies
 * schema/akomantoso30.xsd — i.e. that carries all three mandatory FRBR levels
 * (Work/Expression/Manifestation), each with its required coreProperties
 * (FRBRthis/uri/date/author) plus the level-specific FRBRcountry / FRBRlanguage,
 * in the exact element order the schema's <xsd:sequence>s demand, and in the
 * schema's own namespace (AknSchema::NS). Getting any of that wrong means the
 * save is rejected by HookHandler::onEditFilterMergedContent.
 *
 * The set of document types it can seed is NOT hand-listed: it is the schema's
 * own documentType roots (AknSchema::documentTypes()) whose content-model
 * structure (AknSchema::documentStructures()) is one this builder knows how to
 * fill — hierarchical (<body>), open (<mainBody>) or collection
 * (<collectionBody>). So the wizard only ever offers real XSD document types.
 *
 * The output follows the same Greek /akn/gr/... URI and metadata conventions as
 * the existing corpus (see tests/fixtures) — plain OASIS AKN 3.0, with no
 * LEOS-specific attributes, profiles or namespaces. Asserted valid by
 * AknSkeletonTest against AknSchema::isValid(); attribute names match
 * MetaExtractor so a seed also indexes correctly.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

class AknSkeleton
{

	/** The schema's namespace — the one namespace every element MUST carry. */
	private const NS = AknSchema::NS;

	/**
	 * The schema structure types this builder can fill, each mapped to the body
	 * element that structure requires. Any documentType root whose structure
	 * (per AknSchema::documentStructures()) is one of these can be seeded; the
	 * rest (debate/judgment/amendment/portion, with their own body models) are
	 * not offered until explicitly supported.
	 *
	 * @var array<string,string>
	 */
	private const STRUCTURE_BODY = [
		'hierarchicalStructure' => 'body',
		'openStructure' => 'mainBody',
		'collectionStructure' => 'collectionBody',
	];

	/**
	 * The XSD document-type roots this builder can seed — the schema's own
	 * documentType list, filtered to the structures we know how to fill.
	 *
	 * @return list<string>
	 */
	public static function supportedRoots(): array
	{
		$roots = [];
		foreach (AknSchema::documentStructures() as $root => $structure) {
			if (isset(self::STRUCTURE_BODY[$structure])) {
				$roots[] = $root;
			}
		}
		return $roots;
	}

	/**
	 * Build a schema-valid AKN document.
	 *
	 * @param array<string,mixed> $spec Recognised keys (all optional):
	 *   root      an XSD documentType root, e.g. 'act'/'doc'/'officialGazette'
	 *             (default 'act'; unsupported roots fall back to 'act')
	 *   name      the root's @name / Greek subtype, e.g. 'nomos'/'pd'/'fek'
	 *   subtype   FRBRsubtype value (defaults to name for non-collection roots)
	 *   country   ISO code, default 'gr'
	 *   language  ISO 639 code, default 'ell'
	 *   number    document number, e.g. '5300'
	 *   enacted   YYYY-MM-DD; defaults to today (the schema forbids an empty date)
	 *   alias     human title (the number-form citation, e.g. 'Νόμος 5300/2026')
	 *   author    issuing body's display name
	 *   authorEid internal anchor eId for the author reference
	 *   authorHref ontology IRI for the author
	 *   fekSeries ΦΕΚ τεύχος (Α, Β, …) — carried on publication/@name
	 *   fekNumber ΦΕΚ number
	 *   fekDate   ΦΕΚ date (YYYY-MM-DD)
	 *   fekShowAs publication display label
	 * @return string The document XML (no XML declaration).
	 */
	public static function build(array $spec): string
	{
		$structures = AknSchema::documentStructures();

		$root = (string)($spec['root'] ?? 'act');
		if (!in_array($root, self::supportedRoots(), true)) {
			$root = 'act';
		}
		$structure = $structures[$root] ?? 'hierarchicalStructure';
		$bodyElement = self::STRUCTURE_BODY[$structure] ?? 'body';
		$isCollection = $structure === 'collectionStructure';
		$isGazette = $root === 'officialGazette';

		$str = static fn($key, $default = '') => trim((string)($spec[$key] ?? $default));

		// @name is required on the document root (some roots, e.g. amendmentList,
		// have it as a mandatory attribute). Default to a Greek subtype where we
		// have one, else the root's own name.
		$name = $str('name', $isGazette ? 'fek' : ($root === 'act' ? 'nomos' : $root));
		$country = $str('country', 'gr') ?: 'gr';
		$language = $str('language', 'ell') ?: 'ell';
		$number = $str('number');
		$subtype = $str('subtype', $isCollection ? '' : $name);
		// The schema types @date as xsd:date|xsd:dateTime — an empty value is
		// invalid, so a seed always carries a real date (today, if unset).
		$enacted = self::normaliseDate($str('enacted')) ?: date('Y-m-d');
		$alias = $str('alias');

		$authorEid = $str('authorEid', $isGazette ? 'ethnikotypografeio' : 'vouli');
		$authorName = $str('author', $isGazette ? 'Εθνικό Τυπογραφείο' : 'Βουλή των Ελλήνων');
		$authorHref = $str('authorHref', '/ontology/organization/' . $country . '/' . $authorEid);

		$fekSeries = $str('fekSeries');
		$fekNumber = $str('fekNumber');
		$fekDate = self::normaliseDate($str('fekDate'));
		$fekShowAs = $str('fekShowAs', 'Εφημερίδα της Κυβερνήσεως');

		// FRBRWork/Expression carry an "enactment" (or, for a gazette, a
		// "publication") date; the Manifestation is "generation".
		$primaryDateName = $isGazette ? 'publication' : 'enactment';

		// --- FRBR URIs, following the corpus's /akn/gr/... convention -------
		if ($isGazette) {
			$id = $fekSeries !== ''
				? $fekSeries . ($fekNumber !== '' ? '-' . $fekNumber : '')
				: $number;
			$workSegs = [$country, 'officialGazette', $enacted, $id];
		} else {
			$workSegs = [$country, $root, $name, $enacted, $number];
		}
		$work = '/akn/' . implode('/', array_filter($workSegs, static fn($s) => $s !== ''));
		$workThis = $work . '/!main';
		$expr = $work . '/' . $language . '@';
		$exprThis = $expr . '/!main';
		$manif = $expr . '.xml';
		$manifThis = $expr . '/!main.xml';

		// --- build the DOM (guarantees correct namespace + XML escaping) ----
		$dom = new \DOMDocument('1.0', 'UTF-8');
		$dom->formatOutput = false;
		$ns = self::NS;
		$make = static function (string $elName, array $attrs = [], ?string $text = null) use ($dom, $ns) {
			$el = $dom->createElementNS($ns, $elName);
			foreach ($attrs as $k => $v) {
				$el->setAttribute($k, (string)$v);
			}
			if ($text !== null) {
				$el->appendChild($dom->createTextNode($text));
			}
			return $el;
		};

		$akn = $make('akomaNtoso');
		$dom->appendChild($akn);

		$rootAttrs = ['name' => $name];
		if ($isGazette) {
			$rootAttrs['contains'] = 'originalVersion';
		}
		$rootEl = $make($root, $rootAttrs);
		$akn->appendChild($rootEl);

		$meta = $make('meta');
		$rootEl->appendChild($meta);

		$identification = $make('identification', ['source' => '#' . $authorEid]);
		$meta->appendChild($identification);

		// FRBRWork — coreProperties (this, uri, alias?, date, author) then
		// workProperties (country, subtype?, number?), in schema order.
		$workEl = $make('FRBRWork');
		$workEl->appendChild($make('FRBRthis', ['value' => $workThis]));
		$workEl->appendChild($make('FRBRuri', ['value' => $work]));
		if ($alias !== '') {
			$workEl->appendChild($make('FRBRalias', ['name' => 'commonName', 'value' => $alias]));
		}
		$workEl->appendChild($make('FRBRdate', ['date' => $enacted, 'name' => $primaryDateName]));
		$workEl->appendChild($make('FRBRauthor', ['href' => '#' . $authorEid]));
		$workEl->appendChild($make('FRBRcountry', ['value' => $country]));
		if ($subtype !== '') {
			$workEl->appendChild($make('FRBRsubtype', ['value' => $subtype]));
		}
		if ($number !== '') {
			$workEl->appendChild($make('FRBRnumber', ['value' => $number]));
		}
		$identification->appendChild($workEl);

		// FRBRExpression — coreProperties then exprProperties (FRBRlanguage).
		$exprEl = $make('FRBRExpression');
		$exprEl->appendChild($make('FRBRthis', ['value' => $exprThis]));
		$exprEl->appendChild($make('FRBRuri', ['value' => $expr]));
		$exprEl->appendChild($make('FRBRdate', ['date' => $enacted, 'name' => $primaryDateName]));
		$exprEl->appendChild($make('FRBRauthor', ['href' => '#' . $authorEid]));
		$exprEl->appendChild($make('FRBRlanguage', ['language' => $language]));
		$identification->appendChild($exprEl);

		// FRBRManifestation — coreProperties (manifProperties are all optional).
		$manifEl = $make('FRBRManifestation');
		$manifEl->appendChild($make('FRBRthis', ['value' => $manifThis]));
		$manifEl->appendChild($make('FRBRuri', ['value' => $manif]));
		$manifEl->appendChild($make('FRBRdate', ['date' => $enacted, 'name' => 'generation']));
		$manifEl->appendChild($make('FRBRauthor', ['href' => '#' . $authorEid]));
		$identification->appendChild($manifEl);

		// publication — only when a ΦΕΚ τεύχος is known (its @name/@date/@showAs
		// are all schema-required, so a half-filled publication would be invalid).
		if ($fekSeries !== '') {
			$pubAttrs = [
				'date' => $fekDate !== '' ? $fekDate : $enacted,
				'showAs' => $fekShowAs,
				'name' => $fekSeries,
			];
			if ($fekNumber !== '') {
				$pubAttrs['number'] = $fekNumber;
			}
			$meta->appendChild($make('publication', $pubAttrs));
		}

		// references — the author organisation the FRBRauthor href points at.
		$references = $make('references', ['source' => '#' . $authorEid]);
		$references->appendChild($make('TLCOrganization', [
			'eId' => $authorEid,
			'href' => $authorHref,
			'showAs' => $authorName,
		]));
		$meta->appendChild($references);

		// --- body -----------------------------------------------------------
		if ($isCollection) {
			// collectionBody requires ≥1 component; seed one placeholder
			// documentRef the editor's gazette workspace then replaces.
			$body = $make($bodyElement, ['eId' => 'collectionBody']);
			$component = $make('component', ['eId' => 'collectionBody__cmp_1']);
			$component->appendChild($make('documentRef', [
				'eId' => 'collectionBody__cmp_1__dr_1',
				'href' => '#',
				'showAs' => $alias !== '' ? $alias : '—',
			]));
			$body->appendChild($component);
			$rootEl->appendChild($body);
		} else {
			$body = $make($bodyElement);
			$article = $make('article', ['eId' => 'art_1']);
			$article->appendChild($make('num', [], 'Άρθρο 1'));
			$paragraph = $make('paragraph', ['eId' => 'art_1__para_1']);
			$paragraph->appendChild($make('num', [], '1.'));
			$content = $make('content');
			$content->appendChild($make('p'));
			$paragraph->appendChild($content);
			$article->appendChild($paragraph);
			$body->appendChild($article);
			$rootEl->appendChild($body);
		}

		// Serialise the root node only, so there is no XML prolog/declaration —
		// matching what the editor itself stores (XMLSerializer output).
		return $dom->saveXML($dom->documentElement) ?: '';
	}

	/**
	 * Reduce a date-ish input to a bare YYYY-MM-DD if it starts with one, else
	 * return it unchanged (build() supplies today's date when this is empty).
	 * Keeps a wizard value like "2026-01-15T00:00:00Z" schema-valid-friendly.
	 */
	private static function normaliseDate(string $date): string
	{
		if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date, $m)) {
			return $m[0];
		}
		return $date;
	}
}
