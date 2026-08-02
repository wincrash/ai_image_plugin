/**
 * The generator.
 *
 * Implements the polling contract from PLAN.md §6.5, and fetches its nonce
 * from the uncached session endpoint rather than reading it out of the page —
 * see §7, which is the single most likely way to ship something that works in
 * testing and 403s for most real customers.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-aicake]' );

	if ( ! root || 'undefined' === typeof window.aicakeConfig ) {
		return;
	}

	var config = window.aicakeConfig;
	var spec = JSON.parse( root.dataset.spec || '{}' );

	var el = {
		prompt: root.querySelector( '.aicake__prompt' ),
		counter: root.querySelector( '[data-aicake-counter]' ),
		generate: root.querySelector( '[data-aicake-generate]' ),
		generateLabel: root.querySelector( '[data-aicake-generate-label]' ),
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

	var session = null;
	var polling = null;
	var history = [];
	var busy = false;

	/* ---------------------------------------------------------------- utils */

	function show( node, visible ) {
		if ( node ) { node.hidden = ! visible; }
	}

	function setError( message ) {
		if ( ! el.error ) { return; }
		el.error.textContent = message || '';
		show( el.error, !! message );
	}

	function setBusy( state ) {
		busy = state;
		if ( el.generate ) { el.generate.disabled = state; }
		show( el.status, state );
		if ( ! state && el.statusText ) { el.statusText.textContent = ''; }
	}

	/**
	 * Rotating status text. §15: it takes 5-15s and silence feels broken.
	 */
	var messages = config.i18n.progress || [];
	var messageTimer = null;

	function startMessages() {
		var index = 0;

		if ( el.statusText && messages.length ) {
			el.statusText.textContent = messages[ 0 ];
		}

		messageTimer = window.setInterval( function () {
			index = ( index + 1 ) % messages.length;
			if ( el.statusText && messages.length ) {
				el.statusText.textContent = messages[ index ];
			}
		}, 3500 );
	}

	function stopMessages() {
		window.clearInterval( messageTimer );
		messageTimer = null;
	}

	function request( path, options ) {
		options = options || {};

		var headers = { 'Content-Type': 'application/json' };

		if ( session && session.nonce ) {
			headers['X-WP-Nonce'] = session.nonce;
		}

		return window.fetch( config.root + path, {
			method: options.method || 'GET',
			credentials: 'same-origin',
			headers: headers,
			body: options.body ? JSON.stringify( options.body ) : undefined
		} ).then( function ( response ) {
			return response.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				return { ok: response.ok, status: response.status, data: data };
			} );
		} );
	}

	/* -------------------------------------------------------------- session */

	/**
	 * The nonce is never printed into the page, because page caches serve
	 * stale ones and every logged-out generation would 403 (§7).
	 */
	function loadSession() {
		return request( 'session' ).then( function ( result ) {
			if ( result.ok ) {
				session = result.data;
				updateRemaining();
			}

			return session;
		} );
	}

	function updateRemaining() {
		if ( ! el.remaining || ! session ) { return; }

		var left = session.remaining_generations;

		if ( 'number' !== typeof left ) {
			el.remaining.textContent = '';
			return;
		}

		el.remaining.textContent = left > 0
			? config.i18n.remaining.replace( '%d', left )
			: config.i18n.noneLeft;

		if ( el.generate ) {
			el.generate.disabled = left <= 0 || busy;
		}
	}

	/* ------------------------------------------------------------ generating */

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
		if ( busy ) { return; }

		var prompt = el.prompt ? el.prompt.value.trim() : '';

		if ( ! prompt ) {
			setError( config.i18n.needPrompt );
			if ( el.prompt ) { el.prompt.focus(); }
			return;
		}

		setError( '' );
		setBusy( true );
		startMessages();

		var payload = {
			prompt: prompt,
			aspect: spec.aspect || '1:1',
			product_id: config.productId,
			variation_id: 0
		};

		var text = textPayload();
		if ( text ) { payload.text = text; }

		request( 'generate', { method: 'POST', body: payload } ).then( function ( result ) {
			if ( ! result.ok ) {
				stopMessages();
				setBusy( false );

				// 403 means the nonce went stale while the page sat open.
				// Fetch a fresh one and let the customer try again rather
				// than making them reload.
				if ( 403 === result.status ) {
					loadSession();
					setError( config.i18n.expired );
					return;
				}

				setError( result.data.message || config.i18n.failed );
				return;
			}

			poll( result.data.job_id, result.data.poll_after_ms || 1500 );
		} ).catch( function () {
			stopMessages();
			setBusy( false );
			setError( config.i18n.failed );
		} );
	}

	/**
	 * §6.5: poll every 1.5s, back off to 3s after 15s, give up at 90s.
	 */
	function poll( jobId, interval ) {
		var started = Date.now();

		window.clearTimeout( polling );

		function tick() {
			request( 'job/' + encodeURIComponent( jobId ) ).then( function ( result ) {
				var elapsed = Date.now() - started;

				if ( ! result.ok ) {
					finish( config.i18n.failed );
					return;
				}

				var status = result.data.status;

				if ( 'done' === status ) {
					succeed( result.data );
					return;
				}

				if ( 'failed' === status || 'rejected' === status ) {
					finish( result.data.error || config.i18n.failed );
					return;
				}

				if ( elapsed > 90000 ) {
					finish( config.i18n.timeout );
					return;
				}

				if ( 'number' === typeof result.data.queue_position && result.data.queue_position > 1 && el.statusText ) {
					stopMessages();
					el.statusText.textContent = config.i18n.queued.replace( '%d', result.data.queue_position );
				}

				polling = window.setTimeout( tick, elapsed > 15000 ? 3000 : interval );
			} ).catch( function () {
				finish( config.i18n.failed );
			} );
		}

		polling = window.setTimeout( tick, interval );
	}

	function finish( message ) {
		stopMessages();
		setBusy( false );
		setError( message );
		loadSession();
	}

	function succeed( data ) {
		stopMessages();
		setBusy( false );
		setError( '' );

		select( {
			id: data.public_id,
			url: data.preview_url
		} );

		if ( ! history.some( function ( item ) { return item.id === data.public_id; } ) ) {
			history.push( { id: data.public_id, url: data.preview_url } );
			renderHistory();
		}

		loadSession();
	}

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
	loadSession();
}() );
