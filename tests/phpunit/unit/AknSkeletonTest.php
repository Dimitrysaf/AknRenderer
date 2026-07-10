<?php

namespace MediaWiki\Extension\AknRenderer\Tests\Unit;

use MediaWiki\Extension\AknRenderer\AknSchema;
use MediaWiki\Extension\AknRenderer\AknSkeleton;
use MediaWiki\Extension\AknRenderer\AknUri;
use MediaWiki\Extension\AknRenderer\MetaExtractor;
use MediaWikiUnitTestCase;

/**
 * The guarantee behind the creation wizard: every seed AknSkeleton builds
 * satisfies schema/akomantoso30.xsd (so it can actually be saved), carries the
 * schema's namespace, and round-trips through MetaExtractor (so it indexes).
 * If the schema ever changes what a valid document needs, these tests fail
 * here rather than silently at the user's Save.
 *
 * @covers \MediaWiki\Extension\AknRenderer\AknSkeleton
 */
class AknSkeletonTest extends MediaWikiUnitTestCase
{

	private function assertSchemaValid(string $xml): void
	{
		$errors = AknSchema::validate($xml);
		$this->assertSame(
			[],
			$errors,
			"Seed is not schema-valid:\n" . implode("\n", $errors) . "\n\n" . $xml
		);
	}

	public function testSupportedRootsAreRealXsdDocumentTypes(): void
	{
		$roots = AknSkeleton::supportedRoots();
		$this->assertNotSame([], $roots);
		// Every root the builder offers must be a genuine schema documentType.
		$this->assertSame([], array_diff($roots, AknSchema::documentTypes()));
		// The three structure families we cover.
		$this->assertContains('act', $roots);
		$this->assertContains('doc', $roots);
		$this->assertContains('officialGazette', $roots);
	}

	public function testEverySupportedRootSeedIsSchemaValid(): void
	{
		foreach (AknSkeleton::supportedRoots() as $root) {
			$xml = AknSkeleton::build([
				'root' => $root,
				'number' => '5300',
				'enacted' => '2026-01-15',
				'alias' => 'Test ' . $root,
				'fekSeries' => 'Α',
				'fekNumber' => '12',
				'fekDate' => '2026-01-16',
			]);
			$this->assertStringContainsString(AknSchema::NS, $xml, "$root seed must use the schema namespace");
			$this->assertSchemaValid($xml);
		}
	}

	public function testActNomosSeedRoundTripsThroughMetaExtractor(): void
	{
		$xml = AknSkeleton::build([
			'root' => 'act',
			'name' => 'nomos',
			'number' => '5300',
			'enacted' => '2026-01-15',
			'alias' => 'Νόμος 5300/2026',
			'fekSeries' => 'Α',
			'fekNumber' => '12',
			'fekDate' => '2026-01-16',
		]);
		$this->assertSchemaValid($xml);

		$data = MetaExtractor::fromXml($xml);
		$this->assertNotNull($data);
		$this->assertSame('nomos', $data['docType']);
		$this->assertSame('5300', $data['number']);
		$this->assertSame('Νόμος 5300/2026', $data['alias']);
		$this->assertSame('gr', $data['country']);
		$this->assertSame('ell', $data['language']);
		$this->assertSame('2026-01-15', $data['enacted']);
		$this->assertSame('Α', $data['pubSeries']);
		$this->assertSame('12', $data['pubNumber']);
		$this->assertSame('Βουλή των Ελλήνων', $data['authorLabel'], 'resolved via the seeded <references> TLCOrganization');

		// The seed's Work URI must canonicalise the same way a <ref> href to
		// this document would, so cross-references resolve (see AknUri).
		$this->assertSame('/gr/act/nomos/2026-01-15/5300', AknUri::work($data['workUri']));
	}

	public function testGazetteSeedIsValidAndIsAGazette(): void
	{
		$xml = AknSkeleton::build([
			'root' => 'officialGazette',
			'number' => '12',
			'enacted' => '2026-01-16',
			'alias' => 'ΦΕΚ Α΄ 12/2026',
			'fekSeries' => 'Α',
			'fekNumber' => '12',
			'fekDate' => '2026-01-16',
		]);
		$this->assertSchemaValid($xml);
		$data = MetaExtractor::fromXml($xml);
		$this->assertSame('fek', $data['docType'], 'officialGazette must be recognised as a root');
	}

	public function testUnsupportedRootFallsBackToAct(): void
	{
		// judgment has a distinct body model we do not seed; build() must fall
		// back to a valid <act> rather than emit an invalid judgment.
		$xml = AknSkeleton::build(['root' => 'judgment', 'number' => '1', 'enacted' => '2026-01-15']);
		$this->assertSchemaValid($xml);
		$this->assertStringContainsString('<act', $xml);
	}

	public function testSeedWithoutDateStillValidatesUsingToday(): void
	{
		// The schema forbids an empty @date; a seed with no enactment date must
		// still be valid (build() falls back to today's date).
		$xml = AknSkeleton::build(['root' => 'act', 'name' => 'nomos', 'number' => '1']);
		$this->assertSchemaValid($xml);
	}

	public function testNoGazetteMeansNoPublicationElement(): void
	{
		// publication/@name/@date/@showAs are all required; a half-known ΦΕΚ
		// must not emit a (would-be invalid) <publication>.
		$xml = AknSkeleton::build(['root' => 'act', 'name' => 'nomos', 'number' => '1', 'enacted' => '2026-01-15']);
		$this->assertSchemaValid($xml);
		$this->assertStringNotContainsString('<publication', $xml);
	}
}
