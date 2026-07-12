<?php
/**
 * Canonical Greek numbering for an AKN document body: rewrites <num> text
 * (Μέρος Πρώτο, Κεφάλαιο, Άρθρο N, paragraphs 1./2., points α)/β)…) and the
 * hierarchical eIds of every division/provision, in place, and remaps any
 * @href that pointed at a changed eId.
 *
 * This is a hand-kept PHP port of the editor's JS `aknAutoNumber`
 * (ext.aknEditor.numbering.js). The two are the only copies of the Greek
 * numbering rules; the display-type ordering is derived from
 * AknVocabulary::HEADING_LEVELS (shared), but the num formats / Greek ordinals
 * are presentation and duplicated between the JS editor and here — keep them in
 * sync.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

class AknNumbering
{
	private const GREEK_ORDINALS = [
		'Πρώτο', 'Δεύτερο', 'Τρίτο', 'Τέταρτο', 'Πέμπτο', 'Έκτο', 'Έβδομο', 'Όγδοο',
		'Ένατο', 'Δέκατο', 'Ενδέκατο', 'Δωδέκατο', 'Δέκατο Τρίτο', 'Δέκατο Τέταρτο',
		'Δέκατο Πέμπτο', 'Δέκατο Έκτο', 'Δέκατο Έβδομο', 'Δέκατο Όγδοο', 'Δέκατο Ένατο', 'Εικοστό',
	];
	private const GREEK_LETTERS = [
		'Α', 'Β', 'Γ', 'Δ', 'Ε', 'ΣΤ', 'Ζ', 'Η', 'Θ', 'Ι', 'ΙΑ', 'ΙΒ', 'ΙΓ', 'ΙΔ',
		'ΙΕ', 'ΙΣΤ', 'ΙΖ', 'ΙΗ', 'ΙΘ', 'Κ', 'ΚΑ', 'ΚΒ', 'ΚΓ', 'ΚΔ', 'ΚΕ', 'ΚΣΤ', 'ΚΖ', 'ΚΗ', 'ΚΘ', 'Λ',
	];
	private const GREEK_LOWER = [
		'α', 'β', 'γ', 'δ', 'ε', 'στ', 'ζ', 'η', 'θ', 'ι', 'ια', 'ιβ', 'ιγ', 'ιδ',
		'ιε', 'ιστ', 'ιζ', 'ιη', 'ιθ', 'κ', 'κα', 'κβ', 'κγ', 'κδ', 'κε', 'κστ', 'κζ', 'κη', 'κθ', 'λ',
	];
	private const EID_PREFIX = [
		'part' => 'part', 'section' => 'section', 'subsection' => 'subsec',
		'chapter' => 'chapter', 'subchapter' => 'subchap', 'article' => 'art',
	];

	/**
	 * Renumber $body in place. Returns the old→new eId remap ('#old' => '#new').
	 *
	 * @return array<string,string>
	 */
	public static function apply(\DOMDocument $dom, \DOMElement $body): array
	{
		$remap = [];
		$global = [];
		$articles = [];
		self::walk($dom, $body, $global, $articles, $remap);
		foreach ($articles as $article) {
			self::renumberArticleInternals($dom, $article, $remap);
		}
		if ($remap !== []) {
			$xp = new \DOMXPath($dom);
			foreach ($xp->query('//*[@href]') as $el) {
				$href = $el->getAttribute('href');
				if (isset($remap[$href])) {
					$el->setAttribute('href', $remap[$href]);
				}
			}
		}
		return $remap;
	}

	/** Display division types, ordered outermost-first, from the shared vocabulary. */
	private static function displayTypes(): array
	{
		$levels = AknVocabulary::HEADING_LEVELS;
		asort($levels);
		return array_keys($levels);
	}

	/**
	 * @param array<string,int> &$global
	 * @param list<\DOMElement> &$articles
	 * @param array<string,string> &$remap
	 */
	private static function walk(\DOMDocument $dom, \DOMElement $parent, array &$global, array &$articles, array &$remap): void
	{
		$display = self::displayTypes();
		$scope = [];
		foreach (self::childElements($parent) as $child) {
			$type = $child->localName;
			if (!in_array($type, $display, true)) {
				continue;
			}
			$global[$type] = ($global[$type] ?? 0) + 1;
			$scope[$type] = ($scope[$type] ?? 0) + 1;
			// Articles and parts number globally; nested divisions per-scope.
			$numIndex = ($type === 'article' || $type === 'part') ? $global[$type] : $scope[$type];
			self::setNum($dom, $child, self::numFormat($type, $numIndex));
			self::assignEid($child, self::EID_PREFIX[$type] . '_' . $global[$type], $remap);
			if ($type === 'article') {
				$articles[] = $child;
			} else {
				self::walk($dom, $child, $global, $articles, $remap);
			}
		}
	}

	/** @param array<string,string> &$remap */
	private static function renumberArticleInternals(\DOMDocument $dom, \DOMElement $article, array &$remap): void
	{
		$articleEid = $article->getAttribute('eId');
		$paraIndex = 0;
		foreach (self::childElements($article) as $child) {
			if ($child->localName !== 'paragraph') {
				continue;
			}
			$paraIndex++;
			self::setNum($dom, $child, $paraIndex . '.');
			$paraEid = $articleEid . '__para_' . $paraIndex;
			self::assignEid($child, $paraEid, $remap);
			$subIndex = 0;
			foreach (self::childElements($child) as $sub) {
				if ($sub->localName === 'subparagraph') {
					$subIndex++;
					self::assignEid($sub, $paraEid . '__subpar_' . $subIndex, $remap);
				}
			}
			$content = self::firstChild($child, 'content');
			if ($content !== null) {
				self::renumberPoints($dom, $content, $paraEid, 1, $remap);
			}
			self::renumberPoints($dom, $child, $paraEid, 1, $remap);
		}
	}

	/** @param array<string,string> &$remap */
	private static function renumberPoints(\DOMDocument $dom, \DOMElement $container, string $parentEid, int $depth, array &$remap): void
	{
		$listIndex = 0;
		foreach (self::childElements($container) as $child) {
			if ($child->localName !== 'list') {
				continue;
			}
			$listIndex++;
			$listEid = $parentEid . '__list_' . $listIndex;
			self::assignEid($child, $listEid, $remap);
			$pointIndex = 0;
			foreach (self::childElements($child) as $point) {
				if ($point->localName !== 'point') {
					continue;
				}
				$pointIndex++;
				$letter = self::pointNumFor($pointIndex, $depth);
				self::setNum($dom, $point, $letter . ')');
				$pointEid = $listEid . '__point_' . $letter;
				self::assignEid($point, $pointEid, $remap);
				$pointContent = self::firstChild($point, 'content');
				if ($pointContent !== null) {
					self::renumberPoints($dom, $pointContent, $pointEid, $depth + 1, $remap);
				}
				self::renumberPoints($dom, $point, $pointEid, $depth + 1, $remap);
			}
		}
	}

	private static function numFormat(string $type, int $n): string
	{
		switch ($type) {
			case 'part':
				return 'Μέρος ' . self::ordinal($n);
			case 'section':
				return 'Τμήμα ' . self::letter($n);
			case 'subsection':
				return 'Υποτμήμα ' . self::letter($n);
			case 'chapter':
				return 'Κεφάλαιο ' . self::ordinal($n);
			case 'subchapter':
				return 'Υποκεφάλαιο ' . self::letter($n);
			case 'article':
				return 'Άρθρο ' . $n;
			default:
				return (string)$n;
		}
	}

	private static function pointNumFor(int $index, int $depth): string
	{
		$letter = self::lowerLetter($index);
		if ($depth > 1) {
			$letter .= $letter;
		}
		return $letter;
	}

	private static function ordinal(int $n): string
	{
		return self::GREEK_ORDINALS[$n - 1] ?? (string)$n;
	}

	private static function letter(int $n): string
	{
		return (self::GREEK_LETTERS[$n - 1] ?? (string)$n) . "'";
	}

	private static function lowerLetter(int $n): string
	{
		return self::GREEK_LOWER[$n - 1] ?? (string)$n;
	}

	/** @param array<string,string> &$remap */
	private static function assignEid(\DOMElement $el, string $eid, array &$remap): void
	{
		$old = $el->getAttribute('eId');
		if ($old !== '' && $old !== $eid) {
			$remap['#' . $old] = '#' . $eid;
		}
		$el->setAttribute('eId', $eid);
	}

	private static function setNum(\DOMDocument $dom, \DOMElement $el, string $text): void
	{
		$num = self::firstChild($el, 'num');
		if ($num === null) {
			$num = $dom->createElementNS(AknSchema::NS, 'num');
			$el->insertBefore($num, $el->firstChild);
		}
		$num->textContent = $text;
	}

	private static function firstChild(\DOMElement $el, string $local): ?\DOMElement
	{
		foreach ($el->childNodes as $c) {
			if ($c instanceof \DOMElement && $c->localName === $local) {
				return $c;
			}
		}
		return null;
	}

	/** @return list<\DOMElement> */
	private static function childElements(\DOMElement $parent): array
	{
		$out = [];
		foreach ($parent->childNodes as $c) {
			if ($c instanceof \DOMElement) {
				$out[] = $c;
			}
		}
		return $out;
	}
}
