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
			return Math.round( piece.safe_h * 0.12 );
		}

		function addLine( text ) {
			var piece = state.layout.pieces[ state.selected ];
			var lines = currentLines();

			lines.push( {
				text: text || '',
				colour: config.palette && config.palette.length ? config.palette[ 0 ].value : '#ffffff',
				size: defaultSize( piece ),
				// Offset from the piece centre, in print pixels. Stacked so a
				// second line does not land on top of the first.
				dx: 0,
				dy: Math.round( lines.length * defaultSize( piece ) * 1.3 - piece.safe_h * 0.15 )
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
		 * Push a line back inside the safe area of its piece.
		 *
		 * Round pieces are the interesting case: the limit on how far left a
		 * line may sit depends on how far down it already is, because the safe
		 * area is a circle. Clamping each axis independently against the
		 * bounding square would let a corner poke out of the circle — which is
		 * exactly where a hand cut takes it off.
		 *
		 * @param {Object} line  The line.
		 * @param {Object} piece Its piece.
		 */
		function constrain( line, piece ) {
			var box = measure( line );

			if ( state.layout.round ) {
				var r = piece.safe_w / 2;

				// Shrink until the line can fit across the circle at all.
				while ( box.w / 2 > r && line.size > piece.safe_h * MIN_RATIO ) {
					line.size = Math.max( piece.safe_h * MIN_RATIO, line.size * 0.94 );
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

			while ( box.w > piece.safe_w && line.size > piece.safe_h * MIN_RATIO ) {
				line.size = Math.max( piece.safe_h * MIN_RATIO, line.size * 0.94 );
				box = measure( line );
			}

			var limitX = Math.max( 0, ( piece.safe_w - box.w ) / 2 );
			var limitY = Math.max( 0, ( piece.safe_h - box.h ) / 2 );

			line.dx = Math.max( -limitX, Math.min( limitX, line.dx ) );
			line.dy = Math.max( -limitY, Math.min( limitY, line.dy ) );
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

				// The safe zone, dashed. A constraint rather than a guide, but
				// it still has to be visible or being pushed back by it looks
				// like a bug.
				ctx.setLineDash( [ 4, 4 ] );
				ctx.strokeStyle = 'rgba(0,0,0,0.45)';
				ctx.beginPath();

				if ( state.layout.round ) {
					ctx.arc( px, py, piece.safe_w * k / 2, 0, Math.PI * 2 );
				} else {
					ctx.rect( px - piece.safe_w * k / 2, py - piece.safe_h * k / 2, piece.safe_w * k, piece.safe_h * k );
				}

				ctx.stroke();
				ctx.restore();

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

			var target = out.getContext( '2d' );

			state.layout.pieces.forEach( function ( piece, index ) {
				linesFor( index ).forEach( function ( line ) {
					drawLine( target, line, piece, 1 );
				} );
			} );

			return out.toDataURL( 'image/png' );
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
			mount: function ( element, layout, previewUrl ) {
				canvas = element;
				ctx = canvas.getContext( '2d' );

				state.layout = layout;
				state.selected = 0;
				state.lines = {};

				canvas.addEventListener( 'pointerdown', onDown );
				canvas.addEventListener( 'pointermove', onMove );
				canvas.addEventListener( 'pointerup', onUp );
				canvas.addEventListener( 'pointercancel', onUp );

				window.addEventListener( 'resize', render );

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

			setSameForAll: function ( on ) {
				state.sameForAll = !! on;
				state.selected = 0;
				changed();
			},

			selectPiece: function ( index ) {
				state.selected = index;
				changed();
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

				var headers = { 'Content-Type': 'application/json' };

				if ( config.nonce ) {
					headers['X-WP-Nonce'] = config.nonce;
				}

				return window.fetch( config.root + 'text-layer', {
					method: 'POST',
					credentials: 'same-origin',
					headers: headers,
					body: JSON.stringify( {
						design: designId,
						text: plainText(),
						colours: palette(),
						layer: exportLayer()
					} )
				} ).then( function ( response ) {
					return response.json().then( function ( body ) {
						if ( ! response.ok ) {
							throw new Error( body && body.message ? body.message : config.i18n.textFailed );
						}

						return body;
					} );
				} ).catch( function ( error ) {
					if ( hooks.onError ) {
						hooks.onError( error.message || config.i18n.textFailed );
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
