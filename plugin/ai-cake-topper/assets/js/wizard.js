/**
 * Wizard step 1 — format, size and sheet type.
 *
 * No framework and no build step: production is plain WordPress, and a
 * bundler in the loop would be one more thing that has to work on a shared
 * host before a customer can buy a cake topper.
 */
( function () {
	'use strict';

	var config = window.aicakeWizard;

	if ( ! config ) {
		return;
	}

	var root = document.querySelector( '.aicake-wizard' );

	if ( ! root ) {
		return;
	}

	var sizeField = root.querySelector( '[data-role="size"]' );
	var sizeInput = root.querySelector( '#aicake-size' );
	var pieces    = root.querySelector( '[data-role="pieces"]' );
	var sheets    = root.querySelector( '[data-role="sheets"]' );
	var price     = root.querySelector( '[data-role="price"]' );
	var priceNote = root.querySelector( '[data-role="price-note"]' );
	var next      = root.querySelector( '[data-role="next"]' );
	var hint      = root.querySelector( '[data-role="hint"]' );
	var summary   = root.querySelector( '[data-role="summary"]' );

	var state = {
		type: '',
		mm: null,
		sheet: config.sheets.length ? config.sheets[ 0 ].value : '',
		ai: 'ne'
	};

	/* ------------------------------------------------------------ sheets */

	function renderSheets() {
		config.sheets.forEach( function ( sheet, index ) {
			var label = document.createElement( 'label' );
			label.className = 'aicake-sheet';

			var input = document.createElement( 'input' );
			input.type = 'radio';
			input.name = 'aicake_sheet';
			input.value = sheet.value;
			input.checked = index === 0;

			input.addEventListener( 'change', function () {
				state.sheet = sheet.value;
				update();
			} );

			var text = document.createElement( 'span' );
			text.textContent = sheet.label;

			label.appendChild( input );
			label.appendChild( text );
			sheets.appendChild( label );
		} );
	}

	/* ------------------------------------------------------------- sizes */

	function renderSizes( type ) {
		var options = config.formats[ type ] || [];

		sizeInput.innerHTML = '';

		options.forEach( function ( option ) {
			var element = document.createElement( 'option' );
			element.value = String( option.mm );
			element.textContent = option.label;
			element.dataset.perSheet = String( option.perSheet );
			sizeInput.appendChild( element );
		} );

		/*
		 * A whole sheet has exactly one size, so asking about it would be a
		 * question with one answer. The value is still set, because the server
		 * validates type and size together.
		 */
		var single = options.length <= 1;

		sizeField.hidden = single;
		state.mm = options.length ? options[ 0 ].mm : null;

		if ( ! single ) {
			sizeInput.value = String( state.mm );
		}
	}

	function currentOption() {
		var options = config.formats[ state.type ] || [];

		for ( var i = 0; i < options.length; i++ ) {
			if ( Math.abs( options[ i ].mm - state.mm ) < 0.05 ) {
				return options[ i ];
			}
		}

		return null;
	}

	/* ------------------------------------------------------------- price */

	function update() {
		var option = currentOption();

		/*
		 * "As many as fit" is invisible unless it is said (D-039). Someone
		 * choosing a 10 cm circle and receiving four should read that here,
		 * not discover it when the envelope arrives.
		 */
		if ( option && option.perSheet > 1 ) {
			pieces.textContent = config.i18n.pieces.replace( '%d', option.perSheet );
		} else if ( option ) {
			pieces.textContent = config.i18n.onePiece;
		} else {
			pieces.textContent = '';
		}

		var entry = config.prices[ state.sheet + '|' + state.ai ];

		if ( entry ) {
			price.innerHTML = entry.html;
		}

		var ready = state.type !== '' && option !== null;

		next.disabled = ! ready;
		hint.textContent = ready ? '' : ( state.type === '' ? config.i18n.pickFormat : config.i18n.pickSize );

		if ( summary && option ) {
			summary.textContent = option.label;
		}
	}

	/* -------------------------------------------------------------- steps */

	function show( step ) {
		root.dataset.step = String( step );

		root.querySelectorAll( '.aicake-wizard__step' ).forEach( function ( section ) {
			section.hidden = Number( section.dataset.step ) !== step;
		} );

		root.querySelectorAll( '.aicake-wizard__progress li' ).forEach( function ( item ) {
			item.classList.toggle( 'is-current', Number( item.dataset.for ) === step );
			item.classList.toggle( 'is-done', Number( item.dataset.for ) < step );
		} );

		/*
		 * Addressable, or the back button leaves the wizard entirely and takes
		 * the customer's choices with it (D-034).
		 */
		if ( window.location.hash !== '#step-' + step ) {
			window.history.pushState( null, '', '#step-' + step );
		}
	}

	function stepFromHash() {
		var match = /^#step-(\d+)$/.exec( window.location.hash );

		return match ? Number( match[ 1 ] ) : 1;
	}

	/* --------------------------------------------------------------- wire */

	root.querySelectorAll( 'input[name="aicake_format_type"]' ).forEach( function ( input ) {
		input.addEventListener( 'change', function () {
			state.type = input.value;
			renderSizes( state.type );
			update();
		} );
	} );

	sizeInput.addEventListener( 'change', function () {
		state.mm = parseFloat( sizeInput.value );
		update();
	} );

	next.addEventListener( 'click', function () {
		if ( next.disabled ) {
			return;
		}

		show( 2 );
	} );

	root.querySelectorAll( '[data-role="back"]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			show( 1 );
		} );
	} );

	window.addEventListener( 'popstate', function () {
		show( stepFromHash() );
	} );

	/**
	 * What step 1 decided, for the steps that follow.
	 *
	 * Read rather than posted: the format only reaches the server when a
	 * design is generated, and the server validates it against the catalogue
	 * there — this object is a convenience, never the authority.
	 */
	window.aicakeWizardState = state;

	renderSheets();
	update();
	show( stepFromHash() );
}() );
