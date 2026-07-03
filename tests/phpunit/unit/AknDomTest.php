<?php

namespace MediaWiki\Extension\AknRenderer\Tests\Unit;

use MediaWiki\Extension\AknRenderer\AknDom;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AknRenderer\AknDom
 */
class AknDomTest extends MediaWikiUnitTestCase
{
	use FixtureTrait;

	public function testParseRejectsEmptyString(): void
	{
		$this->assertNull(AknDom::parse(''));
	}

	public function testParseRejectsMalformedXml(): void
	{
		$this->assertNull(AknDom::parse('<akomaNtoso><act>'));
	}

	public function testFindRootOnAct(): void
	{
		$dom = AknDom::parse($this->fixture('n5300-2026-v1'));
		$root = AknDom::findRoot($dom);
		$this->assertNotNull($root);
		$this->assertSame('act', $root->localName);
	}

	public function testFindRootOnOfficialGazette(): void
	{
		$dom = AknDom::parse($this->fixture('fek-a-15-2026'));
		$root = AknDom::findRoot($dom);
		$this->assertNotNull($root);
		$this->assertSame('officialGazette', $root->localName);
	}

	/**
	 * The regression this class exists to prevent: a gazette's
	 * <collectionBody> can <component>-embed a full nested document with
	 * its own <doc>/<meta>. findRoot()/findMeta() must return the OUTER
	 * officialGazette/meta, never the nested one, no matter where in
	 * document order the embedded document falls.
	 */
	public function testFindRootIgnoresComponentEmbeddedDocument(): void
	{
		$dom = AknDom::parse($this->fixture('fek-a-15-2026'));
		$root = AknDom::findRoot($dom);
		$this->assertSame('officialGazette', $root->localName, 'must not match the embedded <doc>');

		$meta = AknDom::findMeta($dom);
		$this->assertNotNull($meta);
		$series = $meta->getElementsByTagName('publication')->item(0)->getAttribute('name');
		$this->assertSame('Α', $series, 'must read the gazette\'s own <publication>, not the embedded decision\'s');
	}

	public function testFindRootReturnsNullWithoutARecognisedRoot(): void
	{
		$dom = AknDom::parse('<akomaNtoso xmlns="http://docs.oasis-open.org/legaldocml/ns/akn/3.0"><nonsense/></akomaNtoso>');
		$this->assertNull(AknDom::findRoot($dom));
	}
}
