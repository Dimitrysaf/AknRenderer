<?php

namespace MediaWiki\Extension\AknRenderer\Tests\Unit;

use MediaWiki\Extension\AknRenderer\ConsolidationException;
use MediaWiki\Extension\AknRenderer\Consolidator;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AknRenderer\Consolidator
 */
class ConsolidatorTest extends MediaWikiUnitTestCase
{
	private const XML =
		'<akomaNtoso xmlns="http://docs.oasis-open.org/legaldocml/ns/akn/3.0/WD17">'
		. '<act><body>'
		. '<article eId="art_1"><num>1</num></article>'
		. '<article eId="art_2"><num>2</num></article>'
		. '</body></act></akomaNtoso>';

	/** An amending document carrying a new provision at eId new_1. */
	private const SRC =
		'<akomaNtoso xmlns="http://docs.oasis-open.org/legaldocml/ns/akn/3.0/WD17">'
		. '<act><body>'
		. '<article eId="new_1"><num>ΝΕΟ</num><content><p>REPLACEMENT</p></content></article>'
		. '</body></act></akomaNtoso>';

	/** Out-of-canon eIds/nums + an internal href, to exercise renumber. */
	private const RENUM =
		'<akomaNtoso xmlns="http://docs.oasis-open.org/legaldocml/ns/akn/3.0/WD17">'
		. '<act><body>'
		. '<article eId="old_a"><num>X</num>'
		. '<paragraph eId="old_a__p"><num>9</num><content>'
		. '<p>see <ref href="#old_b">that</ref></p></content></paragraph></article>'
		. '<article eId="old_b"><num>Y</num></article>'
		. '</body></act></akomaNtoso>';

	public function testRepealRemovesOnlyTheTarget(): void
	{
		$out = Consolidator::apply(self::XML, 'repeal', 'art_2');
		$this->assertStringNotContainsString('eId="art_2"', $out);
		$this->assertStringContainsString('eId="art_1"', $out );
	}

	public function testReplaceSwapsTargetKeepingItsEid(): void
	{
		$out = Consolidator::apply(self::XML, 'replace', 'art_2', self::SRC, 'new_1');
		// The source provision's content is now in art_2's place…
		$this->assertStringContainsString('REPLACEMENT', $out);
		$this->assertStringContainsString('ΝΕΟ', $out);
		// …under the TARGET's eId (citation stability), not the source's.
		$this->assertStringContainsString('eId="art_2"', $out);
		$this->assertStringNotContainsString('eId="new_1"', $out);
		// The untouched sibling remains.
		$this->assertStringContainsString('eId="art_1"', $out);
	}

	public function testInsertAddsSourceAfterTarget(): void
	{
		$out = Consolidator::apply(self::XML, 'insert', 'art_1', self::SRC, 'new_1');
		$this->assertStringContainsString('eId="new_1"', $out);
		// Order: art_1, then the inserted new_1, then art_2.
		$posArt1 = strpos($out, 'eId="art_1"');
		$posNew = strpos($out, 'eId="new_1"');
		$posArt2 = strpos($out, 'eId="art_2"');
		$this->assertTrue($posArt1 < $posNew && $posNew < $posArt2, 'inserted after target');
	}

	public function testReplaceWithoutSourceThrows(): void
	{
		try {
			Consolidator::apply(self::XML, 'replace', 'art_2', null, null);
			$this->fail('expected ConsolidationException');
		} catch (ConsolidationException $e) {
			$this->assertSame('pendingamendments-error-no-source', $e->getMessageKey());
		}
	}

	public function testRenumberRegeneratesNumsEidsAndHrefs(): void
	{
		$out = Consolidator::apply(self::RENUM, 'renumber', null);
		// Canonical Greek nums.
		$this->assertStringContainsString('Άρθρο 1', $out);
		$this->assertStringContainsString('Άρθρο 2', $out);
		// Canonical eIds (old ones gone).
		$this->assertStringContainsString('eId="art_1"', $out);
		$this->assertStringContainsString('eId="art_2"', $out);
		$this->assertStringContainsString('eId="art_1__para_1"', $out);
		$this->assertStringNotContainsString('eId="old_a"', $out);
		$this->assertStringNotContainsString('eId="old_b"', $out);
		// The internal href to the renamed article is remapped.
		$this->assertStringContainsString('href="#art_2"', $out);
		$this->assertStringNotContainsString('href="#old_b"', $out);
	}

	public function testUnknownActionThrows(): void
	{
		try {
			Consolidator::apply(self::XML, 'bogus', 'art_1');
			$this->fail('expected ConsolidationException');
		} catch (ConsolidationException $e) {
			$this->assertSame('pendingamendments-error-unsupported', $e->getMessageKey());
		}
	}

	public function testMissingTargetEidThrows(): void
	{
		try {
			Consolidator::apply(self::XML, 'repeal', null);
			$this->fail('expected ConsolidationException');
		} catch (ConsolidationException $e) {
			$this->assertSame('pendingamendments-error-no-target', $e->getMessageKey());
		}
	}

	public function testUnknownEidThrowsNotFound(): void
	{
		try {
			Consolidator::apply(self::XML, 'repeal', 'art_99');
			$this->fail('expected ConsolidationException');
		} catch (ConsolidationException $e) {
			$this->assertSame('pendingamendments-error-not-found', $e->getMessageKey());
		}
	}
}
