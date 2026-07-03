<?php
/**
 * Localized namespace names for the AknRenderer extension.
 *
 * The DISPLAYED namespace name follows the wiki's content language
 * ($wgLanguageCode). On a Greek wiki ('el') the Law namespace shows as
 * "Νόμος"; the canonical English "Law" (declared in extension.json) keeps
 * working as an input synonym, exactly like core's File / Αρχείο.
 *
 * The constant guards exist because this file may be parsed while building
 * the localisation cache, before the extension.json namespace constants are
 * defined. The ids MUST match the "namespaces" section of extension.json.
 *
 * @file
 * @license GPL-2.0-or-later
 */

if (!defined('NS_LAW')) {
	define('NS_LAW', 3000);
}
if (!defined('NS_LAW_TALK')) {
	define('NS_LAW_TALK', 3001);
}
if (!defined('NS_GAZETTE')) {
	define('NS_GAZETTE', 3002);
}
if (!defined('NS_GAZETTE_TALK')) {
	define('NS_GAZETTE_TALK', 3003);
}

$namespaceNames = [];

// English — canonical fallback (matches extension.json).
$namespaceNames['en'] = [
	NS_LAW => 'Law',
	NS_LAW_TALK => 'Law_talk',
	NS_GAZETTE => 'Gazette',
	NS_GAZETTE_TALK => 'Gazette_talk',
];

// Greek — shown when $wgLanguageCode = 'el'.
$namespaceNames['el'] = [
	NS_LAW => 'Νόμος',
	NS_LAW_TALK => 'Συζήτηση_Νόμου',
	NS_GAZETTE => 'ΦΕΚ',
	NS_GAZETTE_TALK => 'Συζήτηση_ΦΕΚ',
];
