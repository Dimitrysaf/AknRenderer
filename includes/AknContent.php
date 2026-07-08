<?php
/**
 * Content object for the akn-xml content model.
 *
 * A thin wrapper over TextContent: the stored content is the AKN XML, treated
 * as text. Validity is defined by the schema — isValid() returns true iff the
 * XML satisfies schema/akomantoso30.xsd (see AknSchema), which is the single
 * source of truth for what counts as Akoma Ntoso. This backs the primary,
 * message-bearing gate in HookHandler::onEditFilterMergedContent() and also
 * covers non-edit paths (imports, direct API writes) that don't run it.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

use MediaWiki\Content\TextContent;

class AknContent extends TextContent
{

	/**
	 * @param string $text The raw AKN XML.
	 */
	public function __construct(string $text)
	{
		parent::__construct($text, CONTENT_MODEL_AKN);
	}

	/**
	 * Valid iff the content validates against the AKN schema. An empty
	 * document is left to MediaWiki's own blank-page handling (AknSchema
	 * reports no schema errors for empty input).
	 *
	 * @return bool
	 */
	public function isValid()
	{
		return AknSchema::isValid($this->getText());
	}
}
