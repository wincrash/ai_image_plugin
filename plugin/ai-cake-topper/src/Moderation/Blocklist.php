<?php
/**
 * The cheap layer that runs before we spend anything.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Moderation;

defined( 'ABSPATH' ) || exit;

/**
 * PLAN.md §10 Layer 1.
 *
 * Runs before the LLM call, because it is free and instant and catches the
 * majority of real attempts. The LLM behind it is better — it catches
 * paraphrases with no proper noun at all (D-019) — but it costs money and
 * about 800 ms, and there is no reason to spend either on someone who typed
 * "Elsa".
 *
 * Terms are stored one per line and edited from the admin, so growing the list
 * from real rejections never needs a deploy.
 */
class Blocklist {

	public const OPTION = 'aicake_blocklist';

	/**
	 * Franchises worth shipping with.
	 *
	 * **Both languages, because this layer runs before translation.** A list
	 * of English titles would miss almost everything a Lithuanian customer
	 * actually types: Spider-Man is `Žmogus-voras`, Paw Patrol is `Šunyčiai
	 * patruliai`, Frozen is `Ledo šalis`.
	 *
	 * Deliberately excluded, despite being real franchise names: `Ratai`
	 * (Cars) means "wheels", `Lokys` (from Masha and the Bear) means "bear",
	 * and `Kiaulytė` means "piglet". Blocking those would refuse innocent
	 * cake decorations, and §10 is explicit that an over-eager filter is worse
	 * than useless. Multi-word phrases are safe because matching requires the
	 * whole phrase contiguously.
	 *
	 * @var string[]
	 */
	private const STARTER = array(
		// Frozen.
		'Elsa',
		'Elza',
		'Olaf',
		'Olafas',
		'Ledo šalis',
		'Frozen',

		// Marvel and DC.
		'Spider-Man',
		'Spiderman',
		'Žmogus voras',
		'Voratinklio žmogus',
		'Batman',
		'Betmenas',
		'Superman',
		'Supermenas',
		'Iron Man',
		'Geležinis žmogus',
		'Hulk',
		'Halkas',
		'Avengers',
		'Keršytojai',
		'Captain America',

		// Paw Patrol.
		'Paw Patrol',
		'Šunyčiai patruliai',
		'Skye',
		'Chase',

		// Peppa Pig.
		'Peppa',
		'Pepa',
		'Peppa Pig',

		// Disney.
		'Mickey Mouse',
		'Peliukas Mikis',
		'Minnie Mouse',
		'Winnie the Pooh',
		'Mikė Pūkuotukas',
		'Moana',
		'Vaiana',
		'Encanto',
		'Toy Story',
		'Žaislų istorija',
		'Buzz Lightyear',
		'Lion King',
		'Liūtas karalius',
		'Simba',
		'Ariel',
		'Undinėlė Arielė',
		'Cinderella',
		'Pelenė',
		'Snow White',
		'Snieguolė',

		// Others.
		'Pokemon',
		'Pokemonas',
		'Pikachu',
		'Pikaču',
		'Minecraft',
		'Roblox',
		'Hello Kitty',
		'Super Mario',
		'Mario',
		'Luigi',
		'Sonic',
		'Sonikas',
		'Barbie',
		'Barbė',
		'Harry Potter',
		'Haris Poteris',
		'Hogwarts',
		'Hogvartsas',
		'Bluey',
		'Minions',
		'Minionai',
		'Gru',
		'Among Us',
		'Star Wars',
		'Žvaigždžių karai',
		'Darth Vader',
		'Baby Yoda',
		'Ninja Turtles',
		'Vėžliukai nindzės',
		'Masha and the Bear',
		'Maša ir lokys',
		'My Little Pony',
		'Angry Birds',
		'Coca-Cola',
		'Pepsi',
		'McDonalds',
		'Nike',
		'Adidas',
	);

	/**
	 * Cached term list.
	 *
	 * @var string[]|null
	 */
	private ?array $terms = null;

