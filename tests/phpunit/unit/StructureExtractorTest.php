<?php

namespace MediaWiki\Extension\AknRenderer\Tests\Unit;

use MediaWiki\Extension\AknRenderer\StructureExtractor;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AknRenderer\StructureExtractor
 */
class StructureExtractorTest extends MediaWikiUnitTestCase
{
	use FixtureTrait;

	public function testReturnsEmptyForEmptyXml(): void
	{
		$this->assertSame([], StructureExtractor::fromXml('', 1));
	}

	public function testEidTreeOfTheCodifiedLaw(): void
	{
		$rows = StructureExtractor::fromXml($this->fixture('n5300-2026-v1'), 3);
		$this->assertCount(25, $rows, 'one row per eId-bearing structural element in the fixture');

		$this->assertSame([
			'ast_page' => 3,
			'ast_eid' => 'part_a',
			'ast_parent' => null,
			'ast_type' => 'part',
			'ast_num' => 'ΜΕΡΟΣ Α΄',
			'ast_heading' => 'ΓΕΝΙΚΕΣ ΔΙΑΤΑΞΕΙΣ',
			'ast_order' => 0,
		], $rows[0], 'top-level provision has a NULL parent');

		$this->assertSame('chap_a1', $rows[1]['ast_eid']);
		$this->assertSame('part_a', $rows[1]['ast_parent'], 'nested under the part it appears in');
		$this->assertSame('art_1', $rows[2]['ast_eid']);
		$this->assertSame('chap_a1', $rows[2]['ast_parent']);
	}

	public function testOfficialGazetteHasNoStructureTree(): void
	{
		// A gazette issue has a <collectionBody>, not a <body> — nothing to
		// extract at the top level.
		$rows = StructureExtractor::fromXml($this->fixture('fek-a-15-2026'), 6);
		$this->assertSame([], $rows);
	}
}
