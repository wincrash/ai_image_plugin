/**
 * Keyboard shortcuts for the review queue.
 *
 * §14 asks for these because the screen gets used dozens of times a day, and
 * a mouse round trip per order is the difference between reviewing a morning's
 * orders in two minutes and in ten.
 *
 * No framework: this is wp-admin, and the whole file is smaller than the
 * loader for one.
 */
( function () {
	'use strict';

	var cards = Array.prototype.slice.call( document.querySelectorAll( '.aicake-review__card' ) );

	if ( ! cards.length ) {
		return;
	}

	var current = 0;

	function focus( index ) {
		current = Math.max( 0, Math.min( cards.length - 1, index ) );

		cards.forEach( function ( card, i ) {
			card.classList.toggle( 'is-current', i === current );
		} );

		cards[ current ].scrollIntoView( { block: 'center', behavior: 'smooth' } );
		cards[ current ].focus( { preventScroll: true } );
	}

	function submit( decision ) {
		var form = cards[ current ].querySelector( 'form' );

		if ( ! form ) {
			return;
		}

		/*
		 * Rejecting needs a reason and the customer reads it, so the shortcut
		 * puts the cursor in the field rather than sending an empty rejection.
		 * A second R once there would type an R, which is why this only fires
		 * when the field is not already focused — see the guard below.
		 */
		if ( 'reject' === decision ) {
			var reason = form.querySelector( '[data-role="reason"]' );

			if ( reason && '' === reason.value.trim() ) {
				reason.focus();

				return;
			}
		}

		var button = form.querySelector( '[value="' + decision + '"]' );

		if ( button ) {
			button.click();
		}
	}

	document.addEventListener( 'keydown', function ( event ) {
		// Never steal a keystroke from a field. Typing „rožė" into a rejection
		// reason must not approve the order on the „r".
		var tag = ( event.target && event.target.tagName ) || '';

		if ( 'INPUT' === tag || 'TEXTAREA' === tag || 'SELECT' === tag || event.target.isContentEditable ) {
			return;
		}

		if ( event.metaKey || event.ctrlKey || event.altKey ) {
			return;
		}

		switch ( event.key.toLowerCase() ) {
			case 'j':
				event.preventDefault();
				focus( current + 1 );
				break;
			case 'k':
				event.preventDefault();
				focus( current - 1 );
				break;
			case 'a':
				event.preventDefault();
				submit( 'approve' );
				break;
			case 'r':
				event.preventDefault();
				submit( 'reject' );
				break;
		}
	} );

	// Clicking anywhere in a card makes it the current one, so the keyboard and
	// the mouse never disagree about which order is being decided.
	cards.forEach( function ( card, index ) {
		card.addEventListener( 'focusin', function () {
			focus( index );
		} );
	} );

	focus( 0 );
}() );
