<?php
/**
 * Shared AKN vocabulary — the constants used across the renderer, the metadata
 * extractor and the structure extractor. Kept in one place so the lists never
 * drift out of sync (e.g. the renderer and the indexer must agree on what a
 * structural element is).
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

class AknVocabulary
{

    /** AKN 3.0 namespace URI. */
    public const NS = 'http://docs.oasis-open.org/legaldocml/ns/akn/3.0';

    /**
     * Native hierarchical structural elements. Used both by the renderer
     * (rendered as <section> wrappers) and by the structure indexer (rows in
     * akn_structure). Single source of truth for "what is a structural unit".
     */
    public const STRUCTURE_TYPES = [
        'book', 'tome', 'part', 'title', 'subtitle', 'chapter', 'subchapter',
        'section', 'subsection', 'division', 'article', 'clause',
        'paragraph', 'subparagraph', 'list', 'point', 'indent', 'alinea', 'level',
    ];

    /**
     * Heading level (h1–h6) per division type, keyed by element localName /
     * hcontainer @name. Unlisted types fall back to h6.
     *
     * @var array<string,int>
     */
    public const HEADING_LEVELS = [
        'part' => 1,
        'section' => 2,
        'subsection' => 3,
        'chapter' => 4,
        'subchapter' => 5,
        'article' => 6,
    ];

    /**
     * Named hcontainers that are enacted blocks set apart inside a provision
     * (not navigational divisions, not annotations), keyed by English @name →
     * Greek label.
     *
     * @var array<string,string>
     */
    public const HCONTAINER_LABELS = [
        'interpretiveClause' => 'Ερμηνευτική δήλωση',
    ];

    /** Inline elements rendered as a semantic span with class akn-{name}. */
    public const INLINE_SPANS = [
        'def', 'term', 'entity', 'organization', 'person', 'role',
        'location', 'quantity', 'quotedText', 'concept', 'object',
    ];

    /** Greek document-type labels, keyed by lowercased AKN @name/@subtype. */
    public const DOC_TYPES = [
        'nomos' => 'Νόμος',
        'νόμος' => 'Νόμος',
        'act' => 'Νομοθετική πράξη',
        'pd' => 'Προεδρικό Διάταγμα',
        'proedrikodiatagma' => 'Προεδρικό Διάταγμα',
        'pnp' => 'Πράξη Νομοθετικού Περιεχομένου',
        'ya' => 'Υπουργική Απόφαση',
        'kya' => 'Κοινή Υπουργική Απόφαση',
        'nomosplaisio' => 'Νόμος-Πλαίσιο',
        'constitution' => 'Σύνταγμα',
    ];

    /** Country labels, keyed by lowercased ISO code. */
    public const COUNTRIES = [
        'gr' => 'Ελλάδα',
        'cy' => 'Κύπρος',
        'eu' => 'Ευρωπαϊκή Ένωση',
    ];

    /** Language labels, keyed by lowercased ISO 639 code. */
    public const LANGUAGES = [
        'ell' => 'Ελληνικά',
        'el' => 'Ελληνικά',
        'eng' => 'Αγγλικά',
        'en' => 'Αγγλικά',
        'fra' => 'Γαλλικά',
        'fre' => 'Γαλλικά',
        'deu' => 'Γερμανικά',
        'ger' => 'Γερμανικά',
    ];
}
