/**
 * Cutting a decoration out of the customer's own photograph.
 *
 * No framework and no build step, like everything else here: production is
 * plain WordPress on shared hosting, and a bundler in the loop is one more
 * thing that has to work before anyone can buy a cake topper.
 *
 * The model is the one people already know from setting a profile picture: the
 * photograph moves and scales underneath a fixed hole, and what shows through
 * the hole is what gets printed. There is no crop rectangle to drag, no
 * handles, and nothing to get wrong on a phone — one finger pans, two fingers
 * pinch, and the shape of the hole is the shape of the decoration.
 */
( function () {
	'use strict';

	/**
	 * @param {Object} config Localised wizard configuration.
	 * @param {Object} hooks  Callbacks: onReady, onError.
	 */
	window.AiCakeCropper = function ( config, hooks ) {
		hooks = hooks || {};

		var canvas = null;
		var ctx    = null;
		var image  = null;

		/*
		 * The format being cut for. Held rather than read at export time,
		 * because the customer can go back to step 1 and change it — and a
		 * photograph cropped round then exported square is a decoration with
		 * the corners of somebody's living room on it.
		 */
		var format = null;

		// Pan in canvas pixels, and scale as a multiple of the "just covers"
		// size. Never below 1, or the photograph stops filling the hole and
		// the gap prints as bare icing.
		var view = { x: 0, y: 0, scale: 1 };

		var drag = null;
		var pinch = null;

		/**
		 * How far in the customer may go.
		 *
		 * Six times the "just covers" size. Beyond that the crop is a handful
		 * of source pixels stretched across a decoration, and the print looks
		 * like a mistake rather than a choice — `onQuality` warns long before
		 * this, so the ceiling is only there to stop the slider being absurd.
		 */
		var MAX_ZOOM = 6;

		/**
		 * Change the zoom, keeping the middle of the hole where it is.
		 *
		 * Zooming about the centre rather than about the pointer is the less
		 * clever choice and the right one: the customer is looking at what is
		 * inside the circle, and that is the thing that should stay still.
		 *
		 * @param {number} next Requested scale, as a multiple of cover.
		 */
		function zoomTo( next ) {
			if ( ! image || ! canvas ) {
				return;
			}

			var before = coverScale() * view.scale;

			view.scale = Math.min( MAX_ZOOM, Math.max( 1, next ) );

			var after = coverScale() * view.scale;
			var ratio = after / before;

			view.x = ( canvas.width / 2 ) - ( ( canvas.width / 2 ) - view.x ) * ratio;
			view.y = ( canvas.height / 2 ) - ( ( canvas.height / 2 ) - view.y ) * ratio;

			clamp();
			render();
			report();
		}

		/**
		 * Tell the host where the zoom is, and whether the crop is still sharp.
		 *
		 * The second half matters more than it looks. Zooming in takes a
		 * smaller part of the photograph, so beyond a point there are fewer
		 * source pixels than the print needs and the decoration comes out soft
		 * — and nothing on screen would say so, because the viewport is 640 px
		 * and everything looks fine at 640 px. The customer finds out when the
		 * sheet arrives.
		 */
		function report() {
			if ( ! hooks.onZoom || ! image || ! format ) {
				return;
			}

			// Source pixels currently inside the hole, along the long edge.
			var s = coverScale() * view.scale;
			var sourceW = canvas.width / s;
			var sourceH = canvas.height / s;

			hooks.onZoom( {
				scale: view.scale,
				max: MAX_ZOOM,
				// True while the crop still has at least the print's own
				// resolution. A little under is invisible; far under is not.
				sharp: sourceW >= format.targetW * 0.75 && sourceH >= format.targetH * 0.75
			} );
		}

		/**
		 * The smallest scale that still covers the hole completely.
		 */
		function coverScale() {
			if ( ! image || ! canvas ) {
				return 1;
			}

			return Math.max( canvas.width / image.width, canvas.height / image.height );
		}

		/**
		 * Keep the photograph over the hole.
		 *
		 * Clamped rather than merely discouraged: a pan that leaves a corner
		 * uncovered would export transparent pixels, and a transparent hole in
		 * a printed decoration is a white bite out of the edge that nobody
		 * asked for.
		 */
		function clamp() {
			var s = coverScale() * view.scale;
			var w = image.width * s;
			var h = image.height * s;

			var minX = canvas.width - w;
			var minY = canvas.height - h;

			view.x = Math.min( 0, Math.max( minX, view.x ) );
			view.y = Math.min( 0, Math.max( minY, view.y ) );
		}

		/**
		 * Draw the photograph, then dim everything outside the shape.
		 *
		 * The mask is drawn as a filled rectangle with the shape punched out of
		 * it — `evenodd` — rather than as a stroked outline. An outline says
		 * "the cut is here"; a punched mask says "this is what you are buying",
		 * and on a round decoration the difference is the whole point.
		 */
		function render() {
			if ( ! ctx || ! image ) {
				return;
			}

			var s = coverScale() * view.scale;

			ctx.clearRect( 0, 0, canvas.width, canvas.height );
			ctx.drawImage( image, view.x, view.y, image.width * s, image.height * s );

			ctx.save();
			ctx.fillStyle = 'rgba(255,255,255,0.72)';
			ctx.beginPath();
			ctx.rect( 0, 0, canvas.width, canvas.height );
			shapePath();
			ctx.fill( 'evenodd' );
			ctx.restore();

			ctx.save();
			ctx.strokeStyle = '#000000';
			ctx.lineWidth = Math.max( 1, canvas.width / 300 );
			ctx.beginPath();
			shapePath();
			ctx.stroke();
			ctx.restore();
		}

		/**
		 * The outline of one piece, in canvas coordinates.
		 */
		function shapePath() {
			if ( format && 'round' === format.shape ) {
				var r = Math.min( canvas.width, canvas.height ) / 2;

				ctx.moveTo( canvas.width / 2 + r, canvas.height / 2 );
				ctx.arc( canvas.width / 2, canvas.height / 2, r, 0, Math.PI * 2 );

				return;
			}

			ctx.rect( 0, 0, canvas.width, canvas.height );
		}

		/* ------------------------------------------------------- gestures */

		function pointFrom( event ) {
			var box = canvas.getBoundingClientRect();
			var t   = event.touches && event.touches.length ? event.touches[ 0 ] : event;

			return {
				x: ( t.clientX - box.left ) * ( canvas.width / box.width ),
				y: ( t.clientY - box.top ) * ( canvas.height / box.height )
			};
		}

		function spread( event ) {
			var a = event.touches[ 0 ];
			var b = event.touches[ 1 ];

			return Math.hypot( a.clientX - b.clientX, a.clientY - b.clientY );
		}

		function onDown( event ) {
			if ( event.touches && event.touches.length === 2 ) {
				pinch = { at: spread( event ), scale: view.scale };
				drag  = null;

				return;
			}

			var p = pointFrom( event );

			drag = { x: p.x - view.x, y: p.y - view.y };
		}

		function onMove( event ) {
			if ( pinch && event.touches && event.touches.length === 2 ) {
				zoomTo( pinch.scale * ( spread( event ) / pinch.at ) );
				event.preventDefault();

				return;
			}

			if ( ! drag ) {
				return;
			}

			var p = pointFrom( event );

			view.x = p.x - drag.x;
			view.y = p.y - drag.y;

			clamp();
			render();
			event.preventDefault();
		}

		function onUp() {
			drag = null;
			pinch = null;
		}

		return {
			/**
			 * Attach to a canvas.
			 *
			 * @param {HTMLCanvasElement} element The viewport.
			 */
			mount: function ( element ) {
				canvas = element;
				ctx    = canvas.getContext( '2d' );

				if ( canvas.dataset.aicakeBound ) {
					return;
				}

				canvas.dataset.aicakeBound = '1';

				canvas.addEventListener( 'mousedown', onDown );
				canvas.addEventListener( 'touchstart', onDown, { passive: true } );
				window.addEventListener( 'mousemove', onMove );
				canvas.addEventListener( 'touchmove', onMove, { passive: false } );
				window.addEventListener( 'mouseup', onUp );
				canvas.addEventListener( 'touchend', onUp );

				/*
				 * The wheel, for anyone with a mouse. The slider is the
				 * discoverable control and this is the one people reach for
				 * without thinking — but the slider is what makes the feature
				 * *exist* on a desktop, because until it did there was pinch
				 * and nothing else, and the crop was stuck at the whole
				 * picture. Ruslan, 2026-08-09.
				 *
				 * `passive: false`, because it has to stop the page scrolling
				 * out from under the thing being zoomed.
				 */
				canvas.addEventListener(
					'wheel',
					function ( event ) {
						if ( ! image ) {
							return;
						}

						event.preventDefault();
						zoomTo( view.scale * ( event.deltaY < 0 ? 1.12 : 1 / 1.12 ) );
					},
					{ passive: false }
				);
			},

			/**
			 * Set the shape being cut, and size the viewport to match it.
			 *
			 * The viewport is drawn at a fraction of print resolution — it only
			 * has to be looked at. The export re-reads the original photograph
			 * at full size, so nothing here costs the customer any sharpness.
			 *
			 * @param {Object} chosen One entry of config.formats.
			 */
			setFormat: function ( chosen ) {
				format = chosen;

				if ( ! canvas ) {
					return;
				}

				var ratio = ( chosen.targetH && chosen.targetW )
					? chosen.targetH / chosen.targetW
					: 1;

				canvas.width  = 640;
				canvas.height = Math.round( 640 * ratio );

				view = { x: 0, y: 0, scale: 1 };

				if ( image ) {
					clamp();
					render();
					report();
				}
			},

			/**
			 * Load whatever the customer picked.
			 *
			 * **Anything the browser can decode**, which is the point: JPEG,
			 * PNG, WebP, GIF, BMP — and HEIC, which is what an iPhone shoots by
			 * default and which GD has never been able to read. The customer
			 * never hears the word "format" (D-062); the canvas is the
			 * converter.
			 *
			 * @param {File} file From the file input.
			 * @return {Promise}
			 */
			load: function ( file ) {
				return new Promise( function ( resolve, reject ) {
					if ( ! file || ! /^image\//.test( file.type || '' ) ) {
						reject( new Error( config.i18n.notAnImage ) );

						return;
					}

					var url = URL.createObjectURL( file );
					var img = new Image();

					img.onload = function () {
						URL.revokeObjectURL( url );

						image = img;
						view = { x: 0, y: 0, scale: 1 };

						clamp();
						render();
						report();

						if ( hooks.onReady ) {
							hooks.onReady();
						}

						resolve();
					};

					img.onerror = function () {
						URL.revokeObjectURL( url );
						reject( new Error( config.i18n.notAnImage ) );
					};

					img.src = url;
				} );
			},

			/**
			 * Is there a photograph to work with?
			 */
			hasImage: function () {
				return null !== image;
			},

			/**
			 * Set the zoom from outside — the slider.
			 *
			 * @param {number} scale Multiple of the "just covers" size.
			 */
			zoom: function ( scale ) {
				zoomTo( scale );
			},

			/**
			 * The furthest in the customer may go.
			 */
			maxZoom: function () {
				return MAX_ZOOM;
			},

			/**
			 * The crop, at print resolution, as a data URL.
			 *
			 * Rendered from the original photograph rather than from the
			 * viewport canvas — the viewport is 640 px because that is all a
			 * screen needs, and exporting it would print a decoration at a
			 * fifth of the resolution it was cropped at.
			 *
			 * **The canvas is verified before it is trusted** (D-057). Safari
			 * on iOS does not throw when a canvas exceeds its area budget; it
			 * hands back one that reads as transparent, and `toDataURL()` then
			 * produces a valid, blank JPEG. A ⌀20 cm circle is 2434 px square —
			 * well inside the 8.3 megapixels the text layer already asks for,
			 * but the same silence applies.
			 *
			 * @return {string} Data URL, or '' if this device could not.
			 */
			exportCrop: function () {
				if ( ! image || ! format ) {
					return '';
				}

				var out = document.createElement( 'canvas' );

				out.width  = format.targetW;
				out.height = format.targetH;

				var target = out.getContext( '2d' );

				if ( ! target || ! holds( out, target ) ) {
					return '';
				}

				/*
				 * The viewport and the export are the same crop at two sizes,
				 * so every coordinate scales by one factor. Deriving it here,
				 * once, is what keeps what the customer saw and what gets
				 * printed the same picture.
				 */
				var k = out.width / canvas.width;
				var s = coverScale() * view.scale * k;

				target.fillStyle = '#ffffff';
				target.fillRect( 0, 0, out.width, out.height );
				target.drawImage(
					image,
					view.x * k,
					view.y * k,
					image.width * s,
					image.height * s
				);

				/*
				 * JPEG, not PNG. A photograph as PNG is several megabytes of
				 * base64 over a phone connection for no gain — it is a
				 * continuous-tone image, which is precisely what JPEG is for.
				 * The server re-encodes to PNG anyway (D-062).
				 */
				return out.toDataURL( 'image/jpeg', 0.92 );
			}
		};

		/**
		 * Did this canvas keep what was written to it? (D-057)
		 *
		 * The probe is drawn and read back in two far-apart corners, then
		 * erased, so it can never reach the exported picture.
		 *
		 * @param {HTMLCanvasElement}        element The canvas.
		 * @param {CanvasRenderingContext2D} probe   Its context.
		 */
		function holds( element, probe ) {
			var w = element.width;
			var h = element.height;

			if ( w < 1 || h < 1 ) {
				return false;
			}

			try {
				var spots = [ [ 0, 0 ], [ w - 4, h - 4 ] ];

				var ok = spots.every( function ( at ) {
					probe.fillStyle = '#ff0000';
					probe.fillRect( at[ 0 ], at[ 1 ], 4, 4 );

					var px = probe.getImageData( at[ 0 ] + 1, at[ 1 ] + 1, 1, 1 ).data;

					return px[ 0 ] > 200 && px[ 3 ] > 200;
				} );

				spots.forEach( function ( at ) {
					probe.clearRect( at[ 0 ], at[ 1 ], 4, 4 );
				} );

				return ok;
			} catch ( e ) {
				return false;
			}
		}
	};
}() );
