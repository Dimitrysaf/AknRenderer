<?php
/**
 * Special page aliases for the AknRenderer extension.
 *
 * @file
 * @license GPL-2.0-or-later
 */

$specialPageAliases = [];

/** English (canonical) */
$specialPageAliases['en'] = [
	'LawsAmendedBy' => ['LawsAmendedBy', 'Laws amended by'],
	'InForceStatus' => ['InForceStatus', 'In-force status'],
];

/** Greek */
$specialPageAliases['el'] = [
	'LawsAmendedBy' => ['ΝόμοιΠουΤροποποιήθηκανΑπό', 'Νόμοι_που_τροποποιήθηκαν_από'],
	'InForceStatus' => ['ΚατάστασηΙσχύος', 'Κατάσταση_ισχύος'],
];
