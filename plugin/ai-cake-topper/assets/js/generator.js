/**
 * The generator on a product page.
 *
 * Everything about talking to the server lives in `generation.js` — session,
 * nonce rules, the §6.5 polling contract — because the wizard needs exactly the
 * same behaviour and two copies of a back-off schedule drift apart silently.
 * What is left here is the binding between that engine and this page's markup.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-aicake]' );

	if ( ! root || 'undefined' === typeof window.aicakeConfig || 'undefined' === typeof window.AiCakeGeneration ) {
		return;
	}

	var config = window.aicakeConfig;
	var spec = JSON.parse( root.dataset.spec || '{}' );

	var el = {
		prompt: root.querySelector( '.aicake__prompt' ),
		counter: root.querySelector( '[data-aicake-counter]' ),
		generate: root.querySelector( '[data-aicake-generate]' ),
		remaining: root.querySelector( '[data-aicake-remaining]' ),
		status: root.querySelector( '[data-aicake-status]' ),
		statusText: root.querySelector( '[data-aicake-status-text]' ),
		error: root.querySelector( '[data-aicake-error]' ),
		stage: root.querySelector( '[data-aicake-stage]' ),
		preview: root.querySelector( '[data-aicake-preview]' ),
		history: root.querySelector( '[data-aicake-history]' ),
		strip: root.querySelector( '[data-aicake-history-strip]' ),
		design: root.querySelector( '[data-aicake-design]' ),
		text: root.querySelector( '#aicake-text' ),
		font: root.querySelector( '#aicake-font' ),
		placement: root.querySelector( '#aicake-placement' ),
		colour: root.querySelector( '#aicake-colour' )
	};

	function show( node, visible ) {
		if ( node ) { node.hidden = ! visible; }
	}

	var engine = window.AiCakeGeneration( config, {
		onBusy: function ( state ) {
			if ( el.generate ) { el.generate.disabled = state; }
			show( el.status, state );
			if ( ! state && el.statusText ) { el.statusText.textContent = ''; }
		},
		onStatus: function ( text ) {
			if ( el.statusText ) { el.statusText.textContent = text; }
		},
		onError: function ( message ) {
			if ( ! el.error ) { return; }
			el.error.textContent = message || '';
			show( el.error, !! message );
		},
		onSession: function ( session ) {
			if ( ! el.remaining ) { return; }

			var left = session.remaining_generations;

			if ( 'number' !== typeof left ) {
				el.remaining.textContent = '';
				return;
			}

			el.remaining.textContent = left > 0
				? config.i18n.remaining.replace( '%d', left )
				: config.i18n.noneLeft;

			if ( el.generate ) {
				el.generate.disabled = left <= 0 || engine.isBusy();
			}
		},
		onSuccess: function ( design ) {
			select( design );
			renderHistory();
		}
	} );

	/* --------------------------------------------------------------- choosing */

	function select( design ) {
		if ( el.preview ) { el.preview.src = design.url; }
		if ( el.design ) { el.design.value = design.id; }

		show( el.stage, true );
		renderHistory();

		// Tell the add-to-cart form a design now exists.
		document.dispatchEvent( new CustomEvent( 'aicake:selected', { detail: design } ) );
	}

	function renderHistory() {
		if ( ! el.strip ) { return; }

		var history = engine.history();

		show( el.history, history.length > 1 );
		el.strip.textContent = '';

		var chosen = el.design ? el.design.value : '';

		history.forEach( function ( item ) {
			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'aicake__thumb' + ( item.id === chosen ? ' is-selected' : '' );
			button.title = config.i18n.reselect;

			var img = document.createElement( 'img' );
			img.src = item.url;
			img.alt = '';
			button.appendChild( img );

			button.addEventListener( 'click', function () {
				select( item );
			} );

			el.strip.appendChild( button );
		} );
	}

	/* ---------------------------------------------------------------- wiring */

	function textPayload() {
		if ( ! el.text || ! el.text.value.trim() ) { return null; }

		return {
			text: el.text.value.trim(),
			font: el.font ? el.font.value : '',
			colour: el.colour ? el.colour.value : '#ffffff',
			placement: el.placement ? el.placement.value : 'bottom'
		};
	}

	function generate() {
		var prompt = el.prompt ? el.prompt.value.trim() : '';

		if ( ! prompt ) {
			if ( el.error ) {
				el.error.textContent = config.i18n.needPrompt;
				show( el.error, true );
			}

			if ( el.prompt ) { el.prompt.focus(); }

			return;
		}

		var payload = {
			prompt: prompt,
			aspect: spec.aspect || '1:1',
			product_id: config.productId,
			variation_id: 0
		};

		var text = textPayload();
		if ( text ) { payload.text = text; }

		engine.generate( payload );
	}

	if ( el.prompt && el.counter ) {
		var count = function () {
			var length = el.prompt.value.length;
			el.counter.textContent = length + ' / 500';
			el.counter.classList.toggle( 'is-near', length > 450 );
		};

		el.prompt.addEventListener( 'input', count );
		count();
	}

	root.querySelectorAll( '[data-aicake-chip]' ).forEach( function ( chip ) {
		chip.addEventListener( 'click', function () {
			if ( ! el.prompt ) { return; }
			el.prompt.value = chip.textContent.trim();
			el.prompt.dispatchEvent( new Event( 'input' ) );
			el.prompt.focus();
		} );
	} );

	if ( el.generate ) {
		el.generate.addEventListener( 'click', generate );
	}

	// The session call also sets the cookie, so the throttle identity exists
	// before the first generation rather than being created by it (§7).
	engine.loadSession();
}() );
