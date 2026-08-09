<?php
/**
 * Finding a picture that is licensed to be printed and sold.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Providers\Search;

use AiCake\Support\HttpClient;
use AiCake\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Image search against **Openverse** (D-067).
 *
 * Ruslan asked for a way to find a picture on the internet rather than generate
 * one. The obvious reading of that — an image search over the open web — is a
 * licensing problem rather than a technical one: this shop **prints the picture
 * and sells it**, and a general search API licenses its results for display,
 * not for commercial reproduction. That concern is D-060.
 *
 * Openverse answers it. It is WordPress.org's own service, it aggregates
 * openly-licensed work, and — the part that matters — it can be asked for
 * **only those licences that permit commercial use and modification**. That is
 * exactly what a shop printing a decoration on an icing sheet is doing, and it
 * turns "somebody else's copyrighted work" into "work whose licence says yes".
 *
 * It is not free of obligation: most Creative Commons licences require
 * attribution, and that is a decision for Ruslan rather than a default this
 * code should invent. The licence and the creator come back with every result
 * and are stored on the design, so whatever he decides can be honoured without
 * having to go and find them again.
 *
 * No API key. Anonymous use is rate limited, which for a shop of this size is
 * not a constraint — and registering for a key later changes one header.
 */
final class OpenverseProvider {

	/**
	 * The search endpoint.
	 *
	 * Versioned in the path, so a breaking change arrives as a 404 we can see
	 * rather than as results that quietly mean something else.
	 */
	private const ENDPOINT = 'https://api.openverse.org/v1/images/';

	/**
	 * How many results to offer.
	 *
	 * Enough to choose from, few enough to look at on a phone without
	 * scrolling past the point of deciding.
	 */
	private const PER_PAGE = 12;

	private HttpClient $http;

	private Logger $logger;

	/**
	 * @param HttpClient $http   Transport.
	 * @param Logger     $logger Logging.
	 */
	public function __construct( HttpClient $http, Logger $logger ) {
		$this->http   = $http;
		$this->logger = $logger;
	}

	/**
	 * Search.
	 *
	 * @param string $query_en What to look for, in English.
	 * @return array<int, array<string, mixed>> Results, possibly empty.
	 */
	public function search( string $query_en ): array {
		$response = $this->http->request(
			'GET',
			add_query_arg(
				array(
					'q'         => $query_en,
					'page_size' => self::PER_PAGE,
					/*
					 * The whole licensing argument, expressed as a filter.
					 * `commercial` because the shop sells the result;
					 * `modification` because it is cropped, masked and printed
					 * with text over it. Asking for both means a result that
					 * cannot legally be used never reaches the customer to be
					 * chosen — which is a far better place to enforce this than
					 * a warning nobody reads.
					 */
					'license_type' => 'commercial,modification',
					// Bitmaps only. An SVG result would be refused later by the
					// upload boundary anyway; not asking for one is cheaper.
					'extension'    => 'jpg,png',
					'mature'       => 'false',
				),
				self::ENDPOINT
			),
			array(
				'timeout' => 12,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( ! $response->ok() ) {
			$this->logger->warning( 'Image search failed.', array( 'detail' => $response->describe() ) );

			return array();
		}

		$body = $response->json();

		if ( ! is_array( $body ) || ! isset( $body['results'] ) || ! is_array( $body['results'] ) ) {
			return array();
		}

		$results = array();

		foreach ( $body['results'] as $item ) {
			$found = $this->shape( is_array( $item ) ? $item : array() );

			if ( null !== $found ) {
				$results[] = $found;
			}
		}

		return $results;
	}

	/**
	 * One result by its id.
	 *
	 * **This is what makes the pick safe.** The browser sends back an id, never
	 * a URL, and the URL that actually gets fetched comes from here — from
	 * Openverse's own answer about that id. A client-supplied URL would make
	 * the shop's server fetch whatever it was told to, including addresses on
	 * its own network that nothing else can reach.
	 *
	 * @param string $id Openverse identifier.
	 * @return array<string, mixed>|null
	 */
	public function find( string $id ): ?array {
		if ( '' === $id || ! preg_match( '/^[a-zA-Z0-9-]{6,64}$/', $id ) ) {
			return null;
		}

		$response = $this->http->request(
			'GET',
			self::ENDPOINT . rawurlencode( $id ) . '/',
			array(
				'timeout' => 12,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( ! $response->ok() ) {
			return null;
		}

		$body = $response->json();

		return is_array( $body ) ? $this->shape( $body ) : null;
	}

	/**
	 * Fetch the picture itself.
	 *
	 * @param string $url    Absolute URL, from `find()` and never from a client.
	 * @param int    $cap    Largest acceptable response, in bytes.
	 * @return string Raw bytes, or '' on failure.
	 */
	public function fetch( string $url, int $cap ): string {
		/*
		 * https only. An http URL would be fetched over the open network and
		 * is trivially substitutable by anyone between here and there — and
		 * the thing being substituted is bytes this server is about to decode.
		 */
		if ( ! str_starts_with( $url, 'https://' ) ) {
			return '';
		}

		$response = $this->http->request(
			'GET',
			$url,
			array(
				'timeout'   => 20,
				'max_bytes' => $cap,
			)
		);

		if ( ! $response->ok() ) {
			$this->logger->warning( 'Could not fetch a found picture.', array( 'detail' => $response->describe() ) );

			return '';
		}

		return (string) $response->body;
	}

	/**
	 * One API item, reduced to what the wizard and the design row need.
	 *
	 * Nothing is passed through unchanged. Everything here reaches a browser or
	 * a database column, and an upstream that started returning a longer field
	 * or a different shape should produce a smaller result rather than an
	 * unbounded one.
	 *
	 * @param array<string, mixed> $item Raw API item.
	 * @return array<string, mixed>|null
	 */
	private function shape( array $item ): ?array {
		$id  = (string) ( $item['id'] ?? '' );
		$url = (string) ( $item['url'] ?? '' );

		if ( '' === $id || '' === $url ) {
			return null;
		}

		return array(
			'id'      => mb_substr( $id, 0, 64 ),
			'url'     => $url,
			// The API's own small rendition. Falls back to the full picture,
			// which is correct but heavier — better a slow grid than an empty
			// one.
			'thumb'   => (string) ( $item['thumbnail'] ?? $url ),
			'title'   => mb_substr( (string) ( $item['title'] ?? '' ), 0, 120 ),
			'creator' => mb_substr( (string) ( $item['creator'] ?? '' ), 0, 120 ),
			'licence' => mb_substr( strtoupper( (string) ( $item['license'] ?? '' ) ), 0, 24 ),
			'source'  => mb_substr( (string) ( $item['source'] ?? '' ), 0, 40 ),
		);
	}
}
