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
			// This issue publishes only a ministerial decision («απόφαση»),
			// which is neither a law nor a decree → 'other'.
			'agz_doc_type' => 'other',
		], $row);
	}

	/**
	 * agz_doc_type reflects the first published document. This ΦΕΚ leads with
	 * a law (a <documentRef> to /akn/gr/act/nomos/…), so → 'act', even though
	 * it also embeds a presidential decree further down.
	 */
	public function testDocTypeFromLeadingLaw(): void
	{
		$row = GazetteExtractor::fromXml($this->fixture('Gazette:ΦΕΚ Α΄ 12-2026'), 7);
		$this->assertSame('act', $row['agz_doc_type']);
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
