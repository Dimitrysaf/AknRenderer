<?php
/**
 * Content object for the akn-xml content model.
 *
 * For now this is a thin wrapper over TextContent: the stored content is the
 * AKN XML, treated as text. Rendering (the <body> walker) and validation
 * (the CSD13 schema gate) are added in later steps via the ContentHandler.
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
}
