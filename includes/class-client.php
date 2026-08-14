<?php
/**
 * HTTP client for the TopTex v3 API.
 *
 * TopTex exposes a partner catalog behind an AWS API Gateway:
 *
 *   - Base URL:    https://api.toptex.io
 *   - API key:     passed as the `X-Api-Key` header.
 *   - Auth:        `POST /v3/authenticate` with `{username, password}` returns
 *                  a Cognito OIDC token (JWT). The token is passed as the
 *                  `X-Toptex-Authorization` header and expires (~1h).
 *
 * The catalog itself is a paginated listing at `/v3/products/all` where each
 * entry nests `colors[]` -> `sizes[]` (SKU/EAN/price/stock). Pricing and stock
 * are dealer-specific and come from `/v3/products/price` and
 * `/v3/products/inventory` respectively.
 *
 * @package TopTex_WooCommerce
 */

namespace TopTexWooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * TopTex API client.
 */
class Client {

	/**
	 * Base URL of the TopTex API.
	 */
	const BASE_URL = 'https://api.toptex.io';

	/**
	 * Valid usage-right values accepted by the API.
	 */
	const USAGE_RIGHTS = array( 'b2b_uniquement', 'b2c_uniquement', 'b2b_b2c' );

	/**
	 * API key (dealer-specific).
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * The most recent error, captured for diagnostics.
	 *
	 * @var array|null
	 */
	private $last_error = null;

	/**
	 * Constructor.
	 *
	 * @param string $api_key TopTex API key (optional; falls back to settings).
	 */
	public function __construct( $api_key = '' ) {
		$this->api_key = (string) $api_key;
		if ( '' === $this->api_key && class_exists( Settings::class ) ) {
			$settings      = Settings::instance()->get_options();
			$this->api_key = isset( $settings['api_key'] ) ? (string) $settings['api_key'] : '';
		}
	}

	/**
	 * Returns the most recent error captured by this client instance.
	 *
	 * @return array|null { code, message, endpoint, http_code } or null.
	 */
	public function get_last_error() {
		return $this->last_error;
	}

	/**
	 * Makes an authenticated GET request.
	 *
	 * @param string $path  Path relative to the base URL (e.g. "/v3/products/all").
	 * @param array  $query Query-string parameters.
	 * @param array  $args  Extra wp_remote_get args (merged last).
	 * @return array|\WP_Error Decoded JSON, or WP_Error.
	 */
	public function get( $path, array $query = array(), array $args = array() ) {
		$url = self::BASE_URL . '/' . ltrim( $path, '/' );

		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$defaults = array(
			'timeout'     => 30,
			'redirection' => 5,
			'headers'     => $this->auth_headers(),
		);

		$response = wp_remote_get( $url, array_merge( $defaults, $args ) );
		return $this->decode_response( $response );
	}

	/**
	 * Makes an authenticated POST request.
	 *
	 * @param string $path Path relative to the base URL.
	 * @param array  $body Body (encoded as JSON).
	 * @param array  $args Extra wp_remote_post args (merged last).
	 * @return array|\WP_Error Decoded JSON, or WP_Error.
	 */
	public function post( $path, array $body = array(), array $args = array() ) {
		$url = self::BASE_URL . '/' . ltrim( $path, '/' );

		$defaults = array(
			'timeout'     => 30,
			'redirection' => 5,
			'headers'     => array_merge(
				$this->auth_headers(),
				array( 'Content-Type' => 'application/json; charset=utf-8' )
			),
			'body'        => wp_json_encode( $body ),
		);

		$response = wp_remote_post( $url, array_merge( $defaults, $args ) );
		return $this->decode_response( $response );
	}

	/**
	 * Builds the required auth headers (API key + OIDC token when available).
	 *
	 * @return array Header map.
	 */
	private function auth_headers() {
		$headers = array(
			'Accept'    => 'application/json',
			'X-Api-Key' => $this->api_key,
		);

		$token = $this->get_token();
		if ( '' !== $token ) {
			$headers['X-Toptex-Authorization'] = $token;
		}

		return $headers;
	}

