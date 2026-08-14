<?php
/**
 * Catalog importer: Algolia records -> WooCommerce variable products.
 *
 * The importer works in two phases driven by the Algolia client:
 *
 *   1. List every style reference.
 *   2. For each style, fetch its color variants (full color x size matrix)
 *      and build one WooCommerce variable product with Color + Size
 *      attributes and one variation per color x size combination.
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
	 * Product meta key mapping a TopTex reference back to a WooCommerce product.
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

		$color_attr = $this->ensure_attribute( __( 'Color', 'toptex-woocommerce' ), 'color' );
		$size_attr  = $this->ensure_attribute( __( 'Size', 'toptex-woocommerce' ), 'size' );

		foreach ( $client->list_styles() as $reference ) {
			$variants = $client->get_color_variants( $reference );

			if ( empty( $variants ) ) {
				++$stats['errors'];
				$this->log( 'error', sprintf( 'No color variants for reference %s.', $reference ) );
				continue;
			}

			$result = $this->import_style( $reference, $variants, $settings, $color_attr, $size_attr );

			if ( true === $result ) {
				++$stats['imported'];
			} elseif ( 'updated' === $result ) {
				++$stats['updated'];
			} else {
				++$stats['errors'];
				$this->log( 'error', isset( $result['message'] ) ? $result['message'] : __( 'Unknown import error.', 'toptex-woocommerce' ) );
			}

			// Give the process headroom during very large imports.
			if ( 0 === ( $stats['imported'] + $stats['updated'] ) % 25 ) {
				set_time_limit( 30 );
			}
		}

		update_option(
			'toptex_last_sync',
			array(
				'time'   => time(),
				'result' => $stats,
			)
		);

		$this->log( 'info', sprintf( 'Sync complete. Imported %d, updated %d, errors %d.', $stats['imported'], $stats['updated'], $stats['errors'] ) );

		return $stats;
	}

	/**
	 * Imports a single style (and all its color variants) as a variable product.
	 *
	 * @param string $reference  Style reference.
	 * @param array  $variants   Color-variant records.
	 * @param array  $settings   Plugin options.
	 * @param int    $color_attr Color attribute id.
	 * @param int    $size_attr  Size attribute id.
	 * @return true|string|array True (imported), 'updated', or error array.
	 */
	private function import_style( $reference, $variants, $settings, $color_attr, $size_attr ) {
		$first = $variants[0];

		$name        = isset( $first['designation_marketing'] ) ? trim( (string) $first['designation_marketing'] ) : $reference;
		$description = isset( $first['description_marketing'] ) ? (string) $first['description_marketing'] : '';
		$brand       = isset( $first['marque'] ) ? (string) $first['marque'] : '';

		$status = ( 'yes' === $settings['auto_publish'] ) ? 'publish' : 'draft';

		$product_id = $this->find_by_reference( $reference );

		if ( ! $product_id ) {
			$product_id = wp_insert_post(
				array(
					'post_type'    => 'product',
					'post_status'  => $status,
					'post_title'   => $name,
					'post_content' => $description,
					'post_excerpt' => $this->build_short_description( $first ),
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

		$categories = $this->resolve_category_ids( $first, $settings );
		if ( ! empty( $categories ) ) {
			wp_set_object_terms( $product_id, $categories, 'product_cat', false );
		}

		if ( '' !== $brand ) {
			wp_set_object_terms( $product_id, $brand, 'product_tag', true );
		}

		// Collect the union of colors and sizes across all variants.
		$colors = $this->collect_colors( $variants );
		$sizes  = $this->collect_sizes( $variants );

		$this->set_attribute_on_product( $product_id, $color_attr, $colors );
		$this->set_attribute_on_product( $product_id, $size_attr, $sizes );

		update_post_meta( $product_id, '_sku', $this->make_sku( $reference, '', $settings ) );
		$this->write_attribute_metadata( $product_id, $color_attr, $size_attr, $colors, $sizes );

		// Import the full color x size variation matrix.
		$this->import_variations( $product_id, $reference, $variants, $settings, $color_attr, $size_attr );

		// Derive the product-level base price from the cheapest variant.
		$base_price = $this->min_variant_price( $product_id );
		if ( $base_price > 0 ) {
			update_post_meta( $product_id, '_price', (string) $base_price );
			update_post_meta( $product_id, '_regular_price', (string) $base_price );
		}

		if ( 'yes' === $settings['import_images'] ) {
			$this->import_images( $product_id, $first );
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
	 * Finds the product id for a TopTex reference.
	 *
	 * @param string $reference Style reference.
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
	 * Collects the union of color names across variants.
	 *
	 * @param array $variants Color-variant records.
	 * @return string[] Color names.
	 */
	private function collect_colors( $variants ) {
		$colors = array();

		foreach ( $variants as $variant ) {
			if ( isset( $variant['couleur']['color_name'] ) && '' !== trim( (string) $variant['couleur']['color_name'] ) ) {
				$colors[] = (string) $variant['couleur']['color_name'];
			}
		}

		return array_values( array_unique( array_filter( $colors ) ) );
	}

	/**
	 * Collects the union of size labels across variants.
	 *
	 * @param array $variants Color-variant records.
	 * @return string[] Size labels.
	 */
	private function collect_sizes( $variants ) {
		$sizes  = array();
		$fields = array( 'taille_france', 'taille', 'taille_allemagne', 'taille_espagne', 'taille_italie', 'taille_royaumeuni' );

		foreach ( $variants as $variant ) {
			$avail = isset( $variant['produit_available_sizes'] ) ? (array) $variant['produit_available_sizes'] : array();
			foreach ( $avail as $size ) {
				foreach ( $fields as $field ) {
					if ( isset( $size[ $field ] ) && '' !== trim( (string) $size[ $field ] ) ) {
						$sizes[] = (string) $size[ $field ];
						break;
					}
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
	 * Imports the color x size variation matrix.
	 *
	 * @param int    $product_id Parent product id.
	 * @param string $reference  Style reference.
	 * @param array  $variants   Color-variant records.
	 * @param array  $settings   Options.
	 * @param int    $color_attr Color attribute id.
	 * @param int    $size_attr  Size attribute id.
	 * @return void
	 */
	private function import_variations( $product_id, $reference, $variants, $settings, $color_attr, $size_attr ) {
		$color_tax = wc_attribute_taxonomy_name_by_id( $color_attr );
		$size_tax  = wc_attribute_taxonomy_name_by_id( $size_attr );

		$color_key = 'pa_' . str_replace( 'pa_', '', $color_tax );
		$size_key  = 'pa_' . str_replace( 'pa_', '', $size_tax );

		$price_field = ( 'it' === $settings['price_country'] ) ? 'prix_unitaire_vrac_catalogue_italie' : 'prix_unitaire_vrac_catalogue';
		$markup      = isset( $settings['markup_percent'] ) ? (float) $settings['markup_percent'] : 0;

		foreach ( $variants as $variant ) {
			$color_name = isset( $variant['couleur']['color_name'] ) ? (string) $variant['couleur']['color_name'] : '';

			if ( '' === $color_name ) {
				continue;
			}

			$color_term = get_term_by( 'slug', $this->term_slug( $color_name ), $color_tax );
			if ( ! $color_term ) {
				continue;
			}
			$color_term_id = (int) $color_term->term_id;

			$sizes = isset( $variant['produit_available_sizes'] ) ? (array) $variant['produit_available_sizes'] : array();

			foreach ( $sizes as $size ) {
				$size_name = $this->primary_size( $size );

				if ( '' === $size_name ) {
					continue;
				}

				$size_term = get_term_by( 'slug', $this->term_slug( $size_name ), $size_tax );
				if ( ! $size_term ) {
					continue;
				}
				$size_term_id = (int) $size_term->term_id;

				$sku             = isset( $size['sku'] ) ? (string) $size['sku'] : '';
				$ean             = isset( $size['ean'] ) ? (string) $size['ean'] : '';
				$wholesale       = isset( $size[ $price_field ] ) ? (float) $size[ $price_field ] : 0;
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

				// The public catalog does not carry stock; mark in stock for now.
				update_post_meta( $variation_id, '_manage_stock', 'no' );
				update_post_meta( $variation_id, '_stock_status', 'instock' );
			}
		}
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
	 * @param string $reference Style reference.
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
	 * Returns the primary size label for a size record.
	 *
	 * @param array $size Size record.
	 * @return string
	 */
	private function primary_size( $size ) {
		$order = array( 'taille_france', 'taille', 'taille_allemagne', 'taille_espagne', 'taille_italie', 'taille_royaumeuni' );

		foreach ( $order as $field ) {
			if ( isset( $size[ $field ] ) && '' !== trim( (string) $size[ $field ] ) ) {
				return (string) $size[ $field ];
			}
		}

		return '';
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
	 * @param array $record   Algolia record.
	 * @param array $settings Options.
	 * @return int[] Category term ids.
	 */
	private function resolve_category_ids( $record, $settings ) {
		if ( ! empty( $settings['catalog_category_id'] ) ) {
			return array( (int) $settings['catalog_category_id'] );
		}

		$ids    = array();
		$family = isset( $record['famille'] ) ? trim( (string) $record['famille'] ) : '';
		$sub    = isset( $record['sous_famille'] ) ? trim( (string) $record['sous_famille'] ) : '';

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
	 * @param array $record Algolia record.
	 * @return string
	 */
	private function build_short_description( $record ) {
		$parts = array();

		foreach ( array( 'arguments_vente', 'arguments_vente_2', 'arguments_vente_3' ) as $key ) {
			if ( isset( $record[ $key ] ) && '' !== trim( (string) $record[ $key ] ) ) {
				$parts[] = trim( (string) $record[ $key ] );
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
	 * @param array $record     Algolia record (first color variant).
	 * @return void
	 */
	private function import_images( $product_id, $record ) {
		$cdns = array(
			'https://cdn.toptex.com/pictures/',
			'https://cdn.toptex.com/packshots/',
		);

		$files = array();

		foreach ( array( 'images', 'packshots' ) as $group ) {
			if ( isset( $record[ $group ] ) && is_array( $record[ $group ] ) ) {
				foreach ( $record[ $group ] as $img ) {
					if ( isset( $img['picture_url'] ) ) {
						$files[] = ltrim( (string) $img['picture_url'], '/' );
					}
				}
			}
		}

		// Build unique candidate URLs across both CDN roots.
		$urls = array();
		foreach ( $files as $file ) {
			foreach ( $cdns as $cdn ) {
				$urls[] = $cdn . $file;
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
