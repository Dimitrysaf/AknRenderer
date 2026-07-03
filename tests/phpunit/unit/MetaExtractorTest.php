<?php

namespace MediaWiki\Extension\AknRenderer\Tests\Unit;

use MediaWiki\Extension\AknRenderer\MetaExtractor;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AknRenderer\MetaExtractor
 */
class MetaExtractorTest extends MediaWikiUnitTestCase
{
	use FixtureTrait;

	public function testReturnsNullForEmptyXml(): void
	{
		$this->assertNull(MetaExtractor::fromXml(''));
	}

	public function testReturnsNullForMalformedXml(): void
	{
		$this->assertNull(MetaExtractor::fromXml('<akomaNtoso><act>'));
	}

	public function testOriginalEnactment(): void
	{
		$data = MetaExtractor::fromXml($this->fixture('n5300-2026-v1'));
		$this->assertNotNull($data);
		$this->assertSame('nomos', $data['docType']);
		$this->assertSame('Κώδικας Ψηφιακής Διακυβέρνησης και Τεχνητής Νοημοσύνης', $data['alias']);
		$this->assertSame('/gr/act/2026/5300', $data['workUri']);
		$this->assertSame('5300', $data['number']);
		$this->assertSame('gr', $data['country']);
		$this->assertSame('2026-01-10', $data['enacted']);
		$this->assertSame('2026-01-10', $data['exprDate']);
		$this->assertSame('Βουλή των Ελλήνων', $data['authorLabel'], 'resolved via <references> TLCOrganization');
		$this->assertSame('Α', $data['pubSeries']);
		$this->assertSame('15', $data['pubNumber']);
		$this->assertCount(4, $data['keywords']);
		$this->assertSame(['2026-01-10 generation'], $data['events']);
	}

	/**
	 * v2 declares a new FRBRExpression date and a new <publication> — the
	 * whole reason akn_revision keys a "version" on this, not on the
	 * MediaWiki save.
	 */
	public function testAmendedVersionHasNewEffectiveDateAndGazette(): void
	{
		$data = MetaExtractor::fromXml($this->fixture('n5300-2026-v2'));
		$this->assertSame('2026-04-01', $data['exprDate']);
		$this->assertSame('Α', $data['pubSeries']);
		$this->assertSame('40', $data['pubNumber']);
		$this->assertSame(
			['2026-01-10 generation', '2026-04-01 amendment'],
			$data['events']
		);
	}

	public function testSecondAmendmentChangesGazetteSeries(): void
	{
		$data = MetaExtractor::fromXml($this->fixture('n5300-2026-v4'));
		$this->assertSame('2026-09-15', $data['exprDate']);
		$this->assertSame('Β', $data['pubSeries'], 'the second amendment was a ministerial decision, published in Β not Α');
		$this->assertSame('3344', $data['pubNumber']);
	}

	public function testOfficialGazetteDocument(): void
	{
		$data = MetaExtractor::fromXml($this->fixture('fek-a-15-2026'));
		$this->assertNotNull($data, 'officialGazette must be a recognised root, not just act/bill/doc');
		$this->assertSame('fek', $data['docType']);
		$this->assertSame('/gr/officialGazette/2026/A/15', $data['workUri']);
		$this->assertSame([], $data['keywords'], 'this fixture declares no <classification> keywords');
	}
}
