/**
 * Cutting a decoration out of the customer's own photograph.
 *
 * No framework and no build step, like everything else here: production is
 * plain WordPress on shared hosting, and a bundler in the loop is one more
 * thing that has to work before anyone can buy a cake topper.
 *
 * **The photograph stays still and the selection moves over it** (D-069).
 *
 * The first version did the opposite — a fixed hole in the middle with the
 * picture panning underneath, which is the pattern everyone knows from setting
 * a profile picture. It is the right pattern for "fit my face in a circle" and
 * the wrong one for what this shop sells, which is *one thing taken out of a
 * bigger photograph*: a child out of a group, a dog out of a garden. For that
 * the question is "where in the picture am I", and a frame showing only the
 * crop cannot answer it — past about two times in, the rest of the photograph
 * is off-canvas and the customer is panning blind.
 *
 * There is a second thing this buys, which was not obvious until it was built.
 * The rule *the crop may not run past the edge of the photograph* used to show
 * up as the drag mysteriously refusing to move any further. Now the edge of the
 * photograph is on screen, so the selection visibly stops against it. Same
 * constraint, no longer a mystery.
 *
 * One finger moves the selection, two fingers resize it, and the slider does
 * the same job for anyone with a mouse. No drag handles — they are fine with a
 * pointer and miserable with a thumb, and this shop's traffic is mostly phones.
 */
