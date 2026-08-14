/**
 * TopTex for WooCommerce — admin helpers.
 *
 * Adds copy-to-clipboard for the diagnostic report block on the settings page.
 *
 * @package TopTex_WooCommerce
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-toptex-copy]' );
		if ( ! button ) {
			return;
		}

		var target = document.getElementById( button.getAttribute( 'data-toptex-copy' ) );
		if ( ! target ) {
			return;
		}

		var text = target.value || target.textContent || '';

		function done( ok ) {
			var original = button.textContent;
			button.textContent = ok ? 'Copied!' : 'Copy failed';
			button.disabled = true;
			window.setTimeout( function () {
				button.textContent = original;
				button.disabled = false;
			}, 1500 );
		}

		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then(
				function () { done( true ); },
				function () { fallback(); }
			);
		} else {
			fallback();
		}

		function fallback() {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild( ta );
			ta.select();
			var ok = false;
			try {
				ok = document.execCommand( 'copy' );
			} catch ( e ) {
				ok = false;
			}
			document.body.removeChild( ta );
			done( ok );
		}
	} );
}() );