	/**
	 * Every active term: the shipped list plus whatever the shop added, less
	 * whatever it removed.
	 *
	 * @return string[]
	 */
	public function terms(): array {
		if ( null !== $this->terms ) {
			return $this->terms;
		}

		$stored = get_option( self::OPTION, null );

		if ( ! is_array( $stored ) ) {
			// Never configured: use the shipped list as-is.
			$this->terms = self::STARTER;

			return $this->terms;
		}

		$custom  = array_map( 'strval', (array) ( $stored['custom'] ?? array() ) );
		$removed = array_map( 'strval', (array) ( $stored['removed'] ?? array() ) );

		$starter = array_values(
			array_filter(
				self::STARTER,
				static fn( string $term ): bool => ! in_array( $term, $removed, true )
			)
		);

		$this->terms = array_values( array_unique( array_merge( $starter, $custom ) ) );

		return $this->terms;
	}

	/**
	 * The terms the shop added, for the admin textarea.
	 *
	 * @return string[]
	 */
	public function custom_terms(): array {
		$stored = get_option( self::OPTION, null );

		return is_array( $stored ) ? array_map( 'strval', (array) ( $stored['custom'] ?? array() ) ) : array();
	}

	/**
	 * The shipped terms the shop has switched off.
	 *
	 * Stored as an exclusion list rather than by saving an edited copy of the
	 * whole list, so a plugin update can add a new built-in term and have it
	 * take effect. A saved copy would freeze the list at whatever shipped on
	 * the day it was first edited.
	 *
	 * @return string[]
	 */
	public function removed_terms(): array {
		$stored = get_option( self::OPTION, null );

		return is_array( $stored ) ? array_map( 'strval', (array) ( $stored['removed'] ?? array() ) ) : array();
	}

	/**
	 * Replace the set of switched-off built-in terms.
	 *
	 * Anything that is not a shipped term is discarded: the exclusion list is
	 * meaningless for a term the shop typed itself, which it deletes from its
	 * own textarea instead. Left unfiltered it would also grow without limit
	 * as STARTER changes across versions.
	 *
	 * @param string[] $terms Shipped terms to switch off.
	 */
	public function set_removed_terms( array $terms ): void {
		$clean = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $terms ),
					static fn( string $term ): bool => in_array( $term, self::STARTER, true )
				)
			)
		);

		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$stored['removed'] = $clean;

		update_option( self::OPTION, $stored );
		$this->terms = null;
	}

	/**
	 * Replace the shop's additions.
	 *
	 * @param string[] $terms One per entry.
	 */
	public function set_custom_terms( array $terms ): void {
		$clean = array();

		foreach ( $terms as $term ) {
			$term = trim( (string) $term );

			// A one-character term would match a huge amount of innocent text.
			if ( '' !== $term && mb_strlen( $term ) >= 2 ) {
				$clean[] = $term;
			}
		}

		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$stored['custom'] = array_values( array_unique( $clean ) );

		update_option( self::OPTION, $stored );
		$this->terms = null;
	}

	/**
	 * The shipped terms, for the admin to see what it is starting from.
	 *
	 * @return string[]
	 */
	public static function starter_terms(): array {
		return self::STARTER;
	}

	/**
	 * Check a prompt.
	 *
	 * @param string $prompt The customer's text, exactly as typed.
	 * @return Verdict Blocked with the matching term, or allowed.
	 */
	public function check( string $prompt ): Verdict {
		foreach ( $this->terms() as $term ) {
			if ( LtNormaliser::contains_phrase( $prompt, $term ) ) {
				return Verdict::blocked(
					'blocklist',
					'blocklist:' . LtNormaliser::fold( $term ),
					array( 'copyright_character' => true )
				);
			}
		}

		return Verdict::allowed( 'blocklist' );
	}

	/**
	 * Drop the cached list.
	 */
	public function flush(): void {
		$this->terms = null;
	}
}
