<?php

namespace MediaWiki\Extension\AknRenderer\Tests\Unit;

use MediaWiki\Extension\AknRenderer\GazetteExtractor;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AknRenderer\GazetteExtractor
 */
class GazetteExtractorTest extends MediaWikiUnitTestCase
{
	use FixtureTrait;

	public function testOfficialGazetteYieldsARow(): void
	{
		$row = GazetteExtractor::fromXml($this->fixture('fek-a-15-2026'), 6);
		$this->assertSame([
			'agz_page' => 6,
			'agz_series' => 'Α',
			'agz_number' => '15',
			'agz_date' => '2026-01-10',
		], $row);
	}

	/**
	 * The bug this guards against: a Law page (root <act>, not
	 * <officialGazette>) must never produce a gazette row, even though it
	 * has its own <publication> (its citation of the ΦΕΚ that published
	 * it) — that's a different fact, recorded in akn_meta/akn_revision, not
	 * "this page is a gazette issue".
	 */
	public function testLawPageIsNotAGazette(): void
	{
		$this->assertNull(GazetteExtractor::fromXml($this->fixture('n5300-2026-v1'), 3));
		$this->assertNull(GazetteExtractor::fromXml($this->fixture('n5301-2026-v1'), 5));
	}
}
