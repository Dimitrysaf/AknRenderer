<?php

namespace MediaWiki\Extension\AknRenderer\Tests\Unit;

use MediaWiki\Extension\AknRenderer\AknSchema;
use MediaWikiUnitTestCase;

/**
 * The schema (schema/akomantoso30.xsd) is the single source of truth: these
 * tests pin down both that the vocabulary is read from it and that it is used
 * as the validator.
 *
 * @covers \MediaWiki\Extension\AknRenderer\AknSchema
 */
class AknSchemaTest extends MediaWikiUnitTestCase
{

	/** A minimal document that satisfies the schema, for validation tests. */
	private function validAct(string $ns = AknSchema::NS): string
	{
		return <<<XML
<akomaNtoso xmlns="$ns">
  <act name="act">
    <meta>
      <identification source="#d">
        <FRBRWork>
          <FRBRthis value="/akn/gr/act/2026/5300/main"/>
          <FRBRuri value="/akn/gr/act/2026/5300"/>
          <FRBRdate date="2026-01-01" name="enacted"/>
          <FRBRauthor href="#vouli"/>
          <FRBRcountry value="gr"/>
        </FRBRWork>
        <FRBRExpression>
          <FRBRthis value="/akn/gr/act/2026/5300/ell@/main"/>
          <FRBRuri value="/akn/gr/act/2026/5300/ell@"/>
          <FRBRdate date="2026-01-01" name="enacted"/>
          <FRBRauthor href="#vouli"/>
          <FRBRlanguage language="ell"/>
        </FRBRExpression>
        <FRBRManifestation>
          <FRBRthis value="/akn/gr/act/2026/5300/ell@/main.xml"/>
          <FRBRuri value="/akn/gr/act/2026/5300/ell@.xml"/>
          <FRBRdate date="2026-01-01" name="generated"/>
          <FRBRauthor href="#d"/>
        </FRBRManifestation>
      </identification>
    </meta>
    <body>
      <article eId="art_1">
        <num>Article 1</num>
        <heading>Scope</heading>
        <paragraph eId="art_1__para_1">
          <num>1.</num>
          <content><p>Text of the paragraph.</p></content>
        </paragraph>
      </article>
    </body>
  </act>
</akomaNtoso>
XML;
	}

	public function testNamespaceIsTheWd17TargetNamespace(): void
	{
		$this->assertSame(
			'http://docs.oasis-open.org/legaldocml/ns/akn/3.0/WD17',
			AknSchema::NS
		);
	}

	public function testDocumentTypesAreReadFromTheSchema(): void
	{
		$types = AknSchema::documentTypes();
		// All twelve documentType members, none invented.
		$this->assertSame([
			'amendmentList', 'officialGazette', 'documentCollection', 'act', 'bill',
			'debateReport', 'debate', 'statement', 'amendment', 'judgment',
			'portion', 'doc',
		], $types);
	}

	public function testHierarchicalTypesIncludeEveryAnhierMember(): void
	{
		$hier = AknSchema::hierarchicalTypes();
		$this->assertCount(27, $hier);
		// A few that the old hard-coded list was missing.
		foreach (['rule', 'subrule', 'proviso', 'subdivision', 'transitional'] as $t) {
			$this->assertContains($t, $hier);
		}
		// <hcontainer> is deliberately NOT part of ANhier.
		$this->assertNotContains('hcontainer', $hier);
	}

	public function testSemanticInlinesIncludeEventProcessTime(): void
	{
		$inlines = AknSchema::semanticInlines();
		foreach (['date', 'time', 'person', 'event', 'process', 'term'] as $t) {
			$this->assertContains($t, $inlines);
		}
	}

	public function testUnknownGroupYieldsEmptyList(): void
	{
		$this->assertSame([], AknSchema::elementsInGroup('thisGroupDoesNotExist'));
	}

	public function testValidActValidates(): void
	{
		$this->assertSame([], AknSchema::validate($this->validAct()));
		$this->assertTrue(AknSchema::isValid($this->validAct()));
	}

	public function testWrongNamespaceIsRejected(): void
	{
		// The published 3.0 namespace (no /WD17) is not this schema.
		$doc = $this->validAct('http://docs.oasis-open.org/legaldocml/ns/akn/3.0');
		$this->assertFalse(AknSchema::isValid($doc));
	}

	public function testDocumentWithNonSchemaElementIsRejected(): void
	{
		$doc = str_replace(
			'<content><p>Text of the paragraph.</p></content>',
			'<content><bogusElement>x</bogusElement></content>',
			$this->validAct()
		);
		$this->assertNotSame([], AknSchema::validate($doc));
		$this->assertFalse(AknSchema::isValid($doc));
	}

	public function testEmptyStringHasNoSchemaErrors(): void
	{
		$this->assertSame([], AknSchema::validate(''));
		$this->assertTrue(AknSchema::isValid('   '));
	}

	public function testMalformedXmlIsReportedAsInvalid(): void
	{
		$this->assertNotSame([], AknSchema::validate('<akomaNtoso><act>'));
		$this->assertFalse(AknSchema::isValid('<akomaNtoso><act>'));
	}

	/**
	 * Every bundled fixture under tests/fixtures/ must validate against the
	 * schema — including akn-kitchen-sink.xml, which exercises all 315 of the
	 * schema's global elements.
	 *
	 * @dataProvider provideFixtures
	 */
	public function testBundledFixtureIsSchemaValid(string $file): void
	{
		$errors = AknSchema::validate(file_get_contents($file));
		$this->assertSame([], $errors, basename($file) . ':' . "\n" . implode("\n", $errors));
	}

	public static function provideFixtures(): array
	{
		$files = glob(__DIR__ . '/../../fixtures/*.xml');
		self::assertNotEmpty($files, 'expected fixtures under tests/fixtures/');
		return array_map(static fn(string $f): array => [$f], $files);
	}
}
