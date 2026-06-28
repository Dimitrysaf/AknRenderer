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
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Parser\Sanitizer;

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

	/**
	 * Legacy TOC section entries, one per rendered heading, consumed by
	 * ParserOutput::setSections() at the end of fillParserOutput().
	 *
	 * @var list<array{toclevel:int,level:string,line:string,number:string,index:string,fromtitle:false,byteoffset:?int,anchor:string,linkAnchor:string}>
	 */
	private array $tocEntries = [];

	/** @var list<int> Stack of currently-open heading levels (for toclevel/number bookkeeping). */
	private array $tocLevelStack = [];

	/** @var list<int> Section-number counters, one per entry in $tocLevelStack. */
	private array $tocCounters = [];

	/** @var array<string,true> HTML ids already handed out on this page, for de-duplication. */
	private array $usedAnchors = [];

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
		$this->tocEntries = [];
		$this->tocLevelStack = [];
		$this->tocCounters = [];
		$this->usedAnchors = [];

		$dom = new \DOMDocument();
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

		$inner = $this->renderChildren($body) . $this->renderFootnotes();
		$html = Html::rawElement('div', ['class' => 'akn-document'], $inner);

		$parserOutput->setContentHolderText($html);
		$parserOutput->addModuleStyles(['ext.aknRenderer.styles']);
		if ($this->tocEntries !== []) {
			$parserOutput->setSections($this->tocEntries);
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

	// ------------------------------------------------------------ metadata

	/**
	 * Pull a handful of FRBR identification fields out of an AKN
	 * document's <meta> block, for display on action=info.
	 *
	 * This deliberately covers a useful subset of AKN's identification
	 * vocabulary (work/expression dates, country, language, publication),
	 * not the full FRBR model — extend the lookups below if you need more.
	 *
	 * @param string $xml Raw AKN XML (the page's stored content).
	 * @return array<string,string> Field key => value, omitting anything
	 *  not present in the document. Keys: uri, workdate, country,
	 *  language, exprdate, pubname, pubdate, pubnumber.
	 */
	public static function extractFrbrMeta(string $xml): array
	{
		if ($xml === '') {
			return [];
		}
		$dom = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $dom->loadXML($xml, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if (!$loaded) {
			return [];
		}

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
			if ($name !== '') {
				$out['pubname'] = $name;
			}
			if ($pub->getAttribute('date') !== '') {
				$out['pubdate'] = $pub->getAttribute('date');
			}
			if ($pub->getAttribute('number') !== '') {
				$out['pubnumber'] = $pub->getAttribute('number');
			}
		}

		return $out;
	}

	/** Find the first element with this local name, AKN-namespaced or not (same tolerance as findBody()). */
	private static function firstByTagAnyNs(\DOMDocument $dom, string $local): ?\DOMElement
	{
		$nodes = $dom->getElementsByTagNameNS(self::AKN_NS, $local);
		if ($nodes->length > 0) {
			return $nodes->item(0);
		}
		$nodes = $dom->getElementsByTagName($local);
		return $nodes->length > 0 ? $nodes->item(0) : null;
	}

	/** If $scope has a direct child named $childLocal, copy its $attr into $out[$key] (skipping empties). */
	private static function putAttrOfChild(
		array &$out,
		string $key,
		?\DOMElement $scope,
		string $childLocal,
		string $attr
	): void {
		if ($scope === null) {
			return;
		}
		foreach ($scope->childNodes as $child) {
			if ($child->nodeType === XML_ELEMENT_NODE && $child->localName === $childLocal) {
				$value = $child->getAttribute($attr);
				if ($value !== '') {
					$out[$key] = $value;
				}
				return;
			}
		}
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
			return Html::rawElement('p', ['class' => 'akn-p'], $lead . htmlspecialchars($raw, ENT_QUOTES));
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
					$this->takePendingNum() . $this->renderInline($node)
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
				$designation = $this->renderInline($num);
			} elseif ($showAs !== '') {
				$designation = htmlspecialchars($showAs, ENT_QUOTES);
			}
			$rubric = $heading !== null ? $this->renderInline($heading) : '';

			$out = '';
			if ($designation !== '') {
				$level = $this->headingLevelFor($el);
				$tag = 'h' . min($level, 6);
				$line = $rubric !== '' ? $designation . ' — ' . $rubric : $designation;
				$anchor = $this->makeAnchor($eId, $line);
				$this->addTocEntry($level, $anchor, $line);
				$out .= Html::rawElement(
					$tag,
					['class' => 'akn-designation', 'id' => $anchor],
					$designation
				);
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
			if ($eId !== '') {
				$attrs['id'] = $eId;
			}
			$attrs['class'] .= ' akn-prov';

			if ($local === 'paragraph' && $this->isSoleParagraph($el)) {
				return Html::rawElement('section', $attrs, $this->renderChildrenExcept($el, ['num']));
			}

			$this->pendingNum = Html::rawElement('span', ['class' => 'akn-num'], $this->renderInline($num));
			$body = $this->renderChildrenExcept($el, ['num']);
			if ($this->pendingNum !== null) {
				$body = Html::rawElement('p', ['class' => 'akn-p'], $this->pendingNum) . $body;
				$this->pendingNum = null;
			}
			return Html::rawElement('section', $attrs, $body);
		}

		// Shape C — unlabelled container.
		if ($eId !== '') {
			$attrs['id'] = $eId;
		}
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
				$caption = Html::rawElement('caption', [], $this->renderInline($child));
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
			$this->pendingNum = Html::rawElement('span', ['class' => 'akn-num'], $this->renderInline($num));
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
			return htmlspecialchars($node->nodeValue ?? '', ENT_QUOTES);
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

	// ----------------------------------------------------------------- toc

	/**
	 * Reserve a unique HTML id to anchor a heading.
	 *
	 * Prefers the AKN @eId (run through the same escaping core uses for
	 * section ids); falls back to a slug built from the heading's own
	 * text when no eId is present. Either way the result is checked
	 * against every anchor already handed out on this page and
	 * disambiguated with a "_2", "_3", ... suffix, the same convention
	 * core uses for duplicate wikitext heading anchors.
	 *
	 * @param string $eId The container's AKN eId, or '' if it has none.
	 * @param string $fallbackText Rendered (HTML) heading text to slugify
	 *  if there's no eId to use.
	 */
	private function makeAnchor(string $eId, string $fallbackText): string
	{
		$base = $eId !== ''
			? $eId
			: trim(preg_replace('/\s+/', ' ', strip_tags($fallbackText)));
		if ($base === '') {
			$base = 'section';
		}
		$base = Sanitizer::escapeIdForAttribute($base);
		if ($base === '') {
			$base = 'section';
		}

		$anchor = $base;
		$n = 2;
		while (isset($this->usedAnchors[$anchor])) {
			$anchor = $base . '_' . $n;
			$n++;
		}
		$this->usedAnchors[$anchor] = true;
		return $anchor;
	}

	/**
	 * Record one rendered heading as a TOC entry, maintaining toclevel and
	 * section-number bookkeeping the same way core does for nested
	 * wikitext sections — even though AKN heading levels (book/part/
	 * chapter/article/hcontainer...) don't arrive in a strictly
	 * increasing sequence the way h2/h3/h4 do.
	 *
	 * @param int $level The heading's HTML level (2-6, pre-clamping).
	 * @param string $anchor The id already assigned to this heading.
	 * @param string $line Rendered (HTML) heading text, for the TOC label.
	 */
	private function addTocEntry(int $level, string $anchor, string $line): void
	{
		// Close any open siblings/descendants at this depth or deeper.
		while ($this->tocLevelStack !== [] && end($this->tocLevelStack) > $level) {
			array_pop($this->tocLevelStack);
			array_pop($this->tocCounters);
		}
		if ($this->tocLevelStack !== [] && end($this->tocLevelStack) === $level) {
			// Sibling at the same depth: bump its counter.
			$this->tocCounters[count($this->tocCounters) - 1]++;
		} else {
			// First heading, or one level deeper than its parent.
			$this->tocLevelStack[] = $level;
			$this->tocCounters[] = 1;
		}

		$this->tocEntries[] = [
			'toclevel' => count($this->tocLevelStack),
			'level' => (string) $level,
			'line' => $line,
			'number' => implode('.', $this->tocCounters),
			// AKN pages aren't split into wikitext-style editable sections.
			'index' => '',
			'fromtitle' => false,
			'byteoffset' => null,
			'anchor' => $anchor,
			'linkAnchor' => $anchor,
		];
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