( function () {
	'use strict';

	/**
	 * @param {Object} config Localised wizard configuration.
	 * @param {Object} hooks  Callbacks: onReady, onZoom.
	 */
	window.AiCakeCropper = function ( config, hooks ) {
		hooks = hooks || {};

		var canvas = null;
		var ctx    = null;
		var image  = null;

		/** The live preview of the decoration itself, if the host gave us one. */
		var preview = null;
		var previewCtx = null;

		/*
		 * The format being cut for. Held rather than read at export time,
		 * because the customer can go back to step 1 and change it — and a
		 * photograph cropped round then exported square is a decoration with
		 * the corners of somebody's living room on it.
		 */
		var format = null;

		/**
		 * The largest the frame may be, in CSS pixels.
		 *
		 * The canvas is then sized to the photograph's own proportions inside
		 * that box, so the picture fills the frame exactly and there is no
		 * letterboxing to explain. It also means "the whole photograph is
		 * visible" is true by construction rather than by arithmetic.
		 */
		var MAX_W = 640;
		var MAX_H = 520;

		/**
		 * How far in the customer may go: the selection may shrink to a sixth
		 * of the largest one that fits.
		 *
		 * The ceiling is only there to stop the slider being absurd. Long
		 * before it, `report()` warns that the crop no longer has the
		 * resolution the print needs.
		 */
		var MAX_ZOOM = 6;

		/**
		 * The selection, in canvas pixels: its centre, and its width.
		 *
		 * Height is derived from the format rather than stored, so a selection
		 * can never drift out of the shape it is going to be printed in.
		 */
		var sel = { cx: 0, cy: 0, w: 0 };

		var drag = null;
		var pinch = null;

		/** The selection's aspect: height as a multiple of width. */
		function ratio() {
			if ( ! format || ! format.targetW || ! format.targetH ) {
				return 1;
			}

			return format.targetH / format.targetW;
		}

		function selHeight() {
			return sel.w * ratio();
		}

		/**
		 * The largest selection that still fits inside the photograph.
		 */
		function maxWidth() {
			if ( ! canvas ) {
				return 0;
			}

			return Math.min( canvas.width, canvas.height / ratio() );
		}

		/**
		 * Keep the selection inside the picture.
		 *
		 * This is the one rule the whole tool exists to enforce: a selection
		 * hanging off the edge would export transparent pixels, and a
		 * transparent patch on a printed decoration is a white bite out of it
		 * that nobody asked for.
		 */
		function clamp() {
			var max = maxWidth();

			sel.w = Math.min( max, Math.max( max / MAX_ZOOM, sel.w ) );

			var halfW = sel.w / 2;
			var halfH = selHeight() / 2;

			sel.cx = Math.min( canvas.width - halfW, Math.max( halfW, sel.cx ) );
			sel.cy = Math.min( canvas.height - halfH, Math.max( halfH, sel.cy ) );
		}

		/**
		 * The outline of the selection, in canvas coordinates.
		 *
		 * @param {CanvasRenderingContext2D} target Where to trace it.
		 */
		function selectionPath( target ) {
			var halfW = sel.w / 2;
			var halfH = selHeight() / 2;

			if ( format && 'round' === format.shape ) {
				target.moveTo( sel.cx + halfW, sel.cy );
				target.arc( sel.cx, sel.cy, halfW, 0, Math.PI * 2 );

				return;
			}

			target.rect( sel.cx - halfW, sel.cy - halfH, sel.w, selHeight() );
		}

		/**
		 * Draw the photograph, dim everything outside the selection, outline it.
		 */
		function render() {
			if ( ! ctx || ! image ) {
				return;
			}

			ctx.clearRect( 0, 0, canvas.width, canvas.height );
			ctx.drawImage( image, 0, 0, canvas.width, canvas.height );

			/*
			 * The mask is a filled rectangle with the selection punched out of
			 * it — `evenodd` — rather than an outline. An outline says "the cut
			 * is here"; a punched mask says "this is what you are buying", and
			 * the rest of the photograph stays visible underneath it so the
			 * customer can still see what they are aiming at.
			 */
			ctx.save();
			ctx.fillStyle = 'rgba(255,255,255,0.62)';
			ctx.beginPath();
			ctx.rect( 0, 0, canvas.width, canvas.height );
			selectionPath( ctx );
			ctx.fill( 'evenodd' );
			ctx.restore();

			ctx.save();
			ctx.strokeStyle = '#000000';
			ctx.lineWidth = Math.max( 1.5, canvas.width / 260 );
			ctx.beginPath();
			selectionPath( ctx );
			ctx.stroke();
			ctx.restore();

			renderPreview();
		}

		/**
		 * The decoration itself, at a size worth looking at.
		 *
		 * This is what the frame used to be, and giving it up is the cost of
		 * showing the whole photograph. Handing it back as its own panel is
		 * better than either arrangement alone: context on one side, product on
		 * the other, both live.
		 */
		function renderPreview() {
			if ( ! previewCtx || ! image ) {
				return;
			}

			var box = sourceRect();

			previewCtx.clearRect( 0, 0, preview.width, preview.height );
			previewCtx.save();

			if ( format && 'round' === format.shape ) {
				previewCtx.beginPath();
				previewCtx.arc(
					preview.width / 2,
					preview.height / 2,
					Math.min( preview.width, preview.height ) / 2,
					0,
					Math.PI * 2
				);
				previewCtx.clip();
			}

			previewCtx.drawImage(
				image,
				box.x, box.y, box.w, box.h,
				0, 0, preview.width, preview.height
			);

			previewCtx.restore();
		}

		/**
		 * The selection, expressed in the original photograph's own pixels.
		 *
		 * The canvas is the photograph scaled down, so one factor converts
		 * between them. Deriving it in a single place is what keeps what the
		 * customer saw and what gets printed the same picture.
		 */
		function sourceRect() {
			var k = image.width / canvas.width;

			return {
				x: ( sel.cx - ( sel.w / 2 ) ) * k,
				y: ( sel.cy - ( selHeight() / 2 ) ) * k,
				w: sel.w * k,
				h: selHeight() * k
			};
		}

		/**
		 * Resize the selection about its own centre.
		 *
		 * @param {number} width Requested width in canvas pixels.
		 */
		function resize( width ) {
			if ( ! image || ! canvas ) {
				return;
			}

			sel.w = width;

			clamp();
			render();
			report();
		}

		/**
		 * Tell the host where the zoom is, and whether the crop is still sharp.
		 *
		 * The sharpness half matters more than it looks. A smaller selection
		 * means fewer source pixels than the print needs, and nothing on screen
		 * betrays it — the frame is a few hundred pixels wide and everything
		 * looks fine at a few hundred pixels. The customer would find out when
		 * the sheet arrived.
		 */
		function report() {
			if ( ! hooks.onZoom || ! image || ! format || ! canvas ) {
				return;
			}

			var box = sourceRect();
			var max = maxWidth();

			hooks.onZoom( {
				// 1 is the largest selection that fits; 6 is a sixth of it.
				scale: max > 0 ? max / sel.w : 1,
				max: MAX_ZOOM,
				// A little under the print's own resolution is invisible. Far
				// under is not.
				sharp: box.w >= format.targetW * 0.75 && box.h >= format.targetH * 0.75
			} );
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
			if ( ! image ) {
				return;
			}

			if ( event.touches && event.touches.length === 2 ) {
				pinch = { at: spread( event ), w: sel.w };
				drag  = null;

				return;
			}

			var p = pointFrom( event );

			/*
			 * The selection jumps to wherever it is grabbed, rather than only
			 * moving when the customer happens to start inside it. On a phone,
			 * hunting for the inside of a small circle with a fingertip that
			 * covers it is the difference between a tool and a puzzle.
			 */
			sel.cx = p.x;
			sel.cy = p.y;

			clamp();
			render();
			report();

			drag = { x: p.x - sel.cx, y: p.y - sel.cy };
		}

		function onMove( event ) {
			if ( ! image ) {
				return;
			}

			if ( pinch && event.touches && event.touches.length === 2 ) {
				resize( pinch.w * ( spread( event ) / pinch.at ) );
				event.preventDefault();

				return;
			}

			if ( ! drag ) {
				return;
			}

			var p = pointFrom( event );

			sel.cx = p.x - drag.x;
			sel.cy = p.y - drag.y;

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
			 * Attach to the working canvas, and optionally a preview canvas.
			 *
			 * @param {HTMLCanvasElement} element   The frame.
			 * @param {HTMLCanvasElement} [thumb]   Live preview of the piece.
			 */
			mount: function ( element, thumb ) {
				canvas  = element;
				ctx     = canvas.getContext( '2d' );
				preview = thumb || null;
				previewCtx = preview ? preview.getContext( '2d' ) : null;

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

				// The wheel resizes the selection, which is what a pointer user
				// reaches for without thinking. `passive: false`, or the page
				// scrolls out from under the thing being aimed at.
				canvas.addEventListener(
					'wheel',
					function ( event ) {
						if ( ! image ) {
							return;
						}

						event.preventDefault();
						resize( sel.w * ( event.deltaY < 0 ? 1 / 1.12 : 1.12 ) );
					},
					{ passive: false }
				);
			},

			/**
			 * Set the shape being cut.
			 *
			 * @param {Object} chosen One entry of config.formats.
			 */
			setFormat: function ( chosen ) {
				format = chosen;

				if ( preview ) {
					var side = 180;

					preview.width  = side;
					preview.height = Math.round( side * ratio() );
				}

				if ( image ) {
					this.reset();
				}
			},

			/**
			 * Centre the selection and open it as wide as it will go.
			 */
			reset: function () {
				if ( ! canvas || ! image ) {
					return;
				}

				sel.w  = maxWidth();
				sel.cx = canvas.width / 2;
				sel.cy = canvas.height / 2;

				clamp();
				render();
				report();
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
				var self = this;

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

						/*
						 * The frame takes the photograph's own proportions,
						 * inside a sensible box. So the picture fills it
						 * exactly — no letterboxing to explain, and "the whole
						 * photograph is visible" is true by construction.
						 */
						var k = Math.min( MAX_W / img.width, MAX_H / img.height );

						canvas.width  = Math.max( 1, Math.round( img.width * k ) );
						canvas.height = Math.max( 1, Math.round( img.height * k ) );

						self.reset();

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
			 * 1 is the largest selection that fits inside the photograph, and
			 * larger numbers take a smaller part of it.
			 *
			 * @param {number} scale How far in.
			 */
			zoom: function ( scale ) {
				resize( maxWidth() / Math.min( MAX_ZOOM, Math.max( 1, scale ) ) );
			},

			/**
			 * The furthest in the customer may go.
			 */
			maxZoom: function () {
				return MAX_ZOOM;
			},

			/**
			 * The selection, at print resolution, as a data URL.
			 *
			 * Read from the original photograph rather than from the frame —
			 * the frame is at most 640 px because that is all a screen needs,
			 * and exporting it would print a decoration at a fraction of the
			 * resolution it was chosen at.
			 *
			 * **The canvas is verified before it is trusted** (D-057). Safari
			 * on iOS does not throw when a canvas exceeds its area budget; it
			 * hands back one that reads as transparent, and `toDataURL()` then
			 * produces a valid, blank JPEG.
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

				var box = sourceRect();

				target.fillStyle = '#ffffff';
				target.fillRect( 0, 0, out.width, out.height );
				target.drawImage(
					image,
					box.x, box.y, box.w, box.h,
					0, 0, out.width, out.height
				);

				/*
				 * JPEG, not PNG. A photograph is a continuous-tone image, which
				 * is precisely what JPEG is for; PNG would be several megabytes
				 * of base64 over a phone connection for no gain. The server
				 * re-encodes to PNG anyway (D-062).
				 */
				return out.toDataURL( 'image/jpeg', 0.92 );
			}
		};

		/**
		 * Did this canvas keep what was written to it? (D-057)
		 *
		 * Probed in two far-apart corners and erased afterwards, so it can
		 * never reach the exported picture.
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
