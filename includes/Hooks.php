<?php
/**
 * Setup / registration callback for the AknRenderer extension.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

class Hooks
{

	/**
	 * Runs once at extension-registration time.
	 *
	 * Defines the content-model constant so PHP code can refer to
	 * CONTENT_MODEL_AKN instead of the bare 'akn-xml' string. The value MUST
	 * match the key used in the "ContentHandlers" section of extension.json
	 * and the "defaultcontentmodel" of the Law namespace.
	 */
	public static function onRegistration(): void
	{
		if (!defined('CONTENT_MODEL_AKN')) {
			define('CONTENT_MODEL_AKN', 'akn-xml');
		}
	}
}
