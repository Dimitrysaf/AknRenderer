<?php
/**
 * ContentHandler for the akn-xml content model.
 *
 * Renders the AKN <body> into structured HTML: division hierarchy, numbered
 * provisions, lists, tables, inline markup, amendments (ins/del), footnotes,
 * and a verbatim passthrough for <foreign>.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

use MediaWiki\Content\Content;
use MediaWiki\Content\Renderer\ContentParseParams;
use MediaWiki\Content\TextContentHandler;
use MediaWiki\Html\Html;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Parser\ParserOutputFlags;
use Wikimedia\Parsoid\Core\SectionMetadata;
use Wikimedia\Parsoid\Core\TOCData;

class AknContentHandler extends TextContentHandler
{

	/** AKN 3.0 namespace URI. */
	private const AKN_NS = 'http://docs.oasis-open.org/legaldocml/ns/akn/3.0';

	/** Hierarchical containers rendered as <section> wrappers. */
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
		'level',
	];

	/** Heading level per division type. */
	private const HEADING_LEVELS = [
		'book' => 2,
		'part' => 2,
		'title' => 3,
		'chapter' => 3,
		'section' => 4,
		'subsection' => 4,
		'article' => 4,
	];

	/** Greek hcontainer @name → division type, for heading levelling. */
	private const HCONTAINER_DIVISION = [
		'vivlio' => 'book',
		'meros' => 'part',
		'kefalaio' => 'chapter',
		'tmima' => 'section',
		'enotita' => 'section',
	];

	/** Inline elements rendered as a semantic span with class akn-{name}. */
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
		'object',
	];

	/** @var string|null One-shot provision number awaiting the next <p>. */
	private ?string $pendingNum = null;

	/** @var list<array{id:string,refId:string,marker:string,html:string}> */
	private array $footnotes = [];

	/** @var int */
	private int $noteCounter = 0;

	/** @var TOCData|null */
	private ?TOCData $tocData = null;

	/** @var int[] Stack of heading levels, for computing TOC nesting depth. */
	private array $tocStack = [];

	/** @var int */
	private int $tocIndex = 0;

	public function __construct(string $modelId = CONTENT_MODEL_AKN)
	{
		parent::__construct($modelId, [CONTENT_FORMAT_XML, CONTENT_FORMAT_TEXT]);
	}

	protected function getContentClass(): string
	{
		return AknContent::class;
	}

	protected function fillParserOutput(
		Content $content,
		ContentParseParams $cpoParams,
		ParserOutput &$parserOutput
	) {
		if (!$cpoParams->getGenerateHtml()) {
			$parserOutput->setContentHolderText(null);
			return;
		}

		'@phan-var AknContent $content';
		$xml = $content->getText();
		$this->pendingNum = null;
		$this->footnotes = [];
		$this->noteCounter = 0;
		$this->tocData = new TOCData();
		$this->tocStack = [];
		$this->tocIndex = 0;

		$dom = new \DOMDocument();
		$dom->preserveWhiteSpace = false;
		$previous = libxml_use_internal_errors(true);
		$loaded = $xml !== '' && $dom->loadXML($xml, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if (!$loaded) {
			$parserOutput->setContentHolderText(Html::errorBox(
				wfMessage('aknrenderer-render-invalidxml')->inContentLanguage()->escaped()
			));
			return;
		}

		$body = $this->findBody($dom);
		if ($body === null) {
			$parserOutput->setContentHolderText(Html::warningBox(
				wfMessage('aknrenderer-render-nobody')->inContentLanguage()->escaped()
			));
			return;
		}

		$rendered = $this->renderChildren($body);
		$notes = $this->renderFootnotes();
		$toc = $this->tocIndex > 0 ? Parser::TOC_PLACEHOLDER : '';
		$html = Html::rawElement('div', ['class' => 'akn-document'], $toc . $rendered . $notes);

		$parserOutput->setContentHolderText($html);
		$parserOutput->addModuleStyles(['ext.aknRenderer.styles']);
		if ($this->tocIndex > 0) {
			$parserOutput->setTOCData($this->tocData);
			$parserOutput->setOutputFlag(ParserOutputFlags::SHOW_TOC);
		}
	}

	private function findBody(\DOMDocument $dom): ?\DOMElement
	{
		$nodes = $dom->getElementsByTagNameNS(self::AKN_NS, 'body');
		if ($nodes->length > 0) {
			return $nodes->item(0);
		}
		$nodes = $dom->getElementsByTagName('body');
		return $nodes->length > 0 ? $nodes->item(0) : null;
	}

	private function headingLevelFor(\DOMElement $el): int
	{
		$type = $el->localName;
		if ($type === 'hcontainer') {
			$name = strtolower($el->getAttribute('name'));
			if (!isset(self::HCONTAINER_DIVISION[$name])) {
				return 3;
			}
			$type = self::HCONTAINER_DIVISION[$name];
		}
		return self::HEADING_LEVELS[$type] ?? 4;
	}

	private function isTitledOrNumbered(\DOMElement $el): bool
	{
		if ($this->firstChild($el, 'num') !== null) {
			return true;
		}
		if ($this->firstChild($el, 'heading') !== null) {
			return true;
		}
		return $el->localName === 'hcontainer'
			&& ($el->getAttribute('showAs') !== '' || $el->getAttribute('name') !== '');
	}

	private function isSoleParagraph(\DOMElement $el): bool
	{
		$parent = $el->parentNode;
		if (!$parent instanceof \DOMElement && !$parent instanceof \DOMDocument) {
			return false;
		}
		$count = 0;
		foreach ($parent->childNodes as $sib) {
			if ($sib->nodeType === XML_ELEMENT_NODE && $sib->localName === 'paragraph') {
				if (++$count > 1) {
					return false;
				}
			}
		}
		return $count === 1;
	}

	// ---------------------------------------------------------------- block

	private function renderChildren(\DOMNode $node): string
	{
		$html = '';
		foreach ($node->childNodes as $child) {
			$html .= $this->renderNode($child);
		}
		return $html;
	}

	private function renderChildrenExcept(\DOMElement $el, array $skip): string
	{
		$html = '';
		foreach ($el->childNodes as $child) {
			if (
				$child->nodeType === XML_ELEMENT_NODE
				&& in_array($child->localName, $skip, true)
			) {
				continue;
			}
			$html .= $this->renderNode($child);
		}
		return $html;
	}

	/**
	 * Block-context dispatch.
	 *
	 * @param \DOMNode $node
	 * @return string HTML
	 */
	private function renderNode(\DOMNode $node): string
	{
		// Bare text inside a structural element is real content — keep it.
		if ($node->nodeType === XML_TEXT_NODE) {
			$raw = $node->nodeValue ?? '';
			if (trim($raw) === '') {
				return '';
			}
			$lead = $this->takePendingNum();
			return Html::rawElement('p', ['class' => 'akn-p'], $lead . htmlspecialchars(trim($raw), ENT_QUOTES));
		}
		if ($node->nodeType !== XML_ELEMENT_NODE) {
			return '';
		}
		/** @var \DOMElement $node */
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
				return Html::rawElement(
					'p',
					['class' => 'akn-p'],
					$this->takePendingNum() . trim($this->renderInline($node))
				);
			case 'num':
			case 'heading':
				return '';
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
		if ($eId !== '') {
			$attrs['id'] = $eId;
		}

		$num = $this->firstChild($el, 'num');
		$heading = $this->firstChild($el, 'heading');

		$showAs = '';
		if ($local === 'hcontainer') {
			$showAs = $el->getAttribute('showAs');
			if ($showAs === '') {
				$showAs = $el->getAttribute('name');
			}
		}

		// Shape A — titled container.
		if ($heading !== null || $showAs !== '') {
			$designation = '';
			if ($num !== null) {
				$designation = trim($this->renderInline($num));
			} elseif ($showAs !== '') {
				$designation = htmlspecialchars(trim($showAs), ENT_QUOTES);
			}
			$rubric = $heading !== null ? trim($this->renderInline($heading)) : '';

			$out = '';
			if ($designation !== '') {
				$hLevel = min($this->headingLevelFor($el), 6);
				$anchor = $eId !== '' ? $eId : 'akn-sec-' . ($this->tocIndex + 1);
				$attrs['id'] = $anchor;
				$out .= Html::rawElement('h' . $hLevel, ['class' => 'akn-designation'], $designation);
				$this->addTocEntry($designation, $anchor, $hLevel);
			}
			if ($rubric !== '') {
				$out .= Html::rawElement('div', ['class' => 'akn-rubric'], $rubric);
			}
			$out .= $this->renderChildrenExcept($el, ['num', 'heading']);

			$attrs['class'] .= ' akn-block';
			return Html::rawElement('section', $attrs, $out);
		}

		// Shape B — numbered provision.
		if ($num !== null) {
			$attrs['class'] .= ' akn-prov';

			if ($local === 'paragraph' && $this->isSoleParagraph($el)) {
				return Html::rawElement('section', $attrs, $this->renderChildrenExcept($el, ['num']));
			}

			$this->pendingNum = Html::rawElement('span', ['class' => 'akn-num'], trim($this->renderInline($num)));
			$body = $this->renderChildrenExcept($el, ['num']);
			if ($this->pendingNum !== null) {
				$body = Html::rawElement('p', ['class' => 'akn-p'], $this->pendingNum) . $body;
				$this->pendingNum = null;
			}
			return Html::rawElement('section', $attrs, $body);
		}

		// Shape C — unlabelled container.
		return Html::rawElement('section', $attrs, $this->renderChildren($el));
	}

	private function renderTable(\DOMElement $el): string
	{
		$caption = '';
		$rows = '';
		foreach ($el->childNodes as $child) {
			if ($child->nodeType !== XML_ELEMENT_NODE) {
				continue;
			}
			if ($child->localName === 'caption') {
				$caption = Html::rawElement('caption', [], trim($this->renderInline($child)));
			} elseif ($child->localName === 'tr') {
				$rows .= $this->renderRow($child);
			}
		}
		$attrs = ['class' => 'akn-table wikitable'];
		if ($el->getAttribute('eId') !== '') {
			$attrs['id'] = $el->getAttribute('eId');
		}
		return Html::rawElement('table', $attrs, $caption . $rows);
	}

	private function renderRow(\DOMElement $tr): string
	{
		$cells = '';
		foreach ($tr->childNodes as $cell) {
			if (
				$cell->nodeType !== XML_ELEMENT_NODE
				|| ($cell->localName !== 'th' && $cell->localName !== 'td')
			) {
				continue;
			}
			$cellAttrs = [];
			foreach (['colspan', 'rowspan'] as $span) {
				if ($cell->getAttribute($span) !== '') {
					$cellAttrs[$span] = $cell->getAttribute($span);
				}
			}
			$cells .= Html::rawElement($cell->localName, $cellAttrs, $this->renderChildren($cell));
		}
		return Html::rawElement('tr', [], $cells);
	}

	private function renderBlockList(\DOMElement $el): string
	{
		$out = '';
		foreach ($el->childNodes as $child) {
			if ($child->nodeType !== XML_ELEMENT_NODE) {
				continue;
			}
			switch ($child->localName) {
				case 'listIntroduction':
					$out .= Html::rawElement('p', ['class' => 'akn-p akn-listintro'], trim($this->renderInline($child)));
					break;
				case 'listWrapUp':
					$out .= Html::rawElement('p', ['class' => 'akn-p akn-listwrap'], trim($this->renderInline($child)));
					break;
				case 'item':
					$out .= $this->renderItem($child);
					break;
			}
		}
		$attrs = ['class' => 'akn-blockList'];
		if ($el->getAttribute('eId') !== '') {
			$attrs['id'] = $el->getAttribute('eId');
		}
		return Html::rawElement('div', $attrs, $out);
	}

	private function renderItem(\DOMElement $item): string
	{
		$attrs = ['class' => 'akn-item akn-prov'];
		if ($item->getAttribute('eId') !== '') {
			$attrs['id'] = $item->getAttribute('eId');
		}
		$num = $this->firstChild($item, 'num');
		if ($num !== null) {
			$this->pendingNum = Html::rawElement('span', ['class' => 'akn-num'], trim($this->renderInline($num)));
			$body = $this->renderChildrenExcept($item, ['num']);
			if ($this->pendingNum !== null) {
				$body = Html::rawElement('p', ['class' => 'akn-p'], $this->pendingNum) . $body;
				$this->pendingNum = null;
			}
			return Html::rawElement('section', $attrs, $body);
		}
		return Html::rawElement('section', $attrs, $this->renderChildren($item));
	}

	/**
	 * Pass <foreign> content through verbatim (MathML, embedded markup) per §7.
	 * Content is assumed trusted (editor-authored, schema-validated on save).
	 *
	 * @param \DOMElement $el
	 * @return string HTML
	 */
	private function renderForeign(\DOMElement $el): string
	{
		$inner = '';
		foreach ($el->childNodes as $child) {
			$inner .= $el->ownerDocument->saveXML($child);
		}
		return Html::rawElement('div', ['class' => 'akn-foreign'], $inner);
	}

	// -------------------------------------------------------------- inline

	/**
	 * Inline-context render: text plus inline element dispatch.
	 *
	 * @param \DOMNode $node
	 * @return string HTML
	 */
	private function renderInline(\DOMNode $node): string
	{
		$html = '';
		foreach ($node->childNodes as $child) {
			$html .= $this->renderInlineNode($child);
		}
		return $html;
	}

	private function renderInlineNode(\DOMNode $node): string
	{
		if ($node->nodeType === XML_TEXT_NODE) {
			// Collapse source-formatting whitespace (indentation, newlines) to
			// single spaces; legal layout is carried by structure, not whitespace.
			$text = preg_replace('/\s+/u', ' ', $node->nodeValue ?? '');
			return htmlspecialchars($text ?? '', ENT_QUOTES);
		}
		if ($node->nodeType !== XML_ELEMENT_NODE) {
			return '';
		}
		/** @var \DOMElement $node */
		$local = $node->localName;

		switch ($local) {
			// formatting
			case 'b':
			case 'i':
			case 'u':
			case 'sup':
			case 'sub':
				return Html::rawElement($local, [], $this->renderInline($node));
			case 'br':
				return Html::element('br');

			// amendment track-changes
			case 'ins':
				return Html::rawElement('ins', ['class' => 'akn-ins'], $this->renderInline($node));
			case 'del':
				return Html::rawElement('del', ['class' => 'akn-del'], $this->renderInline($node));

			// references / mentions (real links arrive with the index step)
			case 'ref':
			case 'rref':
				$attrs = ['class' => 'akn-ref'];
				$href = $node->getAttribute('href');
				if ($href !== '') {
					$attrs['title'] = $href;
				}
				return Html::rawElement('span', $attrs, $this->renderInline($node));
			case 'mref':
			case 'mod':
				return Html::rawElement('span', ['class' => 'akn-' . $local], $this->renderInline($node));
			case 'a':
				$href = $node->getAttribute('href');
				if ($href !== '') {
					return Html::rawElement(
						'a',
						['href' => $href, 'class' => 'external', 'rel' => 'nofollow'],
						$this->renderInline($node)
					);
				}
				return $this->renderInline($node);

			// inline date
			case 'date':
				$attrs = ['class' => 'akn-date'];
				$iso = $node->getAttribute('date');
				if ($iso !== '') {
					$attrs['datetime'] = $iso;
				}
				return Html::rawElement('time', $attrs, $this->renderInline($node));

			// footnote: pull out of the flow
			case 'authorialNote':
				return $this->renderNoteRef($node);

			// editorial markers: drop
			case 'eol':
			case 'eop':
				return '';

			default:
				if (in_array($local, self::INLINE_SPANS, true)) {
					return Html::rawElement('span', ['class' => 'akn-' . $local], $this->renderInline($node));
				}
				// Unknown inline: keep its text.
				return $this->renderInline($node);
		}
	}

	// ------------------------------------------------------------ footnotes

	private function renderNoteRef(\DOMElement $note): string
	{
		$this->noteCounter++;
		$n = $this->noteCounter;
		$marker = $note->getAttribute('marker');
		if ($marker === '') {
			$marker = (string) $n;
		}
		$id = 'akn-note-' . $n;
		$refId = 'akn-noteref-' . $n;

		$this->footnotes[] = [
			'id' => $id,
			'refId' => $refId,
			'marker' => $marker,
			'html' => $this->renderChildren($note),
		];

		return Html::rawElement(
			'sup',
			['class' => 'akn-noteref', 'id' => $refId],
			Html::rawElement('a', ['href' => '#' . $id], htmlspecialchars($marker, ENT_QUOTES))
		);
	}

	private function renderFootnotes(): string
	{
		if ($this->footnotes === []) {
			return '';
		}
		$items = '';
		foreach ($this->footnotes as $fn) {
			$back = Html::rawElement(
				'a',
				['href' => '#' . $fn['refId'], 'class' => 'akn-note-backref'],
				htmlspecialchars($fn['marker'], ENT_QUOTES) . ' '
			);
			$items .= Html::rawElement('li', ['id' => $fn['id'], 'class' => 'akn-note'], $back . $fn['html']);
		}
		return Html::rawElement(
			'div',
			['class' => 'akn-notes'],
			Html::element('hr') . Html::rawElement('ol', ['class' => 'akn-notes-list'], $items)
		);
	}

	// --------------------------------------------------------------- utils

	/** Consume the pending provision number (with trailing space) if set. */
	private function takePendingNum(): string
	{
		if ($this->pendingNum === null) {
			return '';
		}
		$lead = $this->pendingNum . ' ';
		$this->pendingNum = null;
		return $lead;
	}

	/** Run $fn with the pending number isolated (tables, blockLists). */
	private function isolatingNum(callable $fn): string
	{
		$saved = $this->pendingNum;
		$this->pendingNum = null;
		$out = $fn();
		$this->pendingNum = $saved;
		return $out;
	}

	/**
	 * Record a TOC entry for a titled container. The TOC line is the
	 * designation only (e.g. "Άρθρο 1"), never the rubric.
	 *
	 * @param string $line Designation HTML (inline)
	 * @param string $anchor Section id to link to
	 * @param int $hLevel Heading level (2–6)
	 */
	private function addTocEntry(string $line, string $anchor, int $hLevel): void
	{
		if ($this->tocData === null) {
			return;
		}
		// TOC nesting depth: pop shallower-or-equal levels off the stack.
		while ($this->tocStack !== [] && end($this->tocStack) >= $hLevel) {
			array_pop($this->tocStack);
		}
		$this->tocStack[] = $hLevel;
		$tocLevel = count($this->tocStack);
		$this->tocIndex++;

		$this->tocData->addSection(new SectionMetadata(
			$tocLevel,
			$hLevel,
			$line,
			'',
			(string) $this->tocIndex,
			null,
			null,
			$anchor,
			$anchor
		));
	}

	private function firstChild(\DOMElement $el, string $local): ?\DOMElement
	{
		foreach ($el->childNodes as $child) {
			if ($child->nodeType === XML_ELEMENT_NODE && $child->localName === $local) {
				return $child;
			}
		}
		return null;
	}
}
