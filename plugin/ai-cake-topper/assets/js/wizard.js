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
		/*
		 * Whether AI was used, which is what the €1 surcharge keys off. Set
		 * here for the running total only — the server derives its own answer
		 * from whether the design actually has a generated image, because a
		 * posted flag about whether money was spent cannot be trusted.
		 */
		ai: 'ne',
		design: null
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

		// The preview is shown in the shape being made, not as a square
		// thumbnail — §15, and it is the only place the customer sees what a
		// circular crop does to their picture before paying for it.
		if ( step2.frame ) {
			step2.frame.classList.toggle( 'is-round', state.type !== 'sheet' && state.type !== '' );
		}

		if ( step2.next ) {
			step2.next.disabled = ! state.design;
		}

		if ( step2.hint ) {
			step2.hint.textContent = state.design ? '' : config.i18n.pickDesign;
		}
	}

	/* -------------------------------------------------------- step 2: draw */

	var step2 = {
		prompt: root.querySelector( '#aicake-wizard-prompt' ),
		counter: root.querySelector( '[data-role="counter"]' ),
		generate: root.querySelector( '[data-role="generate"]' ),
		remaining: root.querySelector( '[data-role="remaining"]' ),
		status: root.querySelector( '[data-role="status"]' ),
		statusText: root.querySelector( '[data-role="status-text"]' ),
		error: root.querySelector( '[data-role="error"]' ),
		stage: root.querySelector( '[data-role="stage"]' ),
		frame: root.querySelector( '[data-role="preview-frame"]' ),
		preview: root.querySelector( '[data-role="preview"]' ),
		history: root.querySelector( '[data-role="history"]' ),
		strip: root.querySelector( '[data-role="history-strip"]' ),
		design: root.querySelector( '[data-role="design"]' ),
		next: root.querySelector( '[data-role="next-2"]' ),
		hint: root.querySelector( '[data-role="hint-2"]' )
	};

	function reveal( node, visible ) {
		if ( node ) {
			node.hidden = ! visible;
		}
	}

	function setError( message ) {
		if ( ! step2.error ) {
			return;
		}

		step2.error.textContent = message || '';
		reveal( step2.error, !! message );
	}

	var engine = window.AiCakeGeneration ? window.AiCakeGeneration( config, {
		onBusy: function ( busy ) {
			if ( step2.generate ) { step2.generate.disabled = busy; }
			reveal( step2.status, busy );
			if ( ! busy && step2.statusText ) { step2.statusText.textContent = ''; }
		},
		onStatus: function ( text ) {
			if ( step2.statusText ) { step2.statusText.textContent = text; }
		},
		onError: setError,
		onSession: function ( session ) {
			if ( ! step2.remaining ) { return; }

			var left = session.remaining_generations;

			if ( 'number' !== typeof left ) {
				step2.remaining.textContent = '';
				return;
			}

			step2.remaining.textContent = left > 0
				? config.i18n.remaining.replace( '%d', left )
				: config.i18n.noneLeft;

			if ( step2.generate ) {
				step2.generate.disabled = left <= 0 || engine.isBusy();
			}
		},
		onSuccess: function ( design ) {
			chooseDesign( design );
		}
	} ) : null;

	function chooseDesign( design ) {
		state.design = design.id;

		/*
		 * A generated image is what makes the surcharge apply, so the running
		 * total moves the moment one exists rather than at the cart.
		 */
		state.ai = 'taip';

		if ( step2.preview ) { step2.preview.src = design.url; }
		if ( step2.design ) { step2.design.value = design.id; }

		reveal( step2.stage, true );
		renderHistory();
		update();
	}

	function renderHistory() {
		if ( ! step2.strip || ! engine ) {
			return;
		}

		var items = engine.history();

		reveal( step2.history, items.length > 1 );
		step2.strip.textContent = '';

		items.forEach( function ( item ) {
			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'aicake-thumb' + ( item.id === state.design ? ' is-selected' : '' );
			button.title = config.i18n.reselect;

			var img = document.createElement( 'img' );
			img.src = item.url;
			img.alt = '';
			button.appendChild( img );

			button.addEventListener( 'click', function () {
				chooseDesign( item );
			} );

			step2.strip.appendChild( button );
		} );
	}

	function requestGeneration() {
		if ( ! engine ) {
			return;
		}

		var prompt = step2.prompt ? step2.prompt.value.trim() : '';

		if ( ! prompt ) {
			setError( config.i18n.needPrompt );

			if ( step2.prompt ) { step2.prompt.focus(); }

			return;
		}

		/*
		 * The format goes with the request. The server validates it against
		 * the catalogue and derives the generation aspect from it, so nothing
		 * here decides 1:1 versus 2:3 — they are not independent, and a client
		 * that got it wrong would produce a wrongly cropped image at our cost.
		 */
		engine.generate( {
			prompt: prompt,
			product_id: config.productId,
			variation_id: 0,
			format_type: state.type,
			format_mm: state.mm
		} );
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

	root.querySelectorAll( '[data-role="back-3"]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			show( 2 );
		} );
	} );

	if ( step2.next ) {
		step2.next.addEventListener( 'click', function () {
			if ( ! step2.next.disabled ) {
				show( 3 );
			}
		} );
	}

	if ( step2.generate ) {
		step2.generate.addEventListener( 'click', requestGeneration );
	}

	if ( step2.prompt && step2.counter ) {
		var countPrompt = function () {
			step2.counter.textContent = String( step2.prompt.value.length );
		};

		step2.prompt.addEventListener( 'input', countPrompt );
		countPrompt();
	}

	root.querySelectorAll( '[data-role="chip"]' ).forEach( function ( chip ) {
		chip.addEventListener( 'click', function () {
			if ( ! step2.prompt ) { return; }

			step2.prompt.value = chip.textContent.trim();
			step2.prompt.dispatchEvent( new Event( 'input' ) );
			step2.prompt.focus();
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

	/*
	 * The session call also sets the cookie, so the throttle identity exists
	 * before the first generation rather than being created by it (§7). Issued
	 * from step 1, because by the time someone reaches step 2 they are about to
	 * spend money and a round trip there is a round trip they wait for.
	 */
	if ( engine ) {
		engine.loadSession();
	}
}() );
