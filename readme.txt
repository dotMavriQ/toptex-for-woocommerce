=== TopTex for WooCommerce ===
Contributors: dotmavriq
Tags: woocommerce, garments, wholesale, import, apparel, toptex
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import the TopTex wholesale garment catalog into WooCommerce as variable products.

== Description ==

TopTex for WooCommerce connects your WooCommerce store to the TopTex wholesale garment catalog. Each TopTex style is imported as a WooCommerce **variable product** with **Color** and **Size** attributes, so your customers can pick exactly the variant they want.

**Features**

* One-click import of the full TopTex catalog.
* Each style becomes a variable product with Color and Size attributes.
* The full color x size matrix is imported, including per-color SKUs, EANs, and prices.
* Wholesale prices imported and a configurable markup applied automatically.
* Product images, descriptions, brands, and categories mirrored from TopTex.
* Automatic background sync on a schedule you choose (hourly to weekly, or manual).
* Idempotent imports: re-running never duplicates products; existing products are updated in place.
* Clean, translatable, WordPress.org-compliant code (GPL, unminified, Settings API).

**How it works**

The plugin reads the public TopTex product catalog and turns it into WooCommerce products. It stores a reference to each TopTex style so future syncs update products rather than duplicating them.

> **Note on data source.** This plugin uses the public TopTex catalog, which exposes the complete style x color x size matrix with per-variant SKUs, EANs, and wholesale prices. Live stock levels are dealer-specific and require a TopTex dealer account (available in a future release).

== Installation ==

1. Upload the `toptex-for-woocommerce` folder to the `/wp-content/plugins/` directory, or install through the Plugins screen.
2. Activate the plugin through the 'Plugins' screen.
3. Ensure WooCommerce is installed and active.
4. Go to **WooCommerce → TopTex** to configure markup, price list, and sync schedule.
5. Click **Run import now** to pull in the catalog.

== Frequently Asked Questions ==

= Does this require a TopTex account? =

No account is needed for the basic catalog import. Live dealer stock and dealer-specific pricing may require a TopTex dealer account in a future release.

= Will re-running the import duplicate my products? =

No. Each product is matched by its TopTex style reference, so re-runs update existing products in place.

= Can I control the selling price? =

Yes. The wholesale price is imported and a configurable percentage markup is applied to form the selling price.

== Changelog ==

= 1.0.0 =
* Initial release.

== Privacy ==

This plugin makes remote network requests to the TopTex catalog service (`toptex.fr` and its content delivery network `cdn.toptex.com`) in order to fetch product data and images. No customer or visitor data is transmitted to third parties.