	/**
	 * Decodes an HTTP response into an array or WP_Error.
	 *
	 * @param array|\WP_Error $response wp_remote response.
	 * @return array|\WP_Error
	 */
	private function decode_response( $response ) {
		if ( is_wp_error( $response ) ) {
			$this->last_error = array(
				'code'      => $response->get_error_code(),
				'message'   => $response->get_error_message(),
				'http_code' => 0,
			);
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 400 ) {
			$message = '';

			if ( is_array( $data ) && isset( $data['message'] ) ) {
				$message = $data['message'];
			} elseif ( is_array( $data ) && isset( $data['errorMessage'] ) ) {
				$message = $data['errorMessage'];
			} else {
				$message = __( 'Unknown TopTex API error.', 'toptex-woocommerce' );
			}

			$this->last_error = array(
				'code'      => 'toptex_api_' . $code,
				'message'   => $message,
				'http_code' => $code,
			);

			return new \WP_Error( 'toptex_api_' . $code, $message );
		}

		if ( ! is_array( $data ) ) {
			$this->last_error = array(
				'code'      => 'toptex_api_decode',
				'message'   => __( 'Unexpected response from the TopTex API.', 'toptex-woocommerce' ),
				'http_code' => $code,
			);
			return new \WP_Error( 'toptex_api_decode', __( 'Unexpected response from the TopTex API.', 'toptex-woocommerce' ) );
		}

		return $data;
	}

	/**
	 * Authenticates and caches an OIDC token.
	 *
	 * The token is short-lived (~1h), so we cache it in a transient with a
	 * safety margin and only re-auth once it expires.
	 *
	 * @return string JWT token, or empty string on failure.
	 */
	private function get_token() {
		$cached = get_transient( 'toptex_oidc_token' );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$settings = class_exists( Settings::class ) ? Settings::instance()->get_options() : array();
		$username = isset( $settings['api_username'] ) ? $settings['api_username'] : '';
		$password = isset( $settings['api_password'] ) ? $settings['api_password'] : '';

		if ( '' === $username || '' === $password ) {
			$this->last_error = array(
				'code'      => 'toptex_missing_credentials',
				'message'   => __( 'API username or password is not set.', 'toptex-woocommerce' ),
				'http_code' => 0,
			);
			return '';
		}

		// Authenticate without the token header (chicken-and-egg).
		$url  = self::BASE_URL . '/v3/authenticate';
		$body = wp_json_encode(
			array(
				'username' => $username,
				'password' => $password,
			)
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 5,
				'headers'     => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json; charset=utf-8',
					'X-Api-Key'    => $this->api_key,
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->last_error = array(
				'code'      => $response->get_error_code(),
				'message'   => $response->get_error_message(),
				'http_code' => 0,
			);
			return '';
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $data ) || empty( $data['token'] ) ) {
			$message          = ( is_array( $data ) && isset( $data['message'] ) ) ? $data['message'] : __( 'Authentication failed.', 'toptex-woocommerce' );
			$this->last_error = array(
				'code'      => 'toptex_auth_' . $code,
				'message'   => $message,
				'http_code' => $code,
			);
			return '';
		}

		$token = (string) $data['token'];

		// Cache for 50 minutes (token is ~60 min) to leave a safety margin.
		set_transient( 'toptex_oidc_token', $token, 50 * MINUTE_IN_SECONDS );

