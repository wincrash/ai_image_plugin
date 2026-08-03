<?php
/**
 * Tests for D-041's design director.
 *
 * @package AiCake
 */

declare( strict_types=1 );

use AiCake\Imaging\LayerInspector;
use AiCake\Pipeline\LayoutSuggester;
use AiCake\Support\HttpClient;
use AiCake\Support\HttpResponse;
use AiCake\Support\Logger;
use AiCake\Support\Settings;

/**
 * A canned Gemini reply, so the logic can be tested without the API.
 *
 * This is what the `HttpClient` seam is for — the same adapter runs against
 * the live service and against this.
 */
class StubLayoutHttp implements HttpClient {

	/**
	 * @var array<string, mixed>
	 */
	private array $payload;

	/**
	 * @param array<string, mixed> $payload What the model "returned".
	 */
	public function __construct( array $payload ) {
		$this->payload = $payload;
	}

	/**
	 * @param string               $method  Verb.
	 * @param string               $url     URL.
	 * @param array<string, mixed> $options Options.
	 */
	public function request( string $method, string $url, array $options = array() ): HttpResponse {
		$body = array(
			'candidates' => array(
				array(
					'content' => array(
						'parts' => array( array( 'text' => (string) wp_json_encode( $this->payload ) ) ),
					),
				),
			),
		);

		return new HttpResponse( 200, (string) wp_json_encode( $body ) );
	}
}

/**
 * D-041: the model proposes, the browser draws, the customer edits.
 *
 * Everything here is about the *clamp*. A response schema constrains the shape
 * of what comes back and nothing constrains the values — a model will happily
 * return a valid-shaped layout with eleven colours, a font that does not
 * exist, and a name nobody typed. The clamp is what makes a suggestion always
 * usable, and the word check is what stops a cake being printed with words the
 * customer never asked for.
 */
class LayoutSuggesterTest extends TestCase {

	private const FONTS = array( 'dejavusans', 'dejavusans-bold' );

	/**
	 * A suggester wired to a canned reply.
	 *
	 * @param array<string, mixed> $payload What the model returns.
	 */
	private function suggester( array $payload ): LayoutSuggester {
		$settings = new Settings();

		return new LayoutSuggester( new StubLayoutHttp( $payload ), $settings, new Logger( $settings ) );
	}

	/**
	 * A well-formed reply survives intact.
	 */
	public function test_a_good_suggestion_passes_through(): void {
		$suggestion = $this->suggester(
			array(
				'lines'          => array(
					array(
						'text'       => 'SU GIMTADIENIU',
						'colour'     => '#FFFFFF',
						'size_ratio' => 0.10,
						'dy_ratio'   => -0.18,
					),
					array(
						'text'       => 'Ąžuolas',
						'colour'     => '#ffffff',
						'size_ratio' => 0.20,
						'dy_ratio'   => 0.05,
					),
				),
				'outline'        => true,
				'outline_colour' => '#000000',
				'font'           => 'dejavusans-bold',
			)
		)->suggest( 'Su gimtadieniu Ąžuolas', 'meškiukas', self::FONTS, true );

		$this->assert_same( 2, count( $suggestion['lines'] ), 'two lines' );
		$this->assert_same( 'SU GIMTADIENIU', $suggestion['lines'][0]['text'], 'text kept' );
		$this->assert_same( '#ffffff', $suggestion['lines'][0]['colour'], 'colour lowercased' );
		$this->assert_same( 'dejavusans-bold', $suggestion['font'], 'font kept' );
		$this->assert_true( $suggestion['outline'], 'outline kept' );
	}

	/**
	 * Capitalisation is the model's to change. The words are not.
	 */
	public function test_recasing_and_resplitting_is_allowed(): void {
		$suggestion = $this->suggester(
			array(
				'lines'          => array(
					array(
						'text'       => 'SU',
						'colour'     => '#ffffff',
						'size_ratio' => 0.1,
						'dy_ratio'   => -0.1,
					),
					array(
						'text'       => 'gimtadieniu!',
						'colour'     => '#ffffff',
						'size_ratio' => 0.2,
						'dy_ratio'   => 0.1,
					),
				),
				'outline'        => true,
				'outline_colour' => '#000000',
				'font'           => 'dejavusans',
			)
		)->suggest( 'Su Gimtadieniu', '', self::FONTS, true );

		$this->assert_same( 2, count( $suggestion['lines'] ), 'a different split of the same words is fine' );
	}

	/**
	 * A word nobody typed is a refusal, not a correction.
	 *
	 * The load-bearing one. Moderation ran against what the customer typed; a
	 * model that adds "Mylime" to a birthday cake has put words on a product
	 * that nothing checked and nobody asked for.
	 */
	public function test_an_invented_word_discards_the_suggestion(): void {
		$suggestion = $this->suggester(
			array(
				'lines'          => array(
					array(
						'text'       => 'Su gimtadieniu',
						'colour'     => '#ffffff',
						'size_ratio' => 0.1,
						'dy_ratio'   => -0.1,
					),
					array(
						'text'       => 'Mylime tave',
						'colour'     => '#ffffff',
						'size_ratio' => 0.2,
						'dy_ratio'   => 0.1,
					),
				),
				'outline'        => true,
				'outline_colour' => '#000000',
				'font'           => 'dejavusans',
			)
		)->suggest( 'Su gimtadieniu', '', self::FONTS, true );

		$this->assert_same( array(), $suggestion, 'an added phrase discards the whole suggestion' );
	}

