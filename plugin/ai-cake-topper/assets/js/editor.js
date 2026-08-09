/**
 * The text editor — wizard step 3.
 *
 * D-033 moved text composition out of PHP and into here. The customer types
 * over the watermarked preview, drags the lines where they want them, and what
 * crosses the wire is a transparent PNG the size of the whole print file plus
 * the plain string they typed.
 *
 * Three rules this file exists to keep, all from D-033:
 *
 *   1. **Piece positions come from the server.** `SheetLayout` derives them and
 *      the editor consumes them. Deriving them here is how text ends up across
 *      a gutter while looking perfectly correct on screen.
 *   2. **The safe zone is a constraint, not a guide.** The customer cuts the
 *      sheet with scissors, so a name 2 mm inside the trim gets clipped. Text
 *      cannot be dragged out of the safe area and cannot be grown past it.
 *   3. **Text is drawn at print resolution, never scaled up from the preview.**
 *      The export canvas is the real print canvas; the screen is a viewport
 *      onto it.
 *
 * No framework and no build step, like the rest of the plugin.
 */
( function () {
	'use strict';

	/**
	 * How thick the outline is, as a fraction of the font size.
	 */
	var OUTLINE_RATIO = 0.14;

	/**
	 * Smallest and largest text, as a fraction of the piece's safe height.
	 */
	var MIN_RATIO = 0.04;
	var MAX_RATIO = 0.45;

	/**
	 * Build an editor over a canvas element.
	 *
	 * @param {Object} config Localised wizard config.
	 * @param {Object} hooks  Callbacks: onChange, onError, onBusy.
	 */
	window.AiCakeEditor = function ( config, hooks ) {
		hooks = hooks || {};

		var canvas  = null;
		var ctx     = null;
		var preview = null;
		var bound   = false;

		/*
		 * Set by the last exportLayer(): true when this device could not give us
		 * a canvas the size of the print sheet. Kept here rather than returned
		 * because save() needs it to tell a device failure apart from a refusal
		 * about the text itself — the two produce the same 422 and must not
		 * produce the same sentence (D-057).
		 */
		var exportFailed = false;

		/**
		 * The nonce to send, waiting for the session call if it has not landed.
		 *
		 * **This used to read `config.nonce` directly, and that was a live bug
		 * for every anonymous visitor — which is the wizard's whole audience.**
		 * The printed nonce is deliberately empty for them, because the page
		 * HTML is cacheable and a baked-in nonce would be stale (§7, D-025).
		 * Their only nonce is the one `/session` issues, it lives inside the
		 * generation engine, and nothing copied it here. So `/text-layer` and
		 * `/layout` went out with no nonce at all, WordPress treated them as
		 * user 0, and the customer was told „Sesija pasibaigė." the moment they
		 * tried to save their text.
		 *
		 * The host supplies it now, because there is exactly one correct answer
		 * to "which nonce applies" and the engine already knows it. A second
		 * module working it out independently is how this project served user 0
		 * twice before (D-025, D-026).
		 *
		 * @return {Promise<string>} The nonce, or '' if there is genuinely none.
		 */
		function nonce() {
			if ( 'function' === typeof hooks.nonce ) {
				return Promise.resolve( hooks.nonce() );
			}

			return Promise.resolve( config.nonce || '' );
		}

		var state = {
			layout: null,
			font: config.fonts && config.fonts.length ? config.fonts[ 0 ].handle : '',
			outline: true,
			outlineColour: '#000000',
			sameForAll: true,
			selected: 0,
			// Keyed by piece index, or 'all' when one text covers every piece.
			lines: {}
		};

		var dragging = null;

		/* ------------------------------------------------------------ fonts */

		/**
		 * Load the self-hosted fonts so canvas can measure and draw with them.
		 *
		 * Measurement before the font is ready silently uses a fallback, which
		 * means the fit calculation and the exported bitmap disagree — text
		 * that looked inside the safe zone lands outside it. So nothing draws
		 * until these resolve.
		 */
		function loadFonts() {
			if ( ! window.FontFace || ! document.fonts ) {
				return Promise.resolve();
			}

			var pending = ( config.fonts || [] ).map( function ( font ) {
				var face = new FontFace( font.handle, 'url(' + font.url + ')' );

				return face.load().then( function ( loaded ) {
					document.fonts.add( loaded );
				} ).catch( function () {
					// A font that will not load is not fatal; the select still
					// offers the others.
				} );
			} );

			return Promise.all( pending );
		}

		function fontStack( sizePx ) {
			return Math.round( sizePx ) + 'px "' + state.font + '", sans-serif';
		}

		/* ------------------------------------------------------------ lines */

		function key() {
			return state.sameForAll ? 'all' : String( state.selected );
		}

		function currentLines() {
			if ( ! state.lines[ key() ] ) {
				state.lines[ key() ] = [];
			}

			return state.lines[ key() ];
		}

		/**
		 * Lines to draw on a given piece.
		 *
		 * @param {number} index Piece index.
		 */
		function linesFor( index ) {
			return state.lines[ state.sameForAll ? 'all' : String( index ) ] || [];
		}

		function defaultSize( piece ) {
			return Math.round( piece.limit_h * 0.12 );
		}

		function addLine( text ) {
			var piece = state.layout.pieces[ state.selected ];
			var lines = currentLines();
			var size  = defaultSize( piece );

			lines.push( {
				text: text || '',
				colour: '#ffffff',
				size: size,
				/*
				 * Offset from the piece centre, in print pixels. The first line
				 * starts dead centre and each later one stacks below it.
				 *
				 * An earlier version offset even the first line upward, which
				 * put a single line above the middle of the piece — and, worse,
				 * left the piece centre outside the line's own hit box, so
				 * pressing the obvious place to grab it started no drag at all.
				 */
				dx: 0,
				dy: Math.round( lines.length * size * 1.3 )
			} );

			changed();

			return lines[ lines.length - 1 ];
		}

		function removeLine( i ) {
			currentLines().splice( i, 1 );
			changed();
		}

		/* ------------------------------------------------------- the palette */

		/**
		 * Every colour the exported bitmap will actually contain.
		 *
		 * This is what gets declared to the endpoint, and `LayerInspector`
		 * refuses any pixel that is not near one of them — so it has to be
		 * derived from what is drawn, never from what the UI offers. A swatch
		 * the customer picked and then changed away from must not be declared,
		 * or the palette grows for free and the check weakens.
		 */
		function palette() {
			var used = {};

			Object.keys( state.lines ).forEach( function ( k ) {
				state.lines[ k ].forEach( function ( line ) {
					if ( line.text.trim() !== '' ) {
						used[ line.colour.toLowerCase() ] = true;
					}
				} );
			} );

			if ( state.outline && Object.keys( used ).length > 0 ) {
				used[ state.outlineColour.toLowerCase() ] = true;
			}

			return Object.keys( used );
		}

		function paletteFull() {
			// Number(), because wp_localize_script stringifies everything and
			// a string here would make the comparison depend on coercion.
			return palette().length > ( Number( config.maxColours ) || 4 );
		}

		/* -------------------------------------------------------- measuring */

		/**
		 * The drawn box of a line, in print pixels.
		 *
		 * Measured with the real font at the real size, because that is the
		 * only number that agrees with what the export will contain. The
		 * outline is added on both sides — it is drawn centred on the glyph
		 * edge, so half of it sits outside the fill.
		 *
		 * @param {Object} line The line.
		 */
		function measure( line ) {
			ctx.save();
			ctx.font = fontStack( line.size );

			var metrics = ctx.measureText( line.text || ' ' );

			ctx.restore();

			var pad = state.outline ? line.size * OUTLINE_RATIO : 0;

			return {
				w: metrics.width + pad,
				// Canvas has no reliable cap-height metric across browsers, so
				// the em box is used. It over-estimates, which is the safe
				// direction for a clipping constraint.
				h: line.size + pad
			};
		}

		/**
		 * Push a line back inside its piece.
		 *
		 * The boundary is `limit_w` / `limit_h`, which the server sets — now
		 * the trim line itself (D-042). Never `w` directly: the editor and
		 * `tools/layer-check.php` both read the limit, and hardcoding the trim
		 * here would make moving it a two-file change that silently half-lands.
		 *
		 * Round pieces are the interesting case: how far left a line may sit
		 * depends on how far down it already is, because the boundary is a
		 * circle. Clamping each axis independently against the bounding square
		 * would let a corner poke outside the circle — which is exactly the
		 * part the cut removes.
		 *
		 * @param {Object} line  The line.
		 * @param {Object} piece Its piece.
		 */
		function constrain( line, piece ) {
			var limitW = piece.limit_w;
			var limitH = piece.limit_h;
			var box    = measure( line );
			var floor  = limitH * MIN_RATIO;

			if ( state.layout.round ) {
				var r = limitW / 2;

				// Shrink until the line can fit across the circle at all.
				while ( box.w / 2 > r && line.size > floor ) {
					line.size = Math.max( floor, line.size * 0.94 );
					box = measure( line );
				}

				var halfH = box.h / 2;
				var maxDy = Math.max( 0, Math.sqrt( Math.max( 0, r * r - ( box.w / 2 ) * ( box.w / 2 ) ) ) - halfH );

				line.dy = Math.max( -maxDy, Math.min( maxDy, line.dy ) );

				var reach = Math.sqrt( Math.max( 0, r * r - ( Math.abs( line.dy ) + halfH ) * ( Math.abs( line.dy ) + halfH ) ) );
				var maxDx = Math.max( 0, reach - box.w / 2 );

				line.dx = Math.max( -maxDx, Math.min( maxDx, line.dx ) );

				return;
			}

			while ( box.w > limitW && line.size > floor ) {
				line.size = Math.max( floor, line.size * 0.94 );
				box = measure( line );
			}

			var limitX = Math.max( 0, ( limitW - box.w ) / 2 );
			var limitY = Math.max( 0, ( limitH - box.h ) / 2 );

			line.dx = Math.max( -limitX, Math.min( limitX, line.dx ) );
			line.dy = Math.max( -limitY, Math.min( limitY, line.dy ) );
		}

		/**
		 * Stack a set of lines into a piece, shrinking whatever will not fit.
		 *
		 * Spacing and size cannot be solved separately on a round piece, which
		 * is the whole difficulty. A line placed away from the centre has less
		 * width available — the chord is shorter — so "move it up so it stops
		 * overlapping" and "make it fit across the circle" are the same
		 * problem. Separating them produced a suggestion where a wide line was
		 * pushed off centre for clearance and then clamped straight back onto
		 * the line below, because at that height it was too wide to be
		 * anywhere else.
		 *
		 * So this stacks the lines in reading order, centres the block, and
		 * shrinks any line whose measured width exceeds the chord available at
		 * the height it landed at — then does the whole thing again, because
		 * shrinking one line moves every line after it.
		 *
		 * **Only run when the customer asks for an arrangement** — a
		 * suggestion, not an edit. Re-flowing on every change would undo
		 * dragging, and being able to drag is the point (D-041).
		 *
		 * @param {Array}  lines The lines, mutated in place.
		 * @param {Object} piece Their piece.
		 */
		function arrange( lines, piece ) {
			if ( ! lines.length ) {
				return;
			}

			var radius = piece.limit_w / 2;
			var floor  = piece.limit_h * MIN_RATIO;

			for ( var pass = 0; pass < 40; pass++ ) {
				var boxes = lines.map( measure );
				var total = 0;

				boxes.forEach( function ( box ) {
					total += box.h;
				} );

				var y     = -total / 2;
				var fits  = true;

				for ( var i = 0; i < lines.length; i++ ) {
					var box = boxes[ i ];

					lines[ i ].dx = 0;
					lines[ i ].dy = y + ( box.h / 2 );
					y += box.h;

					var available;

					if ( state.layout.round ) {
						// The half-width available at this line's furthest
						// extent from the centre, not at its baseline — a
						// corner is what leaves the circle first.
						var reach = Math.abs( lines[ i ].dy ) + ( box.h / 2 );

						available = reach >= radius ? 0 : Math.sqrt( ( radius * radius ) - ( reach * reach ) );
					} else {
						available = piece.limit_w / 2;
					}

					if ( box.w / 2 > available && lines[ i ].size > floor ) {
						lines[ i ].size = Math.max( floor, lines[ i ].size * 0.94 );
						fits = false;
					}
				}

				if ( fits ) {
					return;
				}
			}
		}

		function constrainAll() {
			if ( ! state.layout ) {
				return;
			}

			state.layout.pieces.forEach( function ( piece, index ) {
				linesFor( index ).forEach( function ( line ) {
					constrain( line, piece );
				} );
			} );
		}

		/* -------------------------------------------------------- rendering */

		function scale() {
			return canvas.clientWidth / state.layout.canvas.w;
		}

		/**
		 * Draw one line onto a context, in that context's own coordinates.
		 *
		 * @param {CanvasRenderingContext2D} target Where to draw.
		 * @param {Object}                   line   The line.
		 * @param {Object}                   piece  Its piece.
		 * @param {number}                   k      Scale from print px.
		 */
		function drawLine( target, line, piece, k ) {
			if ( line.text.trim() === '' ) {
				return;
			}

			target.save();
			target.font = fontStack( line.size * k );
			target.textAlign = 'center';
			target.textBaseline = 'middle';

			var x = ( piece.cx + line.dx ) * k;
			var y = ( piece.cy + line.dy ) * k;

			if ( state.outline ) {
				target.lineWidth = line.size * k * OUTLINE_RATIO;
				target.strokeStyle = state.outlineColour;
				// Round joins, or a sharp corner on a glyph throws a spike well
				// outside the measured box and past the safe zone.
				target.lineJoin = 'round';
				target.miterLimit = 2;
				target.strokeText( line.text, x, y );
			}

			target.fillStyle = line.colour;
			target.fillText( line.text, x, y );

			target.restore();
		}

		function render() {
			if ( ! canvas || ! state.layout ) {
				return;
			}

			var k     = scale();
			var ratio = window.devicePixelRatio || 1;
			var w     = Math.round( state.layout.canvas.w * k );
			var h     = Math.round( state.layout.canvas.h * k );

			if ( canvas.width !== Math.round( w * ratio ) ) {
				canvas.width  = Math.round( w * ratio );
				canvas.height = Math.round( h * ratio );
			}

			canvas.style.height = h + 'px';

			ctx.setTransform( ratio, 0, 0, ratio, 0, 0 );
			ctx.clearRect( 0, 0, w, h );

			state.layout.pieces.forEach( function ( piece, index ) {
				var px = piece.cx * k;
				var py = piece.cy * k;
				var pw = piece.w * k;
				var ph = piece.h * k;

				// The artwork, drawn per piece — a cupcake sheet is one image
				// repeated, and the customer needs to see it that way to place
				// twelve different names.
				if ( preview && preview.complete && preview.naturalWidth > 0 ) {
					ctx.save();
					ctx.beginPath();

					if ( state.layout.round ) {
						ctx.arc( px, py, pw / 2, 0, Math.PI * 2 );
					} else {
						ctx.rect( px - pw / 2, py - ph / 2, pw, ph );
					}

					ctx.clip();
					ctx.drawImage( preview, px - pw / 2, py - ph / 2, pw, ph );
					ctx.restore();
				}

				// The cut line. Drawn server-side on the real print file
				// (D-033), but it has to appear here too — a line on the
				// printed sheet that was not in the proof reads as a fault.
				ctx.save();
				ctx.strokeStyle = '#000';
				ctx.lineWidth = 1;
				ctx.beginPath();

				if ( state.layout.round ) {
					ctx.arc( px, py, pw / 2, 0, Math.PI * 2 );
				} else {
					ctx.rect( px - pw / 2, py - ph / 2, pw, ph );
				}

				ctx.stroke();
				ctx.restore();

				/*
				 * No second dashed ring. The cut line *is* the limit now
				 * (D-042), so drawing a separate boundary would show a rule
				 * that no longer exists — and two circles a few millimetres
				 * apart is exactly the sort of thing a customer cuts along by
				 * mistake.
				 */

				if ( ! state.sameForAll && index === state.selected ) {
					ctx.save();
					ctx.strokeStyle = '#0073aa';
					ctx.lineWidth = 2;
					ctx.beginPath();
					ctx.arc( px, py, pw / 2 + 3, 0, Math.PI * 2 );
					ctx.stroke();
					ctx.restore();
				}

				linesFor( index ).forEach( function ( line ) {
					drawLine( ctx, line, piece, k );
				} );
			} );
		}

		/* ---------------------------------------------------------- dragging */

		function pointerPrint( event ) {
			var rect = canvas.getBoundingClientRect();
			var k    = scale();

			return {
				x: ( event.clientX - rect.left ) / k,
				y: ( event.clientY - rect.top ) / k
			};
		}

		/**
		 * Which line, if any, is under a point — and which piece it belongs to.
		 *
		 * @param {Object} point Print-pixel coordinates.
		 */
		function hit( point ) {
			var found = null;

			state.layout.pieces.forEach( function ( piece, index ) {
				linesFor( index ).forEach( function ( line, i ) {
					var box = measure( line );
					var cx  = piece.cx + line.dx;
					var cy  = piece.cy + line.dy;

					if (
						Math.abs( point.x - cx ) <= box.w / 2 &&
						Math.abs( point.y - cy ) <= box.h / 2
					) {
						found = { line: line, index: i, piece: piece, pieceIndex: index };
					}
				} );
			} );

			return found;
		}

		function onDown( event ) {
			if ( ! state.layout ) {
				return;
			}

			var point = pointerPrint( event );
			var found = hit( point );

			if ( ! found ) {
				// Tapping a piece selects it, which is how per-piece text is
				// reached on a touch screen.
				selectPieceAt( point );

				return;
			}

			if ( ! state.sameForAll ) {
				state.selected = found.pieceIndex;
			}

			dragging = {
				line: found.line,
				piece: found.piece,
				fromX: point.x - found.line.dx,
				fromY: point.y - found.line.dy
			};

			canvas.setPointerCapture( event.pointerId );
			event.preventDefault();
		}

		function onMove( event ) {
			if ( ! dragging ) {
				return;
			}

			var point = pointerPrint( event );

			dragging.line.dx = point.x - dragging.fromX;
			dragging.line.dy = point.y - dragging.fromY;

			constrain( dragging.line, dragging.piece );
			render();

			event.preventDefault();
		}

		function onUp( event ) {
			if ( ! dragging ) {
				return;
			}

			dragging = null;

			if ( canvas.hasPointerCapture( event.pointerId ) ) {
				canvas.releasePointerCapture( event.pointerId );
			}

			changed();
		}

		function selectPieceAt( point ) {
			if ( state.sameForAll || state.layout.pieces.length < 2 ) {
				return;
			}

			var best = null;
			var bestDistance = Infinity;

			state.layout.pieces.forEach( function ( piece, index ) {
				var dx = point.x - piece.cx;
				var dy = point.y - piece.cy;
				var d  = ( dx * dx ) + ( dy * dy );

				if ( d < bestDistance && d <= ( piece.w / 2 ) * ( piece.w / 2 ) ) {
					bestDistance = d;
					best = index;
				}
			} );

			if ( best !== null && best !== state.selected ) {
				state.selected = best;
				changed();
			}
		}

		/* ------------------------------------------------------------ export */

		/**
		 * The bitmap the endpoint receives.
		 *
		 * Drawn on its own canvas at the true print size, containing text and
		 * nothing else — no preview, no cut line, no safe-zone guide. The
		 * artwork is already on the server and the cut line is drawn there
		 * (D-033); including either here would double them on the print.
		 *
		 * Because this canvas never receives the preview image, it is never
		 * tainted, so `toDataURL` works regardless of where the preview came
		 * from.
		 */
		function exportLayer() {
			var out = document.createElement( 'canvas' );

			out.width  = state.layout.canvas.w;
			out.height = state.layout.canvas.h;

			/*
			 * Whether the device actually gave us the canvas we asked for.
			 *
			 * It may not have. A cupcake sheet is 2481 x 3331 — 8.3 megapixels —
			 * and Safari on iOS does NOT throw when a canvas exceeds its area
			 * budget: it hands back one that reads as transparent, and
			 * toDataURL() then produces a perfectly valid BLANK png. The shop's
			 * own statistics put iOS at 16.1% against Android's 11.1%, so the
			 * one engine this was never tested on is the one most phone
			 * customers use (D-057).
			 *
			 * Before this check, that failure surfaced as „Užrašas tuščias." —
			 * the server refusing a zero-ink layer, correctly, but telling the
			 * customer their text was empty while it sat on the screen in front
			 * of them. Nothing printed wrong; the sale was simply lost, quietly,
			 * and nothing in the logs said why.
			 */
			exportFailed = ! canvasHolds( out );

			var target = out.getContext( '2d' );

			state.layout.pieces.forEach( function ( piece, index ) {
				linesFor( index ).forEach( function ( line ) {
					drawLine( target, line, piece, 1 );
				} );
			} );

			return out.toDataURL( 'image/png' );
		}

		/**
		 * Does this canvas keep what is written to it?
		 *
		 * Written and read back rather than inferred, because every cheaper
		 * signal lies on the device that matters: the width and height
		 * properties report what was asked for, getContext returns a context,
		 * and nothing throws. Only the pixels tell the truth.
		 *
		 * Probed in two far-apart corners — a canvas can be allocated and still
		 * be short of the bottom of a tall sheet, and the bottom row of cupcakes
		 * is exactly where a customer's last name would go.
		 *
		 * The probe is erased before the caller draws, so it can never reach the
		 * exported bitmap and be mistaken for ink by `LayerInspector`.
		 *
		 * @param {HTMLCanvasElement} canvasEl The canvas about to be drawn on.
		 * @return {boolean} True when the pixels came back.
		 */
		function canvasHolds( canvasEl ) {
			var w = canvasEl.width;
			var h = canvasEl.height;

			if ( w < 1 || h < 1 ) {
				return false;
			}

			try {
				var probe = canvasEl.getContext( '2d' );

				if ( ! probe ) {
					return false;
				}

				var spots = [ [ 0, 0 ], [ w - 4, h - 4 ] ];
				var held  = spots.every( function ( at ) {
					probe.fillStyle = '#ff0000';
					probe.fillRect( at[ 0 ], at[ 1 ], 4, 4 );

					var px = probe.getImageData( at[ 0 ] + 1, at[ 1 ] + 1, 1, 1 ).data;

					return px[ 0 ] > 200 && px[ 3 ] > 200;
				} );

				spots.forEach( function ( at ) {
					probe.clearRect( at[ 0 ], at[ 1 ], 4, 4 );
				} );

				return held;
			} catch ( e ) {
				/*
				 * getImageData throws on a tainted canvas. This one never
				 * receives the preview image so it cannot be tainted — but a
				 * throw here still means we cannot verify, and an unverifiable
				 * canvas is treated exactly like a failed one.
				 */
				return false;
			}
		}

		/**
		 * Everything typed, in reading order.
		 *
		 * Sent alongside the bitmap so moderation layers 0 and 1 can still read
		 * it — a bitmap hides every word in it (D-033).
		 */
		function plainText() {
			var parts = [];

			state.layout.pieces.forEach( function ( piece, index ) {
				linesFor( index ).forEach( function ( line ) {
					if ( line.text.trim() !== '' ) {
						parts.push( line.text.trim() );
					}
				} );

				// With one text on every piece there is no point repeating it
				// for all twenty-four.
				if ( state.sameForAll ) {
					parts = parts.slice( 0, linesFor( 0 ).length );
				}
			} );

			return parts.join( ' ' );
		}

		function hasText() {
			return plainText() !== '';
		}

		/* -------------------------------------------------------------- api */

		function changed() {
			constrainAll();
			render();

			if ( hooks.onChange ) {
				hooks.onChange( state );
			}
		}

		return {
			/**
			 * Attach to a canvas and a format.
			 *
			 * @param {HTMLCanvasElement} element    The canvas.
			 * @param {Object}            layout     Server-derived geometry.
			 * @param {string}            previewUrl The watermarked preview.
			 */
			/**
			 * Point the editor at a design.
			 *
			 * Callable more than once, because generating a second picture has
			 * to replace the first one under the text. The listeners are bound
			 * once and only once: they close over `canvas`, so re-binding would
			 * run each handler twice per pointer event and drag everything at
			 * double speed.
			 */
			mount: function ( element, layout, previewUrl ) {
				canvas = element;
				ctx = canvas.getContext( '2d' );

				state.layout = layout;
				state.selected = 0;
				state.lines = {};

				if ( ! bound ) {
					bound = true;

					canvas.addEventListener( 'pointerdown', onDown );
					canvas.addEventListener( 'pointermove', onMove );
					canvas.addEventListener( 'pointerup', onUp );
					canvas.addEventListener( 'pointercancel', onUp );

					window.addEventListener( 'resize', render );
				}

				if ( previewUrl ) {
					preview = new window.Image();
					preview.onload = render;
					preview.src = previewUrl;
				}

				return loadFonts().then( function () {
					render();
				} );
			},

			state: function () {
				return state;
			},

			/**
			 * The proof: what the customer is buying, as a picture.
			 *
			 * The visible canvas already *is* the composite — artwork clipped
			 * per piece, cut lines, text where they dragged it — so the proof
			 * is a capture of it rather than a second rendering. Compositing it
			 * again server-side would mean two renderers that have to agree,
			 * which is the browser↔GD parity problem D-033 deleted; the print
			 * path composites the stored layer instead, and that path is
			 * checked against the real file by `order-check.php`.
			 *
			 * Returns '' before the editor is mounted, so a caller cannot
			 * silently show a blank proof.
			 */
			snapshot: function () {
				if ( ! canvas || ! state.layout ) {
					return '';
				}

				render();

				return canvas.toDataURL( 'image/png' );
			},

			lines: currentLines,
			addLine: addLine,
			removeLine: removeLine,
			palette: palette,
			paletteFull: paletteFull,
			hasText: hasText,
			plainText: plainText,
			render: render,
			changed: changed,

			setFont: function ( handle ) {
				state.font = handle;
				changed();
			},

			setOutline: function ( on, colour ) {
				state.outline = !! on;

				if ( colour ) {
					state.outlineColour = colour;
				}

				changed();
			},

			/**
			 * Switch between one text for every piece and one per piece.
			 *
			 * Carries the text across in both directions. Without this,
			 * unchecking the box silently emptied every piece — you type a
			 * name, decide you want the cupcakes to differ, and lose what you
			 * had. Nobody wants "different names" to start from nothing; they
			 * want to start from the name they just typed.
			 *
			 * @param {boolean} on Whether one text covers every piece.
			 */
			setSameForAll: function ( on ) {
				var was = state.sameForAll;

				on = !! on;

				if ( was && ! on ) {
					// Seed every piece with a copy of the shared text. Copies,
					// not references, or editing one edits all of them and the
					// checkbox does nothing.
					state.layout.pieces.forEach( function ( piece, index ) {
						state.lines[ String( index ) ] = ( state.lines.all || [] ).map( function ( line ) {
							return {
								text: line.text,
								colour: line.colour,
								size: line.size,
								dx: line.dx,
								dy: line.dy
							};
						} );
					} );
				}

				if ( ! was && on ) {
					// Collapsing the other way takes whichever piece was being
					// looked at, which is the one whose text is on screen.
					state.lines.all = ( state.lines[ String( state.selected ) ] || [] ).slice();
				}

				state.sameForAll = on;
				state.selected = 0;
				changed();
			},

			selectPiece: function ( index ) {
				state.selected = index;
				changed();
			},

			/**
			 * Load a model's proposal onto the canvas (D-041).
			 *
			 * The model returns ratios, never pixels, and they are multiplied
			 * out here against the piece the server derived. Sizes are hints:
			 * `constrain()` runs immediately afterwards and shrinks anything
			 * that does not actually fit, measured with the real font. The
			 * model has no idea how wide "Ąžuolas" is in DejaVu Serif Bold and
			 * is not asked to.
			 *
			 * It replaces the current lines rather than merging, because the
			 * proposal is a whole design. Everything stays draggable and
			 * editable afterwards — that is the point of D-041.
			 *
			 * @param {Object} suggestion Clamped server response.
			 */
			applySuggestion: function ( suggestion ) {
				if ( ! suggestion || ! suggestion.lines || ! suggestion.lines.length ) {
					return false;
				}

				var piece = state.layout.pieces[ state.selected ];
				var lines = [];

				suggestion.lines.forEach( function ( line ) {
					lines.push( {
						text: String( line.text || '' ),
						colour: line.colour || '#ffffff',
						size: Math.round( piece.limit_h * Number( line.size_ratio || 0.14 ) ),
						dx: 0,
						dy: Math.round( piece.limit_h * Number( line.dy_ratio || 0 ) )
					} );
				} );

				state.lines[ key() ] = lines;

				// The font has to be in place before anything is measured, or
				// the spacing is worked out against whatever was selected
				// before and the lines still collide.
				if ( suggestion.font ) {
					state.font = suggestion.font;
				}

				arrange( lines, piece );

				if ( typeof suggestion.outline !== 'undefined' ) {
					state.outline = !! suggestion.outline;
				}

				if ( suggestion.outline_colour ) {
					state.outlineColour = suggestion.outline_colour;
				}

				changed();

				return true;
			},

			/**
			 * Ask the server for a layout.
			 *
			 * Failures are swallowed on purpose: the button is optional and the
			 * editor works without it, so a text API being down must not stand
			 * between a customer and their cake (D-041).
			 *
			 * @param {string} designId The design's public id.
			 */
			suggest: function ( designId ) {
				return nonce().then( function ( token ) {
					var headers = { 'Content-Type': 'application/json' };

					if ( token ) {
						headers['X-WP-Nonce'] = token;
					}

					return window.fetch( config.root + 'layout', {
						method: 'POST',
						credentials: 'same-origin',
						headers: headers,
						body: JSON.stringify( { design: designId, text: plainText() } )
					} );
				} ).then( function ( response ) {
					return response.json().then( function ( body ) {
						if ( ! response.ok ) {
							throw new Error( body && body.message ? body.message : '' );
						}

						return body;
					} );
				} );
			},

			/**
			 * Send the layer to the server.
			 *
			 * Everything the endpoint checks is checked again there — this is
			 * the convenient path, not the trusted one.
			 *
			 * @param {string} designId The design's public id.
			 */
			save: function ( designId ) {
				if ( hooks.onBusy ) {
					hooks.onBusy( true );
				}

				/*
				 * Exported before the request is built, because doing so sets
				 * `exportFailed` and the catch below needs it.
				 *
				 * A failed export is still posted rather than abandoned. It
				 * costs almost nothing — a blank 8.3 megapixel PNG compresses
				 * to a couple of kilobytes — and it is the only way the shop
				 * ever finds out this is happening: the server logs the refusal
				 * with the user agent, so an unanswerable "the wizard is broken"
				 * complaint becomes a device name in wc-logs (D-057).
				 */
				var layer = exportLayer();

				return nonce().then( function ( token ) {
					var headers = { 'Content-Type': 'application/json' };

					if ( token ) {
						headers['X-WP-Nonce'] = token;
					}

					return window.fetch( config.root + 'text-layer', {
						method: 'POST',
						credentials: 'same-origin',
						headers: headers,
						body: JSON.stringify( {
							design: designId,
							text: plainText(),
							colours: palette(),
							layer: layer
						} )
					} );
				} ).then( function ( response ) {
					return response.json().then( function ( body ) {
						if ( ! response.ok ) {
							throw new Error( body && body.message ? body.message : config.i18n.textFailed );
						}

						return body;
					} );
				} ).catch( function ( error ) {
					if ( hooks.onError ) {
						/*
						 * The device's failure outranks whatever the server
						 * said. Both arrive as the same 422, but the server can
						 * only see a bitmap with no ink in it and will honestly
						 * report „Užrašas tuščias." — which is true of the
						 * bitmap and false about the customer, who is looking at
						 * their text on the screen. Telling them to retype it
						 * sends them round a loop that cannot end.
						 */
						hooks.onError(
							exportFailed
								? ( config.i18n.canvasTooBig || config.i18n.textFailed )
								: ( error.message || config.i18n.textFailed )
						);
					}

					throw error;
				} ).finally( function () {
					if ( hooks.onBusy ) {
						hooks.onBusy( false );
					}
				} );
			}
		};
	};
}() );
