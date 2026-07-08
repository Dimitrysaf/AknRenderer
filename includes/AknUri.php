<?php
/**
 * Akoma Ntoso URI canonicalisation for cross-reference resolution.
 *
 * Cross-referencing is a plain database match: a page stores its own FRBR
 * Work URI (akn_meta.am_work_uri), and a <ref>/<rref>/<documentRef> that
 * points at that page carries an href. For the two to compare equal they must
 * be reduced to the SAME canonical string — which is all this class does. The
 * indexer canonicalises before storing; the renderer canonicalises the href
 * before the lookup. Keep both on this one function so they can never drift.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

class AknUri
{

	/**
	 * The canonical FRBR Work URI of a reference.
	 *
	 * Given either a page's Work URI or an href pointing at it, it returns the
	 * Work-level path by: dropping any #fragment; removing the leading /akn/
	 * package prefix; and cutting off the expression/manifestation tail — every
	 * segment from the first language-expression component (the one carrying
	 * '@', e.g. /ell@2026-01-15) or manifestation component (starting '!', e.g.
	 * /!main.xml) onward. Example:
	 *
	 *   /akn/gr/act/nomos/2026-01-15/5300/ell@2026-01-15/!main.xml#art_1
	 *     → /gr/act/nomos/2026-01-15/5300
	 *
	 * The Work URI itself has no expression/manifestation part, so it survives
	 * unchanged apart from the /akn/ prefix. Length is not assumed, so it works
	 * for any URI scheme (e.g. the Greek /{country}/{type}/{subtype}/{date}/
	 * {number} form as well as the plain /{country}/{type}/{year}/{number}).
	 *
	 * @param string $uri
	 * @return string The canonical work path, e.g. "/gr/act/nomos/2026-01-15/5300".
	 */
	public static function work(string $uri): string
	{
		$uri = trim($uri);

		// Drop the #fragment (handled separately as the in-page anchor).
		$hash = strpos($uri, '#');
		if ($hash !== false) {
			$uri = substr($uri, 0, $hash);
		}

		// Drop the /akn/ (or akn/) package prefix.
		$uri = preg_replace('#^/?akn/#', '', $uri) ?? $uri;

		$segments = [];
		foreach (explode('/', $uri) as $segment) {
			if ($segment === '') {
				continue;
			}
			// Stop at the expression ('@') or manifestation ('!') component.
			if (strpos($segment, '@') !== false || str_starts_with($segment, '!')) {
				break;
			}
			$segments[] = $segment;
		}

		return '/' . implode('/', $segments);
	}
}
