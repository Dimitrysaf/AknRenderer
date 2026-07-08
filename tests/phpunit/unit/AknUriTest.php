<?php

namespace MediaWiki\Extension\AknRenderer\Tests\Unit;

use MediaWiki\Extension\AknRenderer\AknUri;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AknRenderer\AknUri
 */
class AknUriTest extends MediaWikiUnitTestCase
{

	/**
	 * @dataProvider provideWorkUris
	 */
	public function testWork(string $input, string $expected): void
	{
		$this->assertSame($expected, AknUri::work($input));
	}

	public static function provideWorkUris(): array
	{
		return [
			'plain work uri' => [
				'/akn/gr/act/nomos/2026-01-15/5300',
				'/gr/act/nomos/2026-01-15/5300',
			],
			'expression + manifestation + fragment' => [
				'/akn/gr/act/nomos/2026-01-15/5300/ell@2026-01-15/!main.xml#art_1',
				'/gr/act/nomos/2026-01-15/5300',
			],
			'short 4-segment scheme' => [
				'/akn/gr/act/2026/5300',
				'/gr/act/2026/5300',
			],
			'already without /akn/ prefix' => [
				'/gr/act/nomos/2026-01-15/5300',
				'/gr/act/nomos/2026-01-15/5300',
			],
			'expression with empty date' => [
				'/akn/gr/act/nomos/2026-01-15/5300/ell@',
				'/gr/act/nomos/2026-01-15/5300',
			],
			'bare fragment only' => [
				'#art_1',
				'/',
			],
		];
	}

	/**
	 * The whole point: a page's stored Work URI and an href pointing at it
	 * canonicalise to the SAME string, whatever expression/fragment the href
	 * carries — that equality is what makes the cross-reference resolve.
	 */
	public function testStoredUriAndReferenceHrefCanonicaliseEqual(): void
	{
		$storedFrbrWorkUri = '/akn/gr/act/nomos/2026-01-15/5300';
		$referenceHref = '/akn/gr/act/nomos/2026-01-15/5300/ell@2026-01-15/!main#art_1';
		$this->assertSame(AknUri::work($storedFrbrWorkUri), AknUri::work($referenceHref));
	}
}
