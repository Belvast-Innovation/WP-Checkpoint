/**
 * Copy the environment report to the clipboard. Without JavaScript the
 * textarea can still be selected and copied by hand.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-wpcheckpoint-copy]' );
		if ( ! button ) {
			return;
		}
		var target = document.getElementById( button.getAttribute( 'data-wpcheckpoint-copy' ) );
		if ( ! target ) {
			return;
		}
		target.focus();
		target.select();
		var done = function () {
			var original = button.textContent;
			button.textContent = button.getAttribute( 'data-copied-label' ) || 'Copied';
			window.setTimeout( function () {
				button.textContent = original;
			}, 2000 );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( target.value ).then( done, function () {
				document.execCommand( 'copy' );
				done();
			} );
		} else {
			document.execCommand( 'copy' );
			done();
		}
	} );
}() );
