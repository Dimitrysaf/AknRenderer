<?php

namespace MediaWiki\Extension\AknRenderer\Tests\Unit;

use MediaWiki\Extension\AknRenderer\AmendmentExtractor;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AknRenderer\AmendmentExtractor
 */
class AmendmentExtractorTest extends MediaWikiUnitTestCase
{
	use FixtureTrait;

	public function testOriginalEnactmentHasNoAmendments(): void
	{
		$this->assertSame([], AmendmentExtractor::fromXml($this->fixture('n5300-2026-v1'), 3));
	}

	public function testAccumulatesBothAmendmentsByV4(): void
	{
		$rows = AmendmentExtractor::fromXml($this->fixture('n5300-2026-v4'), 3);
		$this->assertCount(2, $rows, 'v4 accumulates the v2 amendment plus its own');

		$this->assertSame('passive', $rows[0]['ama_direction']);
		$this->assertSame('substitution', $rows[0]['ama_type']);
		$this->assertSame('/gr/act/2026/5301#art_1', $rows[0]['ama_source_href']);
		$this->assertSame('#art_7', $rows[0]['ama_dest_href']);
		$this->assertSame('2026-04-01', $rows[0]['ama_date']);

		$this->assertSame('passive', $rows[1]['ama_direction']);
		$this->assertSame('/gr/ya/2026/3344#art_1', $rows[1]['ama_source_href'], 'sourced from a ministerial decision, not a Law: page');
		$this->assertSame('#art_9', $rows[1]['ama_dest_href']);
		$this->assertSame('2026-09-15', $rows[1]['ama_date']);
	}

	public function testAmendingActRecordsActiveModification(): void
	{
		$rows = AmendmentExtractor::fromXml($this->fixture('n5301-2026-v1'), 5);
		$this->assertCount(1, $rows);
		$this->assertSame('active', $rows[0]['ama_direction'], 'this document is the source of the change, not the target');
		$this->assertSame('#art_1', $rows[0]['ama_source_href'], 'the modifying provision is within this document');
		$this->assertSame('/gr/act/2026/5300#art_7', $rows[0]['ama_dest_href'], 'the modified provision is in the other act');
	}

	/**
	 * A <textualMod> with no <force> at all (as opposed to one whose
	 * <force> fails to resolve) must not fabricate a date — it's simply
	 * unrecorded, not an error.
	 */
	public function testDateIsEmptyStringWhenForceIsAbsent(): void
	{
		$xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<akomaNtoso xmlns="http://docs.oasis-open.org/legaldocml/ns/akn/3.0/WD17">
  <act name="nomos">
    <meta>
      <analysis>
        <passiveModifications>
          <textualMod type="insertion">
            <source href="/gr/act/2026/9999#art_1"/>
            <destination href="#art_5"/>
          </textualMod>
        </passiveModifications>
      </analysis>
    </meta>
  </act>
</akomaNtoso>
XML;
		$rows = AmendmentExtractor::fromXml($xml, 3);
		$this->assertCount(1, $rows);
		$this->assertSame('', $rows[0]['ama_date']);
	}
}