	/**
	 * And so is a word quietly dropped.
	 */
	public function test_a_dropped_word_discards_the_suggestion(): void {
		$suggestion = $this->suggester(
			array(
				'lines'          => array(
					array(
						'text'       => 'Ąžuolas',
						'colour'     => '#ffffff',
						'size_ratio' => 0.2,
						'dy_ratio'   => 0.0,
					),
				),
				'outline'        => true,
				'outline_colour' => '#000000',
				'font'           => 'dejavusans',
			)
		)->suggest( 'Su gimtadieniu Ąžuolas', '', self::FONTS, true );

		$this->assert_same( array(), $suggestion, 'losing a name discards the suggestion' );
	}

	/**
	 * Absurd values are pulled into range rather than rejected.
	 *
	 * A layout is a matter of taste and a bad number is not worth throwing a
	 * whole suggestion away for — unlike a changed word, which is.
	 */
	public function test_out_of_range_values_are_clamped(): void {
		$suggestion = $this->suggester(
			array(
				'lines'          => array(
					array(
						'text'       => 'Ąžuolas',
						'colour'     => 'not-a-colour',
						'size_ratio' => 40,
						'dy_ratio'   => -12,
					),
				),
				'outline'        => true,
				'outline_colour' => 'also-not',
				'font'           => 'comic-sans-that-we-do-not-have',
			)
		)->suggest( 'Ąžuolas', '', self::FONTS, true );

		$this->assert_same( 0.45, $suggestion['lines'][0]['size_ratio'], 'size is clamped to the maximum' );
		$this->assert_same( -0.42, $suggestion['lines'][0]['dy_ratio'], 'offset is clamped to the top' );
		$this->assert_same( '#ffffff', $suggestion['lines'][0]['colour'], 'an unparseable colour falls back' );
		$this->assert_same( '#000000', $suggestion['outline_colour'], 'so does the outline colour' );
		$this->assert_same( 'dejavusans', $suggestion['font'], 'an unavailable font falls back to a real one' );
	}

	/**
	 * A suggestion never declares more colours than the endpoint accepts.
	 *
	 * The invariant, not the mechanism. Writing this test found that with
	 * three lines and a cap of four the palette *cannot* exceed the cap — the
	 * collapse branch in `clamp()` is unreachable today. It is kept as a guard
	 * for the day either constant moves, and this asserts the property that
	 * actually matters rather than pretending to exercise dead code.
	 */
	public function test_the_palette_fits_what_the_endpoint_accepts(): void {
		$lines = array();

		foreach ( array( '#ff0000', '#00ff00', '#0000ff' ) as $i => $colour ) {
			$lines[] = array(
				'text'       => 'zodis' . $i,
				'colour'     => $colour,
				'size_ratio' => 0.1,
				'dy_ratio'   => 0.0,
			);
		}

		$suggestion = $this->suggester(
			array(
				'lines'          => $lines,
				'outline'        => true,
				'outline_colour' => '#111111',
				'font'           => 'dejavusans',
			)
		)->suggest( 'zodis0 zodis1 zodis2', '', self::FONTS, true );

		$palette = array_unique( array_column( $suggestion['lines'], 'colour' ) );

		if ( $suggestion['outline'] ) {
			$palette[] = $suggestion['outline_colour'];
		}

		$this->assert_true(
			count( array_unique( $palette ) ) <= LayerInspector::MAX_COLOURS,
			'the declared palette fits what the endpoint accepts'
		);
	}

	/**
	 * More lines than the editor can draw discards the suggestion.
	 *
	 * Truncating to three would silently drop a word, and a name missing off a
	 * birthday cake is the one failure worth refusing the whole answer for —
	 * which is exactly what the word check then does.
	 */
	public function test_more_lines_than_we_can_draw_is_refused(): void {
		$lines = array();

		foreach ( array( '#ffffff', '#ffffff', '#ffffff', '#ffffff' ) as $i => $colour ) {
			$lines[] = array(
				'text'       => 'zodis' . $i,
				'colour'     => $colour,
				'size_ratio' => 0.1,
				'dy_ratio'   => 0.0,
			);
		}

		$suggestion = $this->suggester(
			array(
				'lines'          => $lines,
				'outline'        => true,
				'outline_colour' => '#000000',
				'font'           => 'dejavusans',
			)
		)->suggest( 'zodis0 zodis1 zodis2 zodis3', '', self::FONTS, true );

		$this->assert_same( array(), $suggestion, 'a four-line answer is refused rather than truncated' );
	}

	/**
	 * Nothing typed, nothing to lay out.
	 */
	public function test_empty_input_asks_nothing(): void {
		$suggestion = $this->suggester( array( 'lines' => array() ) )->suggest( '   ', '', self::FONTS, true );

		$this->assert_same( array(), $suggestion, 'blank text returns no suggestion' );
	}
}
