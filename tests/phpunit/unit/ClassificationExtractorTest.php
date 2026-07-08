<?php

namespace MediaWiki\Extension\AknRenderer\Tests\Unit;

use MediaWiki\Extension\AknRenderer\ClassificationExtractor;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AknRenderer\ClassificationExtractor
 */
class ClassificationExtractorTest extends MediaWikiUnitTestCase
{
	use FixtureTrait;

	public function testExtractsEveryKeyword(): void
	{
		$rows = ClassificationExtractor::fromXml($this->fixture('n5301-2026-v1'), 5);
		$this->assertSame([
			[
				'acl_page' => 5,
				'acl_order' => 0,
				'acl_dictionary' => '',
				'acl_value' => 'artificial-intelligence',
				'acl_showas' => 'Τεχνητή Νοημοσύνη',
				'acl_href' => '',
			],
			[
				'acl_page' => 5,
				'acl_order' => 1,
				'acl_dictionary' => '',
				'acl_value' => 'legislative-amendment',
				'acl_showas' => 'Τροποποίηση Νόμου',
				'acl_href' => '',
			],
		], $rows);
	}

	public function testDocumentWithNoKeywordsYieldsNoRows(): void
	{
		$this->assertSame([], ClassificationExtractor::fromXml($this->fixture('fek-a-15-2026'), 6));
	}

	/** A <keyword> needs at least @showAs or @value to mean anything. */
	public function testKeywordWithNeitherShowAsNorValueIsSkipped(): void
	{
		$xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<akomaNtoso xmlns="http://docs.oasis-open.org/legaldocml/ns/akn/3.0/WD17">
  <act name="nomos">
    <meta>
      <classification>
        <keyword dictionary="http://example.org/thesaurus"/>
      </classification>
    </meta>
  </act>
</akomaNtoso>
XML;
		$this->assertSame([], ClassificationExtractor::fromXml($xml, 1));
	}

	public function testDictionaryAndHrefAreCaptured(): void
	{
		$xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<akomaNtoso xmlns="http://docs.oasis-open.org/legaldocml/ns/akn/3.0/WD17">
  <act name="nomos">
    <meta>
      <classification>
        <keyword dictionary="http://eurovoc.europa.eu/" value="100270" showAs="Τεχνητή νοημοσύνη" href="#art_1"/>
      </classification>
    </meta>
  </act>
</akomaNtoso>
XML;
		$rows = ClassificationExtractor::fromXml($xml, 1);
		$this->assertCount(1, $rows);
		$this->assertSame('http://eurovoc.europa.eu/', $rows[0]['acl_dictionary']);
		$this->assertSame('100270', $rows[0]['acl_value']);
		$this->assertSame('#art_1', $rows[0]['acl_href']);
	}
}
