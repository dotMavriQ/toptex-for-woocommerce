<?php
/**
 * Catalog importer: TopTex API styles -> WooCommerce variable products.
 *
 * The TopTex API returns each Style (catalog reference) as a record with a
 * nested `colors[] -> sizes[]` matrix. We turn that into one WooCommerce
 * variable product with Color + Size attributes and one variation per
 * color x size combination.
 *
 * Pricing and stock come from the dealer-specific `/v3/products/price` and
 * `/v3/products/inventory` endpoints, keyed by SKU.
 *
 * @package TopTex_WooCommerce
 */

namespace TopTexWooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Imports TopTex styles as WooCommerce variable products.
 */
class Importer {

	/**
	 * Product meta key mapping a TopTex catalog reference back to a product.
	 */
	const REFERENCE_META_KEY = '_toptex_reference';

	/**
	 * Log table name (without the database prefix).
	 */
	const LOG_TABLE = 'toptex_sync_log';

	/**
	 * Boots the importer (singleton).
	 *
	 * @return Importer
	 */
	public static function instance() {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}
		return $instance;
	}

	/**
	 * Creates the sync log table (persists across deactivation).
	 *
	 * @return void
	 */
	public static function create_log_table() {
		global $wpdb;

		$table   = $wpdb->prefix . self::LOG_TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			time DATETIME NOT NULL,
			level VARCHAR(20) NOT NULL,
			message TEXT NOT NULL,
			PRIMARY KEY  (id),
			KEY time (time)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Runs a full catalog import.
	 *
	 * @return array|\WP_Error Summary { imported, updated, errors } or WP_Error.
	 */
	public function run_import() {
		if ( ! class_exists( \WooCommerce::class ) ) {
			return new \WP_Error( 'toptex_no_woocommerce', __( 'WooCommerce is not active.', 'toptex-woocommerce' ) );
		}

		$settings = Settings::instance()->get_options();
		$client   = new Client();

		$stats = array(
			'imported' => 0,
			'updated'  => 0,
			'errors'   => 0,
		);

		// Structured diagnostic info collected for the report block.
		$diagnostic = array(
			'fatal'     => null,
			'failures'  => array(),
			'processed' => 0,
		);

		$color_attr = $this->ensure_attribute( __( 'Color', 'toptex-woocommerce' ), 'color' );
		$size_attr  = $this->ensure_attribute( __( 'Size', 'toptex-woocommerce' ), 'size' );

		foreach ( $this->enumerate_products( $client, $settings ) as $record ) {
			++$diagnostic['processed'];

			$reference = isset( $record['catalogReference'] ) ? (string) $record['catalogReference'] : '';

			if ( '' === $reference ) {
				++$stats['errors'];
				$this->log( 'error', __( 'Skipped a product with no catalog reference.', 'toptex-woocommerce' ) );
				continue;
			}

			// Fetch dealer-specific prices and stock for this style.
			$prices    = $client->get_prices( $reference );
			$inventory = $client->get_inventory( $reference );

			$result = $this->import_style( $record, $settings, $color_attr, $size_attr, $prices, $inventory );

			if ( true === $result ) {
				++$stats['imported'];
			} elseif ( 'updated' === $result ) {
				++$stats['updated'];
			} else {
				++$stats['errors'];
				$message = isset( $result['message'] ) ? $result['message'] : __( 'Unknown import error.', 'toptex-woocommerce' );
				$this->log( 'error', $message );

				if ( count( $diagnostic['failures'] ) < 20 ) {
					$diagnostic['failures'][] = array(
						'reference' => $reference,
						'message'   => $message,
					);
				}
			}

			// Give the process headroom during very large imports.
			if ( 0 === ( $stats['imported'] + $stats['updated'] ) % 25 ) {
				set_time_limit( 30 );
			}
		}

		// No products processed almost always means an auth/connection failure.
		if ( 0 === $diagnostic['processed'] ) {
			$last_error = $client->get_last_error();
			if ( $last_error ) {
				$diagnostic['fatal'] = $last_error;
			} else {
				$diagnostic['fatal'] = array(
					'code'      => 'toptex_empty_catalog',
					'message'   => __( 'The import retrieved no products. Check your API credentials and usage-right setting.', 'toptex-woocommerce' ),
					'http_code' => 0,
				);
			}
		}

		update_option(
			'toptex_last_sync',
			array(
				'time'       => time(),
				'result'     => $stats,
				'diagnostic' => $diagnostic,
			)
		);

		$this->log( 'info', sprintf( 'Sync complete. Imported %d, updated %d, errors %d.', $stats['imported'], $stats['updated'], $stats['errors'] ) );

		return $stats;
	}

	/**
	 * Enumerates the product records to import, honoring the selected scope.
	 *
	 * @param Client $client   API client.
	 * @param array  $settings Plugin options.
	 * @return \Generator<int, array> Yields product records.
	 */
	private function enumerate_products( $client, $settings ) {
		$scope       = isset( $settings['import_scope'] ) ? $settings['import_scope'] : 'all';
		$usage_right = isset( $settings['usage_right'] ) ? $settings['usage_right'] : 'b2b_b2c';
		$page_size   = isset( $settings['per_page'] ) ? max( 1, min( 200, (int) $settings['per_page'] ) ) : 200;

		if ( 'selection' === $scope ) {
			$refs = array_filter( array_map( 'trim', explode( ',', (string) $settings['import_references'] ) ) );
			foreach ( $refs as $ref ) {
				$record = $this->find_product_by_reference( $client, $ref, $usage_right );
				if ( $record ) {
					yield $record;
				} else {
					++$this->selection_misses;
					$this->log( 'error', sprintf( 'Reference %s not found in the catalog.', $ref ) );
				}
			}
			return;
		}

		$count = 0;

		foreach ( $client->list_products( $usage_right, $page_size ) as $record ) {
			if ( 'per_page' === $scope && $count >= $page_size ) {
				break;
			}

			yield $record;
			++$count;
		}
	}

	/**
	 * Scratch counter for missing selected references.
	 *
	 * @var int
	 */
	private $selection_misses = 0;

	/**
	 * Finds a single product record by catalog reference (or SKU).
	 *
	 * @param Client $client       API client.
	 * @param string $reference    Catalog reference or SKU.
	 * @param string $usage_right  Usage-right flag.
	 * @return array|null Product record or null.
	 */
	private function find_product_by_reference( $client, $reference, $usage_right ) {
		$key = strpos( $reference, '_' ) !== false ? 'sku' : 'catalog_reference';

		$data = $client->get(
			'/v3/products',
			array(
				$key          => $reference,
				'usage_right' => $usage_right,
			)
		);

		if ( is_wp_error( $data ) || ! is_array( $data ) ) {
			return null;
		}

		// The search endpoint returns either a single object or a list.
		return isset( $data['catalogReference'] ) ? $data : null;
	}

	/**
	 * Imports a single style (and all its colors/sizes) as a variable product.
	 *
	 * @param array $record    TopTex product record.
	 * @param array $settings  Plugin options.
	 * @param int   $color_attr Color attribute id.
	 * @param int   $size_attr  Size attribute id.
	 * @param array $prices     Dealer price items (by SKU).
	 * @param array $inventory  Dealer inventory items (by SKU).
	 * @return true|string|array True (imported), 'updated', or error array.
	 */
	private function import_style( $record, $settings, $color_attr, $size_attr, $prices, $inventory ) {
		$lang      = isset( $settings['language'] ) ? $settings['language'] : 'en';
		$reference = isset( $record['catalogReference'] ) ? (string) $record['catalogReference'] : '';

		$name        = $this->localized( $record, 'designation', $lang, $reference );
		$description = $this->localized( $record, 'description', $lang, '' );
		$brand       = isset( $record['brand'] ) ? trim( (string) $record['brand'] ) : '';

		$status     = ( 'yes' === $settings['auto_publish'] ) ? 'publish' : 'draft';
		$product_id = $this->find_by_reference( $reference );

		if ( ! $product_id ) {
			$product_id = wp_insert_post(
				array(
					'post_type'    => 'product',
					'post_status'  => $status,
					'post_title'   => $name,
					'post_content' => $description,
					'post_excerpt' => $this->build_short_description( $record, $lang ),
				)
			);

			if ( is_wp_error( $product_id ) || ! $product_id ) {
				return array( 'message' => sprintf( 'Could not create product for %s.', $reference ) );
			}

			update_post_meta( $product_id, self::REFERENCE_META_KEY, $reference );
			wp_set_object_terms( $product_id, 'variable', 'product_type' );

			$result = true;
		} else {
			wp_update_post(
				array(
					'ID'           => $product_id,
					'post_title'   => $name,
					'post_content' => $description,
					'post_status'  => $status,
				)
			);
			$result = 'updated';
		}

		$categories = $this->resolve_category_ids( $record, $settings, $lang );
		if ( ! empty( $categories ) ) {
			wp_set_object_terms( $product_id, $categories, 'product_cat', false );
		}

		if ( '' !== $brand ) {
			wp_set_object_terms( $product_id, $brand, 'product_tag', true );
		}

		// Collect the union of colors and sizes across all variants.
		$colors = $this->collect_colors( $record );
		$sizes  = $this->collect_sizes( $record );

		$this->set_attribute_on_product( $product_id, $color_attr, $colors );
		$this->set_attribute_on_product( $product_id, $size_attr, $sizes );

		update_post_meta( $product_id, '_sku', $this->make_sku( $reference, '', $settings ) );
		$this->write_attribute_metadata( $product_id, $color_attr, $size_attr, $colors, $sizes );

		// Import the full color x size variation matrix.
		$this->import_variations( $product_id, $record, $settings, $color_attr, $size_attr, $prices, $inventory );

		// Derive the product-level base price from the cheapest variant.
		$base_price = $this->min_variant_price( $product_id );
		if ( $base_price > 0 ) {
			update_post_meta( $product_id, '_price', (string) $base_price );
			update_post_meta( $product_id, '_regular_price', (string) $base_price );
		}

		if ( 'yes' === $settings['import_images'] ) {
			$this->import_images( $product_id, $record );
		}

		$product = wc_get_product( $product_id );
		if ( $product ) {
			$product->save();
			$product->sync( true );
			wc_delete_product_transients( $product_id );
		}

		return $result;
	}

	/**
	 * Extracts a localized string from a multilingual field or scalar.
	 *
	 * @param array  $record  Product record.
	 * @param string $key     Field key.
	 * @param string $lang    Language code (de/en/es/fr/it/nl/pt).
	 * @param string $fallback Fallback value.
	 * @return string
	 */
	private function localized( $record, $key, $lang, $fallback = '' ) {
		if ( ! isset( $record[ $key ] ) ) {
			return $fallback;
		}

		$value = $record[ $key ];

		if ( is_array( $value ) ) {
			if ( isset( $value[ $lang ] ) && '' !== trim( (string) $value[ $lang ] ) ) {
				return (string) $value[ $lang ];
			}
			// Fall back to English then any non-empty value.
			if ( isset( $value['en'] ) && '' !== trim( (string) $value['en'] ) ) {
				return (string) $value['en'];
			}
			foreach ( $value as $v ) {
				if ( is_scalar( $v ) && '' !== trim( (string) $v ) ) {
					return (string) $v;
				}
			}
			return $fallback;
		}

		return '' !== trim( (string) $value ) ? (string) $value : $fallback;
	}

	/**
	 * Ensures a global product attribute exists.
	 *
	 * @param string $label Attribute label.
	 * @param string $slug  Attribute slug.
	 * @return int Attribute id (0 on failure).
	 */
	private function ensure_attribute( $label, $slug ) {
		$attribute_id = wc_attribute_taxonomy_id_by_name( $slug );

		if ( ! $attribute_id ) {
			$created = wc_create_attribute(
				array(
					'name'         => $label,
					'slug'         => $slug,
					'type'         => 'select',
					'order_by'     => 'name',
					'has_archives' => false,
				)
			);

			if ( ! is_wp_error( $created ) ) {
				$attribute_id = $created;
			}
		}

		return is_wp_error( $attribute_id ) ? 0 : (int) $attribute_id;
	}

	/**
	 * Finds the product id for a TopTex catalog reference.
	 *
	 * @param string $reference Catalog reference.
	 * @return int Product id or 0.
	 */
	private function find_by_reference( $reference ) {
		$posts = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'meta_key'         => self::REFERENCE_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $reference, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'suppress_filters' => true,
			)
		);

		return empty( $posts ) ? 0 : (int) $posts[0];
	}

	/**
	 * Collects the union of color names across the record's color variants.
	 *
	 * @param array $record Product record.
	 * @return string[] Color names.
	 */
	private function collect_colors( $record ) {
		$colors = array();

		$variants = isset( $record['colors'] ) ? (array) $record['colors'] : array();

		foreach ( $variants as $variant ) {
			if ( isset( $variant['colors'] ) && is_array( $variant['colors'] ) ) {
				// colors is a {lang: name} map; prefer 'en'.
				$name = isset( $variant['colors']['en'] ) ? $variant['colors']['en'] : reset( $variant['colors'] );
				if ( is_scalar( $name ) && '' !== trim( (string) $name ) ) {
					$colors[] = (string) $name;
				}
			}
		}

		return array_values( array_unique( array_filter( $colors ) ) );
	}

	/**
	 * Collects the union of size labels across the record's variants.
	 *
	 * @param array $record Product record.
	 * @return string[] Size labels.
	 */
	private function collect_sizes( $record ) {
		$sizes = array();

		$variants = isset( $record['colors'] ) ? (array) $record['colors'] : array();

		foreach ( $variants as $variant ) {
			$size_list = isset( $variant['sizes'] ) ? (array) $variant['sizes'] : array();
			foreach ( $size_list as $size ) {
				if ( isset( $size['size'] ) && '' !== trim( (string) $size['size'] ) ) {
					$sizes[] = (string) $size['size'];
				}
			}
		}

		// Sort sizes naturally (XS, S, M, L, XL, XXL, ...).
		usort(
			$sizes,
			function ( $a, $b ) {
				return strlen( $a ) === strlen( $b ) ? strcmp( $a, $b ) : strlen( $a ) - strlen( $b );
			}
		);

		return array_values( array_unique( array_filter( $sizes ) ) );
	}

	/**
	 * Attaches a list of terms to a global attribute on the product.
	 *
	 * @param int      $product_id   Product id.
	 * @param int      $attribute_id Attribute id.
	 * @param string[] $terms        Term names.
	 * @return void
	 */
	private function set_attribute_on_product( $product_id, $attribute_id, $terms ) {
		if ( ! $attribute_id || empty( $terms ) ) {
			return;
		}

		$taxonomy = wc_attribute_taxonomy_name_by_id( $attribute_id );
		wp_set_object_terms( $product_id, $terms, $taxonomy, false );
	}

	/**
	 * Builds SKU -> data lookups from price and inventory lists.
	 *
	 * @param array $prices    Price items.
	 * @param array $inventory Inventory items.
	 * @return array Two arrays: [price_by_sku, stock_by_sku].
	 */
	private function index_sku_data( $prices, $inventory ) {
		$price_by_sku = array();
		$stock_by_sku = array();

		foreach ( $prices as $item ) {
			if ( isset( $item['sku'] ) && isset( $item['prices'] ) && is_array( $item['prices'] ) ) {
				// Use the lowest quantity tier's price (quantity 1).
				$best = null;
				foreach ( $item['prices'] as $tier ) {
					if ( isset( $tier['price'] ) ) {
						$p = (float) $tier['price'];
						if ( null === $best || $p < $best ) {
							$best = $p;
						}
					}
				}
				if ( null !== $best ) {
					$price_by_sku[ (string) $item['sku'] ] = $best;
				}
			}
		}

		foreach ( $inventory as $item ) {
			if ( isset( $item['sku'] ) && isset( $item['warehouses'] ) && is_array( $item['warehouses'] ) ) {
				$total = 0;
				foreach ( $item['warehouses'] as $wh ) {
					$total += isset( $wh['stock'] ) ? (int) $wh['stock'] : 0;
				}
				$stock_by_sku[ (string) $item['sku'] ] = $total;
			}
		}

		return array( $price_by_sku, $stock_by_sku );
	}

	/**
	 * Imports the color x size variation matrix.
	 *
	 * @param int   $product_id Parent product id.
	 * @param array $record     Product record.
	 * @param array $settings   Options.
	 * @param int   $color_attr Color attribute id.
	 * @param int   $size_attr  Size attribute id.
	 * @param array $prices     Dealer price items.
	 * @param array $inventory  Dealer inventory items.
	 * @return void
	 */
	private function import_variations( $product_id, $record, $settings, $color_attr, $size_attr, $prices, $inventory ) {
		$color_tax = wc_attribute_taxonomy_name_by_id( $color_attr );
		$size_tax  = wc_attribute_taxonomy_name_by_id( $size_attr );

		$color_key = 'pa_' . str_replace( 'pa_', '', $color_tax );
		$size_key  = 'pa_' . str_replace( 'pa_', '', $size_tax );

		$markup = isset( $settings['markup_percent'] ) ? (float) $settings['markup_percent'] : 0;

		list( $price_by_sku, $stock_by_sku ) = $this->index_sku_data( $prices, $inventory );

		$reference = isset( $record['catalogReference'] ) ? (string) $record['catalogReference'] : '';
		$variants  = isset( $record['colors'] ) ? (array) $record['colors'] : array();

		foreach ( $variants as $variant ) {
			$color_name = $this->color_name( $variant );

			if ( '' === $color_name ) {
				continue;
			}

			$color_term = get_term_by( 'slug', $this->term_slug( $color_name ), $color_tax );
			if ( ! $color_term ) {
				continue;
			}

			$size_list = isset( $variant['sizes'] ) ? (array) $variant['sizes'] : array();

			foreach ( $size_list as $size ) {
				$size_name = isset( $size['size'] ) ? (string) $size['size'] : '';

				if ( '' === $size_name ) {
					continue;
				}

				$size_term = get_term_by( 'slug', $this->term_slug( $size_name ), $size_tax );
				if ( ! $size_term ) {
					continue;
				}

				$sku       = isset( $size['sku'] ) ? (string) $size['sku'] : '';
				$ean       = isset( $size['ean'] ) ? (string) $size['ean'] : '';
				$wholesale = isset( $price_by_sku[ $sku ] ) ? (float) $price_by_sku[ $sku ] : 0.0;
				$stock_qty = isset( $stock_by_sku[ $sku ] ) ? (int) $stock_by_sku[ $sku ] : 0;

				if ( $wholesale <= 0 && isset( $size['publicUnitPrice'] ) ) {
					$wholesale = $this->parse_price( $size['publicUnitPrice'] );
				}

				$variation_price = $wholesale > 0 ? round( $wholesale * ( 1 + $markup / 100 ), 2 ) : 0;

				$variation_id = $this->find_variation( $product_id, $color_key, $size_key, $this->term_slug( $color_name ), $this->term_slug( $size_name ) );

				if ( ! $variation_id ) {
					$variation_id = wp_insert_post(
						array(
							'post_type'   => 'product_variation',
							'post_status' => 'publish',
							'post_parent' => $product_id,
						)
					);
				}

				if ( ! $variation_id || is_wp_error( $variation_id ) ) {
					continue;
				}

				update_post_meta( $variation_id, 'attribute_' . $color_key, $this->term_slug( $color_name ) );
				update_post_meta( $variation_id, 'attribute_' . $size_key, $this->term_slug( $size_name ) );

				update_post_meta( $variation_id, '_sku', $this->make_sku( $reference, $sku, $settings ) );
				update_post_meta( $variation_id, '_regular_price', (string) $variation_price );
				update_post_meta( $variation_id, '_price', (string) $variation_price );

				if ( '' !== $ean ) {
					update_post_meta( $variation_id, '_gtin', $ean );
				}

				// Stock: manage by quantity when we have live inventory data.
				if ( $wholesale > 0 || ! empty( $stock_by_sku ) ) {
					update_post_meta( $variation_id, '_manage_stock', 'yes' );
					update_post_meta( $variation_id, '_stock', (string) $stock_qty );
				} else {
					update_post_meta( $variation_id, '_manage_stock', 'no' );
				}
				update_post_meta( $variation_id, '_stock_status', $stock_qty > 0 ? 'instock' : 'outofstock' );
			}
		}
	}

	/**
	 * Extracts a color name from a color variant.
	 *
	 * @param array $variant Color variant.
	 * @return string Color name.
	 */
	private function color_name( $variant ) {
		if ( isset( $variant['colors'] ) && is_array( $variant['colors'] ) ) {
			$name = isset( $variant['colors']['en'] ) ? $variant['colors']['en'] : reset( $variant['colors'] );
			if ( is_scalar( $name ) ) {
				return (string) $name;
			}
		}

		return '';
	}

	/**
	 * Parses a European-formatted price string ("5,72 €") to float.
	 *
	 * @param string $price Price string.
	 * @return float
	 */
	private function parse_price( $price ) {
		$clean = (string) $price;
		$clean = preg_replace( '/[^0-9,\.\-]/', '', $clean );

		// Handle "5,72" (comma decimal) vs "5.72".
		if ( strpos( $clean, ',' ) !== false && strpos( $clean, '.' ) === false ) {
			$clean = str_replace( ',', '.', $clean );
		} elseif ( strpos( $clean, ',' ) !== false && strpos( $clean, '.' ) !== false ) {
			// "1.234,56" -> thousands sep "." and decimal ",".
			$clean = str_replace( '.', '', $clean );
			$clean = str_replace( ',', '.', $clean );
		}

		return (float) $clean;
	}

	/**
	 * Finds an existing variation by attribute slugs.
	 *
	 * @param int    $product_id Parent product id.
	 * @param string $color_key  Color attribute meta key.
	 * @param string $size_key   Size attribute meta key.
	 * @param string $color_slug Color slug.
	 * @param string $size_slug  Size slug.
	 * @return int Variation id or 0.
	 */
	private function find_variation( $product_id, $color_key, $size_key, $color_slug, $size_slug ) {
		$variations = get_posts(
			array(
				'post_type'        => 'product_variation',
				'post_parent'      => $product_id,
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		foreach ( $variations as $vid ) {
			$c = (string) get_post_meta( $vid, 'attribute_' . $color_key, true );
			$s = (string) get_post_meta( $vid, 'attribute_' . $size_key, true );

			if ( $c === $color_slug && $s === $size_slug ) {
				return (int) $vid;
			}
		}

		return 0;
	}

	/**
	 * Builds a sanitized SKU with an optional suffix.
	 *
	 * @param string $reference Catalog reference.
	 * @param string $base_sku  Base sku (variation-level).
	 * @param array  $settings  Options.
	 * @return string
	 */
	private function make_sku( $reference, $base_sku, $settings ) {
		$sku = ( '' !== $base_sku ) ? $base_sku : $reference;

		if ( ! empty( $settings['append_sku_suffix'] ) ) {
			$sku .= $settings['append_sku_suffix'];
		}

		return sanitize_title( $sku );
	}

	/**
	 * Slugifies a term name.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private function term_slug( $name ) {
		return sanitize_title( $name );
	}

	/**
	 * Resolves product category ids for a record.
	 *
	 * @param array  $record   Product record.
	 * @param array  $settings Options.
	 * @param string $lang     Language code.
	 * @return int[] Category term ids.
	 */
	private function resolve_category_ids( $record, $settings, $lang ) {
		if ( ! empty( $settings['catalog_category_id'] ) ) {
			return array( (int) $settings['catalog_category_id'] );
		}

		$ids    = array();
		$family = $this->localized( $record, 'family', $lang, '' );
		$sub    = $this->localized( $record, 'sub_family', $lang, '' );

		$parent_id = 0;

		if ( '' !== $family ) {
			$term = term_exists( $family, 'product_cat' );
			if ( ! $term ) {
				$term = wp_insert_term( $family, 'product_cat' );
			}
			if ( ! is_wp_error( $term ) ) {
				$parent_id = (int) $term['term_id'];
				$ids[]     = $parent_id;
			}
		}

		if ( '' !== $sub && $parent_id ) {
			$term = term_exists( sanitize_title( $sub ), 'product_cat', $parent_id );
			if ( ! $term ) {
				$term = wp_insert_term( $sub, 'product_cat', array( 'parent' => $parent_id ) );
			}
			if ( ! is_wp_error( $term ) ) {
				$ids[] = (int) $term['term_id'];
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Builds a short description from marketing arguments.
	 *
	 * @param array  $record Product record.
	 * @param string $lang   Language code.
	 * @return string
	 */
	private function build_short_description( $record, $lang ) {
		$parts = array();

		foreach ( array( 'salesArguments', 'salesArguments2', 'salesArguments3' ) as $key ) {
			$value = $this->localized( $record, $key, $lang, '' );
			if ( '' !== $value ) {
				$parts[] = $value;
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * Writes the WooCommerce product attribute metadata object.
	 *
	 * @param int      $product_id Product id.
	 * @param int      $color_attr Color attribute id.
	 * @param int      $size_attr  Size attribute id.
	 * @param string[] $colors     Color names.
	 * @param string[] $sizes      Size labels.
	 * @return void
	 */
	private function write_attribute_metadata( $product_id, $color_attr, $size_attr, $colors, $sizes ) {
		$color_tax = wc_attribute_taxonomy_name_by_id( $color_attr );
		$size_tax  = wc_attribute_taxonomy_name_by_id( $size_attr );

		$color_key = 'pa_' . str_replace( 'pa_', '', $color_tax );
		$size_key  = 'pa_' . str_replace( 'pa_', '', $size_tax );

		$attributes = array();

		if ( ! empty( $colors ) ) {
			$attributes[ $color_key ] = array(
				'name'         => $color_key,
				'value'        => implode( ' | ', $colors ),
				'position'     => 0,
				'is_visible'   => true,
				'is_variation' => true,
				'is_taxonomy'  => true,
			);
		}

		if ( ! empty( $sizes ) ) {
			$attributes[ $size_key ] = array(
				'name'         => $size_key,
				'value'        => implode( ' | ', $sizes ),
				'position'     => 1,
				'is_visible'   => true,
				'is_variation' => true,
				'is_taxonomy'  => true,
			);
		}

		update_post_meta( $product_id, '_product_attributes', $attributes );
	}

	/**
	 * Computes the minimum (non-zero) variation price for the product.
	 *
	 * @param int $product_id Parent product id.
	 * @return float
	 */
	private function min_variant_price( $product_id ) {
		$min = null;

		$variations = get_posts(
			array(
				'post_type'        => 'product_variation',
				'post_parent'      => $product_id,
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		foreach ( $variations as $vid ) {
			$price = (float) get_post_meta( $vid, '_price', true );
			if ( $price > 0 && ( null === $min || $price < $min ) ) {
				$min = $price;
			}
		}

		return null === $min ? 0.0 : $min;
	}

	/**
	 * Downloads and attaches product images.
	 *
	 * @param int   $product_id Product id.
	 * @param array $record     Product record.
	 * @return void
	 */
	private function import_images( $product_id, $record ) {
		$urls = array();

		// Top-level images.
		if ( isset( $record['images'] ) && is_array( $record['images'] ) ) {
			foreach ( $record['images'] as $img ) {
				foreach ( array( 'url_image', 'url_packshot', 'url' ) as $k ) {
					if ( isset( $img[ $k ] ) && '' !== trim( (string) $img[ $k ] ) ) {
						$urls[] = (string) $img[ $k ];
						break;
					}
				}
			}
		}

		// Per-color packshots (FACE/BACK).
		if ( isset( $record['colors'] ) && is_array( $record['colors'] ) ) {
			foreach ( $record['colors'] as $variant ) {
				if ( isset( $variant['packshots'] ) && is_array( $variant['packshots'] ) ) {
					foreach ( $variant['packshots'] as $shot ) {
						if ( isset( $shot['url_packshot'] ) && '' !== trim( (string) $shot['url_packshot'] ) ) {
							$urls[] = (string) $shot['url_packshot'];
						}
					}
				}
			}
		}

		$urls = array_values( array_unique( $urls ) );

		if ( empty( $urls ) ) {
			return;
		}

		$attachment_ids = array();

		foreach ( $urls as $url ) {
			if ( count( $attachment_ids ) >= 12 ) {
				break;
			}

			$attachment_id = $this->sideload_image( $url, $product_id );
			if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
				$attachment_ids[] = (int) $attachment_id;
			}
		}

		if ( empty( $attachment_ids ) ) {
			return;
		}

		set_post_thumbnail( $product_id, $attachment_ids[0] );

		$gallery = array_slice( $attachment_ids, 1 );
		if ( ! empty( $gallery ) ) {
			update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery ) );
		}
	}

	/**
	 * Sideloads an image into the media library.
	 *
	 * @param string $url        Image URL.
	 * @param int    $product_id Parent product id (for context).
	 * @return int|\WP_Error Attachment id or WP_Error.
	 */
	private function sideload_image( $url, $product_id ) {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		return media_sideload_image( $url, $product_id, null, 'id' );
	}

	/**
	 * Writes a log row (and error_log when WP_DEBUG_LOG is on).
	 *
	 * @param string $level   'info' or 'error'.
	 * @param string $message Message.
	 * @return void
	 */
	private function log( $level, $message ) {
		global $wpdb;

		$table = $wpdb->prefix . self::LOG_TABLE;

		$wpdb->insert(
			$table,
			array(
				'time'    => current_time( 'mysql' ),
				'level'   => sanitize_key( $level ),
				'message' => sanitize_textarea_field( $message ),
			),
			array( '%s', '%s', '%s' )
		);

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[TopTex] ' . $level . ': ' . $message );
		}
	}
}
