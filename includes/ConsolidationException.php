<?php
/**
 * Thrown by Consolidator when an amendment instruction can't be applied. Carries
 * an i18n message key so the caller (Special:PendingAmendments) can surface a
 * localised reason.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

class ConsolidationException extends \Exception
{
	private string $messageKey;

	public function __construct(string $messageKey)
	{
		parent::__construct($messageKey);
		$this->messageKey = $messageKey;
	}

	public function getMessageKey(): string
	{
		return $this->messageKey;
	}
}
