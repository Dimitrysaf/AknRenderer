<?php
/**
 * ContentHandler for the akn-xml content model.
 */

namespace MediaWiki\Extension\AknRenderer;

use MediaWiki\Content\Content;
use MediaWiki\Content\Renderer\ContentParseParams;
use MediaWiki\Content\TextContentHandler;
use MediaWiki\Html\Html;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Page\PageProps\PagePropsEntityUpdate;

class AknContentHandler extends TextContentHandler
{
	private const AKN_NS = 'http://docs.oasis-open.org/legaldocml/ns/akn/3.0';

	private const CONTAINERS = [
		'part',
		'chapter',
		'section',
		'subsection',
		'article',
		'paragraph',
		'subparagraph',
		'list',
		'point',
		'indent',
		'alinea',
		'level'
	];
	private const HEADING_LEVELS = [
		'book' => 2,
		'part' => 2,
		'title' => 3,
		'chapter' => 3,
		'section' => 4,
		'subsection' => 4,
		'article' => 4
	];
	private const HCONTAINER_DIVISION = [
		'vivlio' => 'book',
		'meros' => 'part',
		'kefalaio' => 'chapter',
		'tmima' => 'section',
		'enotita' => 'section'
	];
	private const INLINE_SPANS = [
		'def',
		'term',
		'entity',
		'organization',
		'person',
		'role',
		'location',
		'quantity',
		'quotedText',
		'concept',
		'object'
	];

	private ?string $pendingNum = null;
	private array $footnotes = [];
	private int $noteCounter = 0;
	private array $tocEntries = [];
	private array $tocLevelStack = [];
	private array $tocCounters = [];
	private array $usedAnchors = [];

	public function __construct(string $modelId = CONTENT_MODEL_AKN)
	{
		parent::__construct($modelId, [CONTENT_FORMAT_XML, CONTENT_FORMAT_TEXT]);
	}

	public function getSecondaryDataUpdates(Content $content, ContentParseParams $cpoParams): array
	{
		$xml = $content->getText();
		$meta = self::extractFrbrMeta($xml);
		$updates = [];
		foreach ($meta as $key => $value) {
			$updates[] = new PagePropsEntityUpdate('akn-' . $key, $value);
		}
		return $updates;
	}

	protected function getContentClass(): string
	{
		return AknContent::class;
	}

