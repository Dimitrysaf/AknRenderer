<?php

namespace MediaWiki\Extension\AknRenderer\Tests\Unit;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\AknRenderer\AknVocabulary;
use MediaWiki\Extension\AknRenderer\HookHandler;
use MediaWiki\Page\WikiPageFactory;
use MediaWikiUnitTestCase;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * @covers \MediaWiki\Extension\AknRenderer\HookHandler
 */
class HookHandlerTest extends MediaWikiUnitTestCase
{

	/**
	 * Cheap regression guard: catches a renamed/typo'd AknVocabulary
	 * constant that this hook forgot to update, since nothing else checks
	 * that the exported JS config actually matches the PHP source of truth.
	 */
	public function testExportsAknVocabularyConfigVars(): void
	{
		$handler = new HookHandler(
			$this->createMock(IConnectionProvider::class),
			$this->createMock(WikiPageFactory::class)
		);

		$vars = [];
		$handler->onResourceLoaderGetConfigVars($vars, 'vector', new HashConfig());

		$this->assertArrayHasKey('wgAknVocabulary', $vars);
		$this->assertSame(AknVocabulary::NS, $vars['wgAknVocabulary']['namespace']);
		$this->assertSame(AknVocabulary::documentTypes(), $vars['wgAknVocabulary']['documentTypes']);
		$this->assertSame(AknVocabulary::structureTypes(), $vars['wgAknVocabulary']['structureTypes']);
		$this->assertSame(AknVocabulary::inlineSpans(), $vars['wgAknVocabulary']['inlineSpans']);
		$this->assertSame(AknVocabulary::MAIN_BODY_CONTAINERS, $vars['wgAknVocabulary']['mainBodyContainers']);
		$this->assertSame(AknVocabulary::HEADING_LEVELS, $vars['wgAknVocabulary']['headingLevels']);
		$this->assertSame(AknVocabulary::HCONTAINER_LABELS, $vars['wgAknVocabulary']['hcontainerLabels']);
		$this->assertSame(AknVocabulary::DOC_TYPES, $vars['wgAknVocabulary']['docTypes']);
		$this->assertSame(AknVocabulary::COUNTRIES, $vars['wgAknVocabulary']['countries']);
		$this->assertSame(AknVocabulary::LANGUAGES, $vars['wgAknVocabulary']['languages']);
	}
}
