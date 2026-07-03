/**
 * Flashes the element a same-page anchor (footnote backref, cross-reference)
 * points to.
 *
 * Deliberately avoids CSS `@keyframes`/`animation` entirely: those can be
 * suppressed by the browser when the OS-level "reduce motion" preference is
 * on (Windows Settings > Ease of Access > Display > Show animations), and
 * browsers differ in how strictly they honour that for page-authored
 * animations. Driving the highlight via a plain `transition` on an inline
 * style, set directly from JS, sidesteps that entirely — and
 * prefers-reduced-motion is checked explicitly so those users still get a
 * (static, non-animated) highlight instead of silently nothing.
 *
 * Triggered from the click itself, not a `hashchange` listener: if
 * anything on the page handles the anchor click with
 * `history.pushState`/`replaceState` instead of a native fragment
 * navigation, `hashchange` never fires at all.
 */
( function () {
	'use strict';

	var HIGHLIGHT_COLOR = '#ffec6e9c';
	var FLASH_COUNT = 3;
	var FADE_MS = 500;
	var GAP_MS = 200;
	var STATIC_ON_MS = 300;
	var STATIC_GAP_MS = 200;

	function prefersReducedMotion() {
		return !!( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
	}

	function idFromFragment( fragment ) {
		if ( !fragment || fragment.length < 2 ) {
			return null;
		}
		try {
			return decodeURIComponent( fragment.slice( 1 ) );
		} catch ( e ) {
			return fragment.slice( 1 );
		}
	}

	/** One fade-out pulse, animated via transition; recurses FLASH_COUNT times. */
	function pulse( el, index ) {
		if ( index >= FLASH_COUNT ) {
			return;
		}
		el.style.transition = 'none';
		el.style.backgroundColor = HIGHLIGHT_COLOR;
		// Force a reflow so the browser commits the instant colour above
		// before the transition below is applied — otherwise there's
		// nothing for it to animate from.
		// eslint-disable-next-line no-unused-expressions
		el.offsetWidth;
		el.style.transition = 'background-color ' + FADE_MS + 'ms ease-out';
		el.style.backgroundColor = 'transparent';
		window.setTimeout( function () {
			pulse( el, index + 1 );
		}, FADE_MS + GAP_MS );
	}

	/** Same, but a plain on/off blink with no transition, for reduced motion. */
	function pulseStatic( el, index ) {
		if ( index >= FLASH_COUNT ) {
			return;
		}
		el.style.transition = 'none';
		el.style.backgroundColor = HIGHLIGHT_COLOR;
		window.setTimeout( function () {
			el.style.backgroundColor = '';
			window.setTimeout( function () {
				pulseStatic( el, index + 1 );
			}, STATIC_GAP_MS );
		}, STATIC_ON_MS );
	}

	function flashElement( el ) {
		if ( !el || !el.closest( '.akn-document' ) ) {
			return;
		}
		if ( prefersReducedMotion() ) {
			pulseStatic( el, 0 );
			return;
		}
		pulse( el, 0 );
	}

	function flashFragment( fragment ) {
		var id = idFromFragment( fragment );
		if ( id !== null ) {
			flashElement( document.getElementById( id ) );
		}
	}

	function onClick( event ) {
		var link = event.target.closest && event.target.closest( 'a[href*="#"]' );
		if ( !link ) {
			return;
		}
		var href = link.getAttribute( 'href' ) || '';
		var hashIndex = href.indexOf( '#' );
		if ( hashIndex === -1 ) {
			return;
		}
		var target = document.getElementById( idFromFragment( href.slice( hashIndex ) ) || '' );
		if ( !target ) {
			return;
		}
		// Defer past whatever else the click triggers (skin scroll handling,
		// possible pushState) so this doesn't depend on running first.
		window.setTimeout( function () {
			flashElement( target );
		}, 0 );
	}

	function init() {
		flashFragment( window.location.hash );
		document.addEventListener( 'click', onClick );
		window.addEventListener( 'hashchange', function () {
			flashFragment( window.location.hash );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
