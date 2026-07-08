<?php
/**
 * Shared AKN vocabulary facade.
 *
 * The *vocabulary* itself — which elements are document roots, hierarchical
 * containers or semantic inlines — is not defined here: it is read from the
 * schema (see AknSchema), so the code can never drift from
 * schema/akomantoso30.xsd. This class only adds the things the schema does
 * not carry: presentation choices (heading levels, canonical Greek labels)
 * and the routing map from each document type to its main-body container.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

class AknVocabulary
{

	/** AKN namespace URI — the schema's target namespace (WD17). */
	public const NS = AknSchema::NS;

	/**
	 * The main-body containers, one per document structure type in the schema
	 * (openStructure→mainBody, hierarchicalStructure→body,
	 * debateStructure→debateBody, judgmentStructure→judgmentBody,
	 * amendmentStructure→amendmentBody, portionStructure→portionBody). A
	 * collection (officialGazette/amendmentList/documentCollection) has no
	 * main body — it has a <collectionBody>, handled separately.
	 *
	 * @var list<string>
	 */
	public const MAIN_BODY_CONTAINERS = [
		'body', 'mainBody', 'debateBody', 'judgmentBody', 'amendmentBody', 'portionBody',
	];

	/** The collection-of-documents body (officialGazette et al.). */
	public const COLLECTION_BODY_CONTAINER = 'collectionBody';

	/**
	 * Native hierarchical structural elements (schema group ANhier). Used both
	 * by the renderer (rendered as <section> wrappers) and by the structure
	 * indexer (rows in akn_structure).
	 *
	 * @return list<string>
	 */
	public static function structureTypes(): array
	{
		return AknSchema::hierarchicalTypes();
	}

	/**
	 * Document-type root elements (schema group documentType).
	 *
	 * @return list<string>
	 */
	public static function documentTypes(): array
	{
		return AknSchema::documentTypes();
	}

	/**
	 * Semantic inline elements rendered as a span with class akn-{name}
	 * (schema group ANsemanticInline). <date>/<time> also appear here but are
	 * given richer, dedicated rendering by the handler.
	 *
	 * @return list<string>
	 */
	public static function inlineSpans(): array
	{
		return AknSchema::semanticInlines();
	}

	/**
	 * Heading level (h1–h6) per division type, keyed by element localName /
	 * hcontainer @name. Unlisted types fall back to h6. Presentation only.
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
