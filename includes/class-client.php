<?php
/**
 * Thin HTTP client for the TopTex Algolia search index.
 *
 * The TopTex storefront exposes a search-only Algolia application/key inside
 * its own JavaScript. We use the public search index as the catalog source:
 *
 *   - Application id: RPN83XBV2P
 *   - Search-only key: 1a0a64df969cbff9044aae5de0c5d0e8 (public, read-only)
 *   - Index: index_fr_fr
 *
 * The index is configured with `attributeForDistinct: reference_catalogue`, so
 * ordinary searches collapse to one color per style. Passing `distinct: false`
 * exposes every style x color record, each with its own complete
 * sizes/SKU/EAN/price list.
 *
 * We enumerate the catalog in two phases so we never hit Algolia's 4000-hit
 * pagination ceiling:
 *
 *   1. Empty query with `distinct: true` to list every style reference.
 *   2. For each style, a `filters: reference_catalogue:"X"` query with
 *      `distinct: false` to retrieve all its color variants at once.
 *
 * Only the query endpoint is permitted with this key (no browse).
 *
 * @package TopTex_WooCommerce
 */

namespace TopTexWooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Algolia client.
 */
class Client {

	/**
	 * Algolia application id.
	 */
	const APP_ID = 'RPN83XBV2P';

	/**
	 * Algolia search-only API key (public, read-only).
	 */
	const API_KEY = '1a0a64df969cbff9044aae5de0c5d0e8';

	/**
	 * Algolia index name.
	 */
	const INDEX_NAME = 'index_fr_fr';

	/**
	 * Records per page when enumerating the catalog.
	 */
	const HITS_PER_PAGE = 100;

	/**
	 * Algolia distributed-search-network hosts.
	 */
	const HOSTS = array(
		'RPN83XBV2P-1.algolianet.com',
		'RPN83XBV2P-2.algolianet.com',
		'RPN83XBV2P-3.algolianet.com',
	);

	/**
	 * Runs a query against the index.
	 *
	 * @param string $query      Search query.
	 * @param int    $page       Zero-based page number.
	 * @param array  $parameters Query parameters merged over defaults.
	 * @return array|\WP_Error Decoded response, or WP_Error on failure.
	 */
	public function client_query( $query, $page, array $parameters = array() ) {
		$payload = array_merge(
			array(
				'query'                => (string) $query,
				'page'                 => max( 0, (int) $page ),
				'hitsPerPage'          => self::HITS_PER_PAGE,
				'distinct'             => true,
				'getRankingInfo'       => false,
				'analytics'            => false,
				'enableABTest'         => false,
				'attributesToRetrieve' => array( '*' ),
			),
			$parameters
		);

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			return new \WP_Error( 'toptex_json_encode', __( 'Could not encode the request payload.', 'toptex-woocommerce' ) );
		}

		$host = $this->select_host();
		$url  = sprintf( 'https://%s/1/indexes/%s/query', $host, self::INDEX_NAME );

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 5,
				'headers'     => array(
					'Content-Type'             => 'application/json; charset=utf-8',
					'X-Algolia-API-Key'        => self::API_KEY,
					'X-Algolia-Application-Id' => self::APP_ID,
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( 200 !== $code ) {
			$message = isset( $data['message'] ) ? $data['message'] : __( 'Unknown Algolia error.', 'toptex-woocommerce' );
			return new \WP_Error( 'toptex_algolia_' . $code, $message );
		}

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'toptex_algolia_decode', __( 'Unexpected response from the catalog service.', 'toptex-woocommerce' ) );
		}

		return $data;
	}

	/**
	 * Enumerates every style reference (deduplicated by `reference_catalogue`).
	 *
	 * A `distinct: true` empty query collapses the index to one record per
	 * style (~2976 records, safely under Algolia's 4000-hit pagination limit).
	 *
	 * @return \Generator<int, string> Yields each style reference string.
	 */
	public function list_styles() {
		$page = 0;

		do {
			$data = $this->client_query( '', $page );
			++$page;

			if ( is_wp_error( $data ) ) {
				yield from array();
				return;
			}

			$total_hits = isset( $data['nbHits'] ) ? (int) $data['nbHits'] : 0;
			$hits       = isset( $data['hits'] ) ? (array) $data['hits'] : array();

			foreach ( $hits as $hit ) {
				if ( isset( $hit['reference_catalogue'] ) && '' !== trim( (string) $hit['reference_catalogue'] ) ) {
					yield trim( (string) $hit['reference_catalogue'] );
				}
			}

			$fetched = $page * self::HITS_PER_PAGE;

			if ( empty( $hits ) || $fetched >= $total_hits ) {
				break;
			}
		} while ( true );
	}

	/**
	 * Fetches all color variants for a single style.
	 *
	 * Uses `filters` on the `filterOnly(reference_catalogue)` facet plus
	 * `distinct: false` to retrieve every style x color record with its own
	 * size/SKU/EAN/price list. A style has at most a few dozen colors, so this
	 * is always a single page well under the pagination limit.
	 *
	 * @param string $reference Style reference (e.g. "IB323").
	 * @return array[] List of color-variant records.
	 */
	public function get_color_variants( $reference ) {
		$data = $this->client_query(
			'',
			0,
			array(
				'filters'     => sprintf( 'reference_catalogue:"%s"', addcslashes( $reference, '"\\' ) ),
				'distinct'    => false,
				'hitsPerPage' => 1000,
			)
		);

		if ( is_wp_error( $data ) ) {
			return array();
		}

		return isset( $data['hits'] ) ? (array) $data['hits'] : array();
	}

	/**
	 * Picks a host, round-robin, to spread requests across the DSN.
	 *
	 * @return string A hostname.
	 */
	protected function select_host() {
		$index = get_transient( 'toptex_algolia_host_index' );

		if ( false === $index ) {
			$index = 0;
		}

		$host = self::HOSTS[ $index % count( self::HOSTS ) ];

		set_transient( 'toptex_algolia_host_index', ( $index + 1 ) % count( self::HOSTS ), DAY_IN_SECONDS );

		return $host;
	}
}