	protected function fillParserOutput(Content $content, ContentParseParams $cpoParams, ParserOutput &$parserOutput)
	{
		if (!$cpoParams->getGenerateHtml()) {
			$parserOutput->setContentHolderText(null);
			return;
		}

		$xml = $content->getText();
		$this->resetState();

		$dom = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $xml !== '' && $dom->loadXML($xml, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if (!$loaded) {
			$parserOutput->setContentHolderText(Html::errorBox(wfMessage('aknrenderer-render-invalidxml')->inContentLanguage()->escaped()));
			return;
		}

		$body = $this->findBody($dom);
		if ($body === null) {
			$parserOutput->setContentHolderText(Html::warningBox(wfMessage('aknrenderer-render-nobody')->inContentLanguage()->escaped()));
			return;
		}

		$inner = $this->renderChildren($body) . $this->renderFootnotes();
		$html = Html::rawElement('div', ['class' => 'akn-document'], $inner);

		$parserOutput->setContentHolderText($html);
		$parserOutput->addModuleStyles(['ext.aknRenderer.styles']);
		if ($this->tocEntries !== []) {
			$parserOutput->setSections($this->tocEntries);
		}
	}

	private function resetState(): void
	{
		$this->pendingNum = null;
		$this->footnotes = [];
		$this->noteCounter = 0;
		$this->tocEntries = [];
		$this->tocLevelStack = [];
		$this->tocCounters = [];
		$this->usedAnchors = [];
	}

	private function findBody(\DOMDocument $dom): ?\DOMElement
	{
		$nodes = $dom->getElementsByTagNameNS(self::AKN_NS, 'body');
		return $nodes->length > 0 ? $nodes->item(0) : $dom->getElementsByTagName('body')->item(0);
	}

	public static function extractFrbrMeta(string $xml): array
	{
		if ($xml === '')
			return [];
		$dom = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $dom->loadXML($xml, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if (!$loaded)
			return [];

		$work = self::firstByTagAnyNs($dom, 'FRBRWork');
		$expr = self::firstByTagAnyNs($dom, 'FRBRExpression');
		$pub = self::firstByTagAnyNs($dom, 'publication');

		$out = [];
		self::putAttrOfChild($out, 'uri', $work, 'FRBRuri', 'value');
		self::putAttrOfChild($out, 'workdate', $work, 'FRBRdate', 'date');
		self::putAttrOfChild($out, 'country', $work, 'FRBRcountry', 'value');
		self::putAttrOfChild($out, 'language', $expr, 'FRBRlanguage', 'language');
		self::putAttrOfChild($out, 'exprdate', $expr, 'FRBRdate', 'date');

		if ($pub !== null) {
			$name = $pub->getAttribute('showAs') ?: $pub->getAttribute('name');
			if ($name !== '')
				$out['pubname'] = $name;
			if ($pub->getAttribute('date') !== '')
				$out['pubdate'] = $pub->getAttribute('date');
			if ($pub->getAttribute('number') !== '')
				$out['pubnumber'] = $pub->getAttribute('number');
		}
		return $out;
	}

	private static function firstByTagAnyNs(\DOMDocument $dom, string $local): ?\DOMElement
	{
		$nodes = $dom->getElementsByTagNameNS(self::AKN_NS, $local);
		return $nodes->length > 0 ? $nodes->item(0) : $dom->getElementsByTagName($local)->item(0);
	}

	private static function putAttrOfChild(array &$out, string $key, ?\DOMElement $scope, string $childLocal, string $attr): void
	{
		if ($scope === null)
			return;
		foreach ($scope->childNodes as $child) {
			if ($child->nodeType === XML_ELEMENT_NODE && $child->localName === $childLocal) {
				$value = $child->getAttribute($attr);
				if ($value !== '')
					$out[$key] = $value;
				return;
			}
		}
	}

	private function headingLevelFor(\DOMElement $el): int
	{
		$type = $el->localName;
		if ($type === 'hcontainer') {
			$name = strtolower($el->getAttribute('name'));
			return isset(self::HCONTAINER_DIVISION[$name]) ? self::HEADING_LEVELS[self::HCONTAINER_DIVISION[$name]] : 3;
		}
		return self::HEADING_LEVELS[$type] ?? 4;
	}

	private function isTitledOrNumbered(\DOMElement $el): bool
	{
		return ($this->firstChild($el, 'num') !== null || $this->firstChild($el, 'heading') !== null || ($el->localName === 'hcontainer' && ($el->getAttribute('showAs') !== '' || $el->getAttribute('name') !== '')));
	}

	private function isSoleParagraph(\DOMElement $el): bool
	{
		$parent = $el->parentNode;
		if (!$parent instanceof \DOMElement && !$parent instanceof \DOMDocument)
			return false;
		$count = 0;
		foreach ($parent->childNodes as $sib) {
			if ($sib->nodeType === XML_ELEMENT_NODE && $sib->localName === 'paragraph') {
				if (++$count > 1)
					return false;
			}
		}
		return $count === 1;
	}

	private function renderChildren(\DOMNode $node): string
	{
		$html = '';
		foreach ($node->childNodes as $child)
			$html .= $this->renderNode($child);
		return $html;
	}

	private function renderChildrenExcept(\DOMElement $el, array $skip): string
	{
		$html = '';
		foreach ($el->childNodes as $child) {
			if ($child->nodeType === XML_ELEMENT_NODE && in_array($child->localName, $skip, true))
				continue;
			$html .= $this->renderNode($child);
		}
		return $html;
	}

	private function renderNode(\DOMNode $node): string
	{
		if ($node->nodeType === XML_TEXT_NODE) {
			$raw = $node->nodeValue ?? '';
			return (trim($raw) === '') ? '' : Html::rawElement('p', ['class' => 'akn-p'], $this->takePendingNum() . htmlspecialchars($raw, ENT_QUOTES));
		}
		if ($node->nodeType !== XML_ELEMENT_NODE)
			return '';
		$local = $node->localName;
		if (in_array($local, self::CONTAINERS, true) || $local === 'hcontainer') {
			if ($this->isTitledOrNumbered($node)) {
				$saved = $this->pendingNum;
				$this->pendingNum = null;
				$out = $this->renderContainer($node);
				$this->pendingNum = $saved;
				return $out;
			}
			return $this->renderContainer($node);
		}
		switch ($local) {
			case 'table':
				return $this->isolatingNum(fn() => $this->renderTable($node));
			case 'blockList':
				return $this->isolatingNum(fn() => $this->renderBlockList($node));
			case 'foreign':
				return $this->renderForeign($node);
			case 'quotedStructure':
				return Html::rawElement('div', ['class' => 'akn-quotedStructure'], $this->renderChildren($node));
			case 'authorialNote':
				return $this->renderNoteRef($node);
			case 'content':
				return $this->renderChildren($node);
			case 'p':
				return Html::rawElement('p', ['class' => 'akn-p'], $this->takePendingNum() . $this->renderInline($node));
			case 'num':
			case 'heading':
			case 'eol':
			case 'eop':
				return '';
			default:
				return $this->renderChildren($node);
		}
	}

	private function renderContainer(\DOMElement $el): string
	{
		$local = $el->localName;
		$attrs = ['class' => 'akn-' . $local];
		$eId = $el->getAttribute('eId');
		$num = $this->firstChild($el, 'num');
		$heading = $this->firstChild($el, 'heading');
		$showAs = ($local === 'hcontainer') ? ($el->getAttribute('showAs') ?: $el->getAttribute('name')) : '';

		if ($heading !== null || $showAs !== '') {
			$designation = ($num !== null) ? $this->renderInline($num) : ($showAs !== '' ? htmlspecialchars($showAs, ENT_QUOTES) : '');
			$rubric = $heading !== null ? $this->renderInline($heading) : '';
			$out = '';
			if ($designation !== '') {
				$level = $this->headingLevelFor($el);
				$tag = 'h' . min($level, 6);
				$line = ($rubric !== '') ? $designation . ' — ' . $rubric : $designation;
				$anchor = $this->makeAnchor($eId, $line);
				$this->addTocEntry($level, $anchor, $line);
				$out .= Html::rawElement($tag, ['class' => 'akn-designation', 'id' => $anchor], $designation);
			}
			if ($rubric !== '')
				$out .= Html::rawElement('div', ['class' => 'akn-rubric'], $rubric);
			$out .= $this->renderChildrenExcept($el, ['num', 'heading']);
			$attrs['class'] .= ' akn-block';
			return Html::rawElement('section', $attrs, $out);
		}
		if ($num !== null) {
			if ($eId !== '')
				$attrs['id'] = $eId;
			$attrs['class'] .= ' akn-prov';
			if ($local === 'paragraph' && $this->isSoleParagraph($el))
				return Html::rawElement('section', $attrs, $this->renderChildrenExcept($el, ['num']));
			$this->pendingNum = Html::rawElement('span', ['class' => 'akn-num'], $this->renderInline($num));
			$body = $this->renderChildrenExcept($el, ['num']);
			if ($this->pendingNum !== null)
				$body = Html::rawElement('p', ['class' => 'akn-p'], $this->pendingNum) . $body;
			$this->pendingNum = null;
			return Html::rawElement('section', $attrs, $body);
		}
		if ($eId !== '')
			$attrs['id'] = $eId;
		return Html::rawElement('section', $attrs, $this->renderChildren($el));
	}

	private function renderTable(\DOMElement $el): string
	{
		$caption = '';
		$rows = '';
		foreach ($el->childNodes as $child) {
			if ($child->nodeType !== XML_ELEMENT_NODE)
				continue;
			if ($child->localName === 'caption')
				$caption = Html::rawElement('caption', [], $this->renderInline($child));
			elseif ($child->localName === 'tr')
				$rows .= $this->renderRow($child);
		}
		$attrs = ['class' => 'akn-table wikitable'];
		if ($el->getAttribute('eId') !== '')
			$attrs['id'] = $el->getAttribute('eId');
		return Html::rawElement('table', $attrs, $caption . $rows);
	}

	private function renderRow(\DOMElement $tr): string
	{
		$cells = '';
		foreach ($tr->childNodes as $cell) {
			if ($cell->nodeType !== XML_ELEMENT_NODE || ($cell->localName !== 'th' && $cell->localName !== 'td'))
				continue;
			$cellAttrs = [];
			foreach (['colspan', 'rowspan'] as $span)
				if ($cell->getAttribute($span) !== '')
					$cellAttrs[$span] = $cell->getAttribute($span);
			$cells .= Html::rawElement($cell->localName, $cellAttrs, $this->renderChildren($cell));
		}
		return Html::rawElement('tr', [], $cells);
	}

	private function renderBlockList(\DOMElement $el): string
	{
		$out = '';
		foreach ($el->childNodes as $child) {
			if ($child->nodeType !== XML_ELEMENT_NODE)
				continue;
			switch ($child->localName) {
				case 'listIntroduction':
					$out .= Html::rawElement('p', ['class' => 'akn-p akn-listintro'], $this->renderInline($child));
					break;
				case 'listWrapUp':
					$out .= Html::rawElement('p', ['class' => 'akn-p akn-listwrap'], $this->renderInline($child));
					break;
				case 'item':
					$out .= $this->renderItem($child);
					break;
			}
		}
		$attrs = ['class' => 'akn-blockList'];
		if ($el->getAttribute('eId') !== '')
			$attrs['id'] = $el->getAttribute('eId');
		return Html::rawElement('div', $attrs, $out);
	}

	private function renderItem(\DOMElement $item): string
	{
		$attrs = ['class' => 'akn-item akn-prov'];
		if ($item->getAttribute('eId') !== '')
			$attrs['id'] = $item->getAttribute('eId');
		$num = $this->firstChild($item, 'num');
		if ($num !== null) {
			$this->pendingNum = Html::rawElement('span', ['class' => 'akn-num'], $this->renderInline($num));
			$body = $this->renderChildrenExcept($item, ['num']);
			if ($this->pendingNum !== null)
				$body = Html::rawElement('p', ['class' => 'akn-p'], $this->pendingNum) . $body;
			$this->pendingNum = null;
			return Html::rawElement('section', $attrs, $body);
		}
		return Html::rawElement('section', $attrs, $this->renderChildren($item));
	}

	private function renderForeign(\DOMElement $el): string
	{
		$inner = '';
		foreach ($el->childNodes as $child)
			$inner .= $el->ownerDocument->saveXML($child);
		return Html::rawElement('div', ['class' => 'akn-foreign'], $inner);
	}

	private function renderInline(\DOMNode $node): string
	{
		$html = '';
		foreach ($node->childNodes as $child)
			$html .= $this->renderInlineNode($child);
		return $html;
	}

	private function renderInlineNode(\DOMNode $node): string
	{
		if ($node->nodeType === XML_TEXT_NODE)
			return htmlspecialchars($node->nodeValue ?? '', ENT_QUOTES);
		if ($node->nodeType !== XML_ELEMENT_NODE)
			return '';
		$local = $node->localName;
		switch ($local) {
			case 'b':
			case 'i':
			case 'u':
			case 'sup':
			case 'sub':
				return Html::rawElement($local, [], $this->renderInline($node));
			case 'br':
				return Html::element('br');
			case 'ins':
				return Html::rawElement('ins', ['class' => 'akn-ins'], $this->renderInline($node));
			case 'del':
				return Html::rawElement('del', ['class' => 'akn-del'], $this->renderInline($node));
			case 'ref':
			case 'rref':
				$attrs = ['class' => 'akn-ref'];
				$href = $node->getAttribute('href');
				if ($href !== '')
					$attrs['title'] = $href;
				return Html::rawElement('span', $attrs, $this->renderInline($node));
			case 'mref':
			case 'mod':
				return Html::rawElement('span', ['class' => 'akn-' . $local], $this->renderInline($node));
			case 'a':
				$href = $node->getAttribute('href');
				return ($href !== '') ? Html::rawElement('a', ['href' => $href, 'class' => 'external', 'rel' => 'nofollow'], $this->renderInline($node)) : $this->renderInline($node);
			case 'date':
				$attrs = ['class' => 'akn-date'];
				$iso = $node->getAttribute('date');
				if ($iso !== '')
					$attrs['datetime'] = $iso;
				return Html::rawElement('time', $attrs, $this->renderInline($node));
			case 'authorialNote':
				return $this->renderNoteRef($node);
			case 'eol':
			case 'eop':
				return '';
			default:
				return in_array($local, self::INLINE_SPANS, true) ? Html::rawElement('span', ['class' => 'akn-' . $local], $this->renderInline($node)) : $this->renderInline($node);
		}
	}

	private function renderNoteRef(\DOMElement $note): string
	{
		$this->noteCounter++;
		$n = $this->noteCounter;
		$marker = $note->getAttribute('marker') ?: (string) $n;
		$id = 'akn-note-' . $n;
		$refId = 'akn-noteref-' . $n;
		$this->footnotes[] = ['id' => $id, 'refId' => $refId, 'marker' => $marker, 'html' => $this->renderChildren($note)];
		return Html::rawElement('sup', ['class' => 'akn-noteref', 'id' => $refId], Html::rawElement('a', ['href' => '#' . $id], htmlspecialchars($marker, ENT_QUOTES)));
	}

	private function renderFootnotes(): string
	{
		if ($this->footnotes === [])
			return '';
		$items = '';
		foreach ($this->footnotes as $fn) {
			$back = Html::rawElement('a', ['href' => '#' . $fn['refId'], 'class' => 'akn-note-backref'], htmlspecialchars($fn['marker'], ENT_QUOTES) . ' ');
			$items .= Html::rawElement('li', ['id' => $fn['id'], 'class' => 'akn-note'], $back . $fn['html']);
		}
		return Html::rawElement('div', ['class' => 'akn-notes'], Html::element('hr') . Html::rawElement('ol', ['class' => 'akn-notes-list'], $items));
	}

	private function makeAnchor(string $eId, string $fallbackText): string
	{
		$base = $eId !== '' ? $eId : trim(preg_replace('/\s+/', ' ', strip_tags($fallbackText)));
		$base = Sanitizer::escapeIdForAttribute($base ?: 'section');
		$anchor = $base;
		$n = 2;
		while (isset($this->usedAnchors[$anchor])) {
			$anchor = $base . '_' . $n;
			$n++;
		}
		$this->usedAnchors[$anchor] = true;
		return $anchor;
	}

	private function addTocEntry(int $level, string $anchor, string $line): void
	{
		while ($this->tocLevelStack !== [] && end($this->tocLevelStack) > $level) {
			array_pop($this->tocLevelStack);
			array_pop($this->tocCounters);
		}
		if ($this->tocLevelStack !== [] && end($this->tocLevelStack) === $level)
			$this->tocCounters[count($this->tocCounters) - 1]++;
		else {
			$this->tocLevelStack[] = $level;
			$this->tocCounters[] = 1;
		}
		$this->tocEntries[] = ['toclevel' => count($this->tocLevelStack), 'level' => (string) $level, 'line' => $line, 'number' => implode('.', $this->tocCounters), 'index' => '', 'fromtitle' => false, 'byteoffset' => null, 'anchor' => $anchor, 'linkAnchor' => $anchor];
	}

	private function takePendingNum(): string
	{
		if ($this->pendingNum === null)
			return '';
		$lead = $this->pendingNum . ' ';
		$this->pendingNum = null;
		return $lead;
	}

	private function isolatingNum(callable $fn): string
	{
		$saved = $this->pendingNum;
		$this->pendingNum = null;
		$out = $fn();
		$this->pendingNum = $saved;
		return $out;
	}

	private function firstChild(\DOMElement $el, string $local): ?\DOMElement
	{
		foreach ($el->childNodes as $child)
			if ($child->nodeType === XML_ELEMENT_NODE && $child->localName === $local)
				return $child;
		return null;
	}
}
