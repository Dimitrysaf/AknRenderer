<?php

namespace MediaWiki\Extension\AknRenderer\Tests\Unit;

/**
 * Loads one of the sample AKN documents under tests/fixtures/ by name (no
 * .xml extension) — the same documents used to manually verify every
 * extractor while building them, now pinned down as regression tests.
 */
trait FixtureTrait
{

	private function fixture(string $name): string
	{
		return file_get_contents(__DIR__ . '/../../fixtures/' . $name . '.xml');
	}
}
