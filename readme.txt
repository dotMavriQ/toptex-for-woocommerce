=== TopTex for WooCommerce ===
Contributors: dotmavriq
Tags: woocommerce, garments, wholesale, import, apparel, toptex
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import the TopTex wholesale garment catalog into WooCommerce as variable products.

== Description ==

TopTex for WooCommerce connects your WooCommerce store to the TopTex wholesale garment catalog via the official TopTex v3 partner API. Each TopTex style is imported as a WooCommerce **variable product** with **Color** and **Size** attributes, so your customers can pick exactly the variant they want.

**Features**

* Talks to the official TopTex API (`api.toptex.io`), not a third-party index.
* Import **all or part** of the catalog: full catalog, selected references, or the first N products.
* Each style becomes a variable product with Color and Size attributes.
* The full color x size matrix is imported, including per-color SKUs and EANs.
* **Live dealer prices** and **stock** are pulled from the API and a configurable markup is applied.
* Deleted/discontinued sizes are reconciled automatically (marked out of stock).
* Product images, descriptions, brands, and categories mirrored from TopTex.
* Choose the import language (7 languages supported).
* Automatic background sync on a schedule you choose (hourly to weekly, or manual).
* Idempotent imports: re-running never duplicates products; existing products are updated in place.
* A copyable, secret-free diagnostic report and a "test connection" button.
* Clean, translatable, WordPress.org-compliant code (GPL, unminified, Settings API).

**How it works**

The plugin authenticates against the TopTex API (API key + OIDC token) and pulls the catalog, then stores a reference to each TopTex catalog reference so future syncs update products rather than duplicating them.

== Installation ==

1. Upload the `toptex-for-woocommerce` folder to the `/wp-content/plugins/` directory, or install through the Plugins screen.
2. Activate the plugin through the 'Plugins' screen.
3. Ensure WooCommerce is installed and active.
4. Go to **WooCommerce → TopTex** and enter your API key, username, and password.
5. Configure scope, markup, language, and sync schedule.
6. Click **Run import now** to pull in the catalog.

== Frequently Asked Questions ==

= Do I need TopTex API credentials? =

Yes. A partner API key, username, and password are required. You can obtain them from the TopTex developer portal (`portal.toptex.io`).

= Can I import only part of the catalog? =

Yes. Choose "Selected references only" and list the catalog references you want, or "First N products" to import a limited batch for testing.

= Will re-running the import duplicate my products? =

No. Each product is matched by its TopTex catalog reference, so re-runs update existing products in place.

= Does the plugin import stock levels? =

Yes. Live stock (per warehouse) is pulled from the API and applied to each variation.

= What happens when TopTex discontinues a size? =

On a full-catalog sync, deleted/discontinued size SKUs are marked out of stock automatically, so removed variants stop appearing as buyable options.

= Can I control the selling price? =

Yes. The wholesale price is imported and a configurable percentage markup is applied to form the selling price.

== Changelog ==

= 1.1.0 =
* Replaced the Algolia index scraper with the official TopTex v3 partner API.
* Added API key / username / password authentication with automatic OIDC token refresh.
* Added import scope (full catalog, selected references, or first N products).
* Added live dealer pricing and stock import.
* Added language and usage-right configuration.
* Added reconciliation of deleted/discontinued size SKUs.
* Added a copyable diagnostic report and a "test connection" button.

= 1.0.0 =
* Initial release.

== Privacy ==

This plugin makes remote network requests to the TopTex API (`api.toptex.io`) and its content delivery network (`cdn.toptex.com`) in order to fetch product data, prices, stock, and images. No customer or visitor data is transmitted to third parties. Your TopTex API credentials are stored in the WordPress options table.
