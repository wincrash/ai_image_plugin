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

	/* --------------------------------------------------- step 3: the text */

	var step3 = {
		canvas: root.querySelector( '[data-role="editor-canvas"]' ),
		piecesField: root.querySelector( '[data-role="pieces-field"]' ),
		sameForAll: root.querySelector( '[data-role="same-for-all"]' ),
		picker: root.querySelector( '[data-role="piece-picker"]' ),
		lines: root.querySelector( '[data-role="lines"]' ),
		addLine: root.querySelector( '[data-role="add-line"]' ),
		fontPicker: root.querySelector( '[data-role="fontpicker"]' ),
		fontButton: root.querySelector( '[data-role="font-button"]' ),
		fontList: root.querySelector( '[data-role="font-list"]' ),
		fontHandle: config.fonts && config.fonts.length ? config.fonts[ 0 ].handle : '',
		outline: root.querySelector( '[data-role="outline"]' ),
		outlineColour: root.querySelector( '[data-role="outline-colour"]' ),
		error: root.querySelector( '[data-role="error-3"]' ),
		next: root.querySelector( '[data-role="next-3"]' ),
		hint: root.querySelector( '[data-role="hint-3"]' )
	};

	var editor = window.AiCakeEditor ? window.AiCakeEditor( config, {
		onChange: function () {
			syncControls();
		},
		onError: function ( message ) {
			if ( step3.error ) {
				step3.error.textContent = message || '';
				step3.error.hidden = ! message;
			}
		},
		onBusy: function ( busy ) {
			if ( step3.next ) { step3.next.disabled = busy; }
			if ( step3.hint ) { step3.hint.textContent = busy ? config.i18n.savingText : ''; }
		}
	} ) : null;

	var editorMounted = false;

	/**
	 * The geometry for the format chosen at step 1.
	 *
	 * Looked up, never derived. D-033 is explicit that the client must not
	 * compute piece positions — text would land across a gutter and still look
	 * right on screen.
	 */
	function currentLayout() {
		return config.layouts ? config.layouts[ state.type + '|' + state.mm ] : null;
	}

	function mountEditor() {
		if ( ! editor || ! step3.canvas || editorMounted ) {
			return;
		}

		var layout = currentLayout();

		if ( ! layout || ! state.design ) {
			return;
		}

		editorMounted = true;

		editor.mount( step3.canvas, layout, step2.preview ? step2.preview.src : '' ).then( function () {
			// One empty line to start. An editor with no rows looks like it has
			// not loaded, and "Pridėti eilutę" is not an obvious first move.
			if ( editor.lines().length === 0 ) {
				editor.addLine( '' );
			}

			syncControls();
		} );

		if ( step3.piecesField ) {
			step3.piecesField.hidden = layout.pieces.length < 2;
		}
	}

	function renderPiecePicker() {
		if ( ! step3.picker || ! editor ) {
			return;
		}

		var editorState = editor.state();
		var layout = editorState.layout;

		if ( ! layout || editorState.sameForAll || layout.pieces.length < 2 ) {
			step3.picker.hidden = true;

			return;
		}

		step3.picker.hidden = false;
		step3.picker.textContent = '';

		layout.pieces.forEach( function ( piece, index ) {
			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'aicake-piece' + ( index === editorState.selected ? ' is-selected' : '' );
			button.textContent = String( index + 1 );
			button.title = config.i18n.piece.replace( '%d', index + 1 );

			button.addEventListener( 'click', function () {
				editor.selectPiece( index );
			} );

			step3.picker.appendChild( button );
		} );
	}

	/**
	 * Refresh the controls after any editor change.
	 *
	 * **The rows are only rebuilt when the set of rows actually changes.**
	 * Rebuilding them on every change destroys and recreates the `<input
	 * type="color">` — and a native colour dialog belongs to the element that
	 * opened it, so it closes the instant that element is replaced. Dragging
	 * inside the picker fires `input` continuously, so the picker shut on the
	 * first movement and choosing a colour was impossible.
	 *
	 * Editing a value never changes the signature, so typing, resizing and
	 * picking a colour all leave the DOM alone and keep focus where it was.
	 */
	function syncControls() {
		var signature = controlsSignature();

		if ( signature !== lastControls ) {
			lastControls = signature;
			renderLineControls();
			renderPiecePicker();
		}

		// The font list previews the customer's own text, so it follows what
		// they type. Nothing in it can hold an open dialog.
		renderFontChoices();
	}

	/**
	 * What the control rows depend on structurally — not their values.
	 */
	function controlsSignature() {
		if ( ! editor ) {
			return '';
		}

		var editorState = editor.state();

		return [
			editorState.sameForAll ? 'all' : editorState.selected,
			editor.lines().length
		].join( '|' );
	}

	var lastControls = null;

	/**
	 * One row per line of text, for whichever piece is selected.
	 *
	 * Rebuilt rather than diffed. There are at most a handful of rows and the
	 * alternative is state in two places — but see `syncControls()` for why
	 * this must not run on every change.
	 */
	function renderLineControls() {
		if ( ! step3.lines || ! editor ) {
			return;
		}

		var lines = editor.lines();
		var focusIndex = document.activeElement && document.activeElement.dataset
			? document.activeElement.dataset.lineIndex
			: null;

		step3.lines.textContent = '';

		lines.forEach( function ( line, index ) {
			var row = document.createElement( 'div' );
			row.className = 'aicake-line';

			var input = document.createElement( 'input' );
			input.type = 'text';
			input.className = 'aicake-line__text';
			input.value = line.text;
			input.maxLength = 40;
			input.dataset.lineIndex = String( index );
			input.setAttribute( 'aria-label', config.i18n.addLine );

			input.addEventListener( 'input', function () {
				line.text = input.value;
				editor.changed();
			} );

			row.appendChild( input );

			/*
			 * A real colour control. The endpoint caps how *many* distinct
			 * colours a layer may declare, not which ones, so a free picker is
			 * no weaker than a fixed swatch list — four arbitrary colours and
			 * four chosen ones give `LayerInspector` exactly the same job.
			 */
			var colour = document.createElement( 'input' );
			colour.type = 'color';
			colour.className = 'aicake-line__colour';
			colour.value = line.colour;
			colour.title = config.i18n.colour;

			colour.addEventListener( 'input', function () {
				var previous = line.colour;

				line.colour = colour.value;

				// Checked after the change, because changing the only red line
				// to blue removes red — the cap is on the colours actually in
				// use, not on how many have ever been picked.
				if ( editor.paletteFull() ) {
					line.colour = previous;
					colour.value = previous;

					if ( step3.error ) {
						step3.error.textContent = config.i18n.tooManyColours.replace( '%d', config.maxColours );
						step3.error.hidden = false;
					}

					return;
				}

				if ( step3.error ) {
					step3.error.hidden = true;
				}

				editor.changed();
			} );

			row.appendChild( colour );

			var smaller = document.createElement( 'button' );
			smaller.type = 'button';
			smaller.className = 'aicake-line__size';
			smaller.textContent = '−';

			smaller.addEventListener( 'click', function () {
				line.size = Math.round( line.size * 0.9 );
				editor.changed();
			} );

			var bigger = document.createElement( 'button' );
			bigger.type = 'button';
			bigger.className = 'aicake-line__size';
			bigger.textContent = '+';

			// Growing is clamped by the safe zone inside the editor, so this
			// can be optimistic — constrain() pulls it back if it does not fit.
			bigger.addEventListener( 'click', function () {
				line.size = Math.round( line.size * 1.1 );
				editor.changed();
			} );

			var remove = document.createElement( 'button' );
			remove.type = 'button';
			remove.className = 'aicake-line__remove';
			remove.textContent = '×';
			remove.title = config.i18n.removeLine;

			remove.addEventListener( 'click', function () {
				editor.removeLine( index );
			} );

			row.appendChild( smaller );
			row.appendChild( bigger );
			row.appendChild( remove );

			step3.lines.appendChild( row );
		} );

		if ( focusIndex !== null ) {
			var restore = step3.lines.querySelector( '[data-line-index="' + focusIndex + '"]' );

			if ( restore ) {
				restore.focus();
				restore.setSelectionRange( restore.value.length, restore.value.length );
			}
		}
	}

	/**
	 * The font list, each entry drawn in the font it selects.
	 *
	 * Shows the customer's own text where there is any, because "Ąžuolas" in a
	 * face is a far better basis for choosing than the face's name is — and it
	 * is also the moment a font with no Lithuanian glyphs would become obvious.
	 * (`FontCatalogue` refuses those already, so this is belt and braces.)
	 */
	function renderFontChoices() {
		if ( ! step3.fontList || ! step3.fontButton ) {
			return;
		}

		var fonts = config.fonts || [];

		step3.fontList.textContent = '';

		fonts.forEach( function ( font ) {
			var item = document.createElement( 'li' );
			item.className = 'aicake-fontpicker__item' + ( font.handle === step3.fontHandle ? ' is-selected' : '' );
			item.setAttribute( 'role', 'option' );
			item.setAttribute( 'aria-selected', font.handle === step3.fontHandle ? 'true' : 'false' );
			item.tabIndex = 0;
			item.style.fontFamily = '"' + font.handle + '", sans-serif';
			item.textContent = fontSample( font );

			var choose = function () {
				step3.fontHandle = font.handle;

				if ( editor ) {
					editor.setFont( font.handle );
				}

				closeFontList();
				renderFontChoices();
			};

			item.addEventListener( 'click', choose );
			item.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Enter' || event.key === ' ' ) {
					event.preventDefault();
					choose();
				}
			} );

			step3.fontList.appendChild( item );
		} );

		var current = fonts.filter( function ( font ) {
			return font.handle === step3.fontHandle;
		} )[ 0 ] || fonts[ 0 ];

		if ( current ) {
			step3.fontButton.style.fontFamily = '"' + current.handle + '", sans-serif';
			step3.fontButton.textContent = fontSample( current );
		}
	}

	function fontSample( font ) {
		var typed = editor ? editor.plainText().trim() : '';

		return typed !== '' ? typed.slice( 0, 24 ) : font.label;
	}

	function openFontList() {
		if ( ! step3.fontList ) {
			return;
		}

		step3.fontList.hidden = false;
		step3.fontButton.setAttribute( 'aria-expanded', 'true' );
	}

	function closeFontList() {
		if ( ! step3.fontList ) {
			return;
		}

		step3.fontList.hidden = true;
		step3.fontButton.setAttribute( 'aria-expanded', 'false' );
	}

	/**
	 * Save the layer, then move on.
	 *
	 * A layer with no text is not posted at all — there is nothing to check and
	 * nothing to composite, and the endpoint would refuse an empty bitmap.
	 */
	function finishText() {
		if ( ! editor || ! editor.hasText() ) {
			show( 4 );

			return;
		}

		editor.save( state.design ).then( function () {
			show( 4 );
		} ).catch( function () {
			// The error is already on screen through onError.
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
				mountEditor();
			}
		} );
	}

	root.querySelectorAll( '[data-role="back-4"]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			show( 3 );
		} );
	} );

	if ( step3.addLine ) {
		step3.addLine.addEventListener( 'click', function () {
			if ( editor ) {
				editor.addLine( '' );
			}
		} );
	}

	if ( step3.sameForAll ) {
		step3.sameForAll.addEventListener( 'change', function () {
			if ( editor ) {
				editor.setSameForAll( step3.sameForAll.checked );
			}
		} );
	}

	if ( step3.fontButton ) {
		step3.fontButton.addEventListener( 'click', function ( event ) {
			event.stopPropagation();

			if ( step3.fontList.hidden ) {
				openFontList();
			} else {
				closeFontList();
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( step3.fontPicker && ! step3.fontPicker.contains( event.target ) ) {
				closeFontList();
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' ) {
				closeFontList();
			}
		} );
	}

	if ( step3.outline ) {
		step3.outline.addEventListener( 'change', function () {
			if ( editor ) {
				editor.setOutline( step3.outline.checked, step3.outlineColour ? step3.outlineColour.value : null );
			}
		} );
	}

	if ( step3.outlineColour ) {
		step3.outlineColour.addEventListener( 'input', function () {
			if ( ! editor ) {
				return;
			}

			var previous = editor.state().outlineColour;

			editor.setOutline( step3.outline ? step3.outline.checked : true, step3.outlineColour.value );

			if ( editor.paletteFull() ) {
				step3.outlineColour.value = previous;
				editor.setOutline( step3.outline ? step3.outline.checked : true, previous );

				if ( step3.error ) {
					step3.error.textContent = config.i18n.tooManyColours.replace( '%d', config.maxColours );
					step3.error.hidden = false;
				}
			}
		} );
	}

	if ( step3.next ) {
		step3.next.addEventListener( 'click', finishText );
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
	renderFontChoices();
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