		return $token;
	}

	/**
	 * Tests connectivity and authentication against the TopTex API.
	 *
	 * Authenticates (if needed) and fetches the first page of the catalog. A
	 * successful result means the credentials are valid and the API is up.
	 *
	 * @return array|\WP_Error { ok, message, total_count } or WP_Error.
	 */
	public function test_connection() {
		$token = $this->get_token();

		if ( '' === $token ) {
			$err = $this->get_last_error();
			if ( $err ) {
				return new \WP_Error( $err['code'], $err['message'] );
			}
			return new \WP_Error( 'toptex_auth_failed', __( 'Authentication failed.', 'toptex-woocommerce' ) );
		}

		$data = $this->get(
			'/v3/products/all',
			array(
				'usage_right' => 'b2b_b2c',
				'page_number' => 1,
				'page_size'   => 1,
			)
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$total = isset( $data['total_count'] ) ? (int) $data['total_count'] : 0;

		return array(
			'ok'          => true,
			'message'     => __( 'Connected to the TopTex API.', 'toptex-woocommerce' ),
			'total_count' => $total,
		);
	}

	/**
	 * Lists every catalog product (one Style per record), paginated.
	 *
	 * @param string $usage_right Usage-right flag.
	 * @param int    $page_size   Page size (max 200).
	 * @return \Generator<int, array> Yields product records.
	 */
	public function list_products( $usage_right = 'b2b_b2c', $page_size = 200 ) {
		$usage_right = $this->normalize_usage_right( $usage_right );
		$page_size   = max( 1, min( 200, (int) $page_size ) );
		$page        = 1;

		do {
			$data = $this->get(
				'/v3/products/all',
				array(
					'usage_right' => $usage_right,
					'page_number' => $page,
					'page_size'   => $page_size,
				)
			);

			if ( is_wp_error( $data ) ) {
				yield from array();
				return;
			}

			$items = isset( $data['items'] ) ? (array) $data['items'] : array();
			$total = isset( $data['total_count'] ) ? (int) $data['total_count'] : 0;

			foreach ( $items as $item ) {
				yield $item;
			}

			++$page;

			if ( empty( $items ) || ( $total > 0 && $page > (int) ceil( $total / $page_size ) ) ) {
				break;
			}
		} while ( true );
	}

	/**
	 * Fetches the price list for a single catalog reference (all SKUs).
	 *
	 * @param string $catalog_reference Style reference (e.g. "B610").
	 * @return array[] Price items (sku, color, size, prices[]).
	 */
	public function get_prices( $catalog_reference ) {
		$data = $this->get(
			'/v3/products/price',
			array( 'catalog_reference' => (string) $catalog_reference )
		);

		if ( is_wp_error( $data ) ) {
			return array();
		}

		return isset( $data['items'] ) ? (array) $data['items'] : array();
	}

	/**
	 * Fetches inventory for a single catalog reference (all SKUs).
	 *
	 * @param string $catalog_reference Style reference.
	 * @return array[] Inventory items (sku, color, size, warehouses[]).
	 */
	public function get_inventory( $catalog_reference ) {
		$data = $this->get(
			'/v3/products/inventory',
			array( 'catalog_reference' => (string) $catalog_reference )
		);

		if ( is_wp_error( $data ) ) {
			return array();
		}

		return isset( $data['items'] ) ? (array) $data['items'] : array();
	}

	/**
	 * Fetches the list of deleted size SKUs.
	 *
	 * @param string $usage_right Usage-right flag.
	 * @return array[] Deleted entries ({catalog_type, catalog_id}).
	 */
	public function list_deleted( $usage_right = 'b2b_b2c' ) {
		$data = $this->get(
			'/v3/products/deleted',
			array( 'usage_right' => $this->normalize_usage_right( $usage_right ) )
		);

		if ( is_wp_error( $data ) ) {
			return array();
		}

		return isset( $data['items'] ) ? (array) $data['items'] : array();
	}

	/**
	 * Normalizes a usage-right flag to a valid value.
	 *
	 * @param string $usage_right Input value.
	 * @return string Valid usage-right.
	 */
	private function normalize_usage_right( $usage_right ) {
		return in_array( (string) $usage_right, self::USAGE_RIGHTS, true )
			? (string) $usage_right
			: 'b2b_b2c';
	}
}
