/**
 * The generation engine: session, nonce, generate, poll.
 *
 * Extracted from `generator.js` so the product page and the wizard share one
 * implementation of PLAN.md §6.5 rather than two. The polling contract is the
 * wrong thing to have two copies of — it encodes the back-off schedule, the
 * give-up point, and D-025's nonce rules, and a second copy would drift from
 * the first silently and only for some visitors.
 *
 * No DOM in here. Callers pass hooks and build their own payload, which is why
 * the wizard can add a format to the request without this file knowing what a
 * format is.
 *
 * Two sources for the nonce, and which one applies is decided server-side
 * (D-025). Anonymous visitors get one from the uncached session endpoint,
 * because their page is cached and a printed nonce would be stale — §7, the
 * single most likely way to ship something that works in testing and 403s for
 * most real customers. Logged-in users get one printed into the page, because
 * their page is never cached and the endpoint, which sends no nonce itself,
 * can only mint them one belonging to user 0.
 */
( function () {
	'use strict';

	/**
	 * @param {Object} config Localised configuration: root, nonce, i18n.
	 * @param {Object} hooks  onBusy, onStatus, onError, onSuccess, onSession.
	 */
	window.AiCakeGeneration = function ( config, hooks ) {
		hooks = hooks || {};

		var session = null;
		var polling = null;
		var messageTimer = null;
		var busy = false;
		var history = [];

		var messages = ( config.i18n && config.i18n.progress ) || [];

		function call( name, value ) {
			if ( 'function' === typeof hooks[ name ] ) {
				hooks[ name ]( value );
			}
		}

		function setBusy( state ) {
			busy = state;
			call( 'onBusy', state );
		}

		/**
		 * Rotating status text. §15: it takes 5–15 s and silence feels broken.
		 */
		function startMessages() {
			var index = 0;

			if ( messages.length ) {
				call( 'onStatus', messages[ 0 ] );
			}

			messageTimer = window.setInterval( function () {
				if ( ! messages.length ) {
					return;
				}

				index = ( index + 1 ) % messages.length;
				call( 'onStatus', messages[ index ] );
			}, 3500 );
		}

		function stopMessages() {
			window.clearInterval( messageTimer );
			messageTimer = null;
		}

		/**
		 * The printed nonce wins whenever there is one. It is the only nonce
		 * that matches a logged-in user's cookie, and it is present from the
		 * first request — including the session call itself, which is what lets
		 * that call authenticate and report the right allowance for a logged-in
		 * customer instead of the anonymous one (§11.3).
		 */
		function nonce() {
			return config.nonce || ( session && session.nonce ) || '';
		}

		function request( path, options ) {
			options = options || {};

			var headers = { 'Content-Type': 'application/json' };
			var token = nonce();

			if ( token ) {
				headers['X-WP-Nonce'] = token;
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

		function loadSession() {
			return request( 'session' ).then( function ( result ) {
				// A printed nonce that no longer verifies, while the login
				// cookie still does. Nothing here can mint a replacement —
				// only a page load can — so stop pretending a retry helps.
				if ( 403 === result.status && config.nonce ) {
					call( 'onError', config.i18n.reload );
					return session;
				}

				if ( ! result.ok ) {
					return session;
				}

				// Logged out in another tab. The printed nonce is now the
				// wrong one and the endpoint's anonymous nonce is the right
				// one.
				if ( config.nonce && false === result.data.logged_in ) {
					config.nonce = '';
				}

				session = result.data;
				call( 'onSession', session );

				return session;
			} );
		}

		function generate( payload ) {
			if ( busy ) {
				return;
			}

			call( 'onError', '' );
			setBusy( true );
			startMessages();

			request( 'generate', { method: 'POST', body: payload } ).then( function ( result ) {
				if ( ! result.ok ) {
					stopMessages();
					setBusy( false );

					// 403 means the nonce went stale while the page sat open.
					// Fetch a fresh one and let the customer try again rather
					// than making them reload — unless the dead nonce is the
					// printed one, which reloading is the only cure for.
					if ( 403 === result.status ) {
						loadSession().then( function () {
							call( 'onError', config.nonce ? config.i18n.reload : config.i18n.expired );
						} );

						return;
					}

					call( 'onError', result.data.message || config.i18n.failed );

					return;
				}

				poll( result.data.job_id, result.data.poll_after_ms || 1500 );
			} ).catch( function () {
				stopMessages();
				setBusy( false );
				call( 'onError', config.i18n.failed );
			} );
		}

		/**
		 * §6.5: poll every 1.5 s, back off to 3 s after 15 s, give up at 90 s.
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

					if ( 'number' === typeof result.data.queue_position && result.data.queue_position > 1 ) {
						stopMessages();
						call( 'onStatus', config.i18n.queued.replace( '%d', result.data.queue_position ) );
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
			call( 'onError', message );
			loadSession();
		}

		function succeed( data ) {
			stopMessages();
			setBusy( false );
			call( 'onError', '' );

			var design = { id: data.public_id, url: data.preview_url };

			if ( ! history.some( function ( item ) { return item.id === design.id; } ) ) {
				history.push( design );
			}

			call( 'onSuccess', design );

			loadSession();
		}

		return {
			loadSession: loadSession,
			generate: generate,
			history: function () { return history; },
			session: function () { return session; },
			isBusy: function () { return busy; }
		};
	};
}() );
