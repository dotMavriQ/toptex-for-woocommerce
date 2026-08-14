# TopTex for WooCommerce

Import the TopTex wholesale garment catalog into WooCommerce as variable
products with full color and size variations — from the official TopTex API.

## What it does

Each TopTex **style** (identified by a `catalogReference` such as `B610`) becomes
a WooCommerce variable product with **Color** and **Size** attributes. The
importer builds the complete color × size matrix, including per-variation SKUs,
EAN/GTIN codes, live dealer prices and stock.

| TopTex concept        | WooCommerce mapping                 |
| --------------------- | ----------------------------------- |
| Style (`catalogReference`) | Variable product                |
| Color                 | `Color` attribute (global taxonomy) |
| Size                  | `Size` attribute (global taxonomy)  |
| Color × size (SKU)    | Product variation (SKU, EAN, price) |
| `family` / `sub_family` | Product category / sub-category   |
| `brand`               | Product tag                         |
| `images` / `packshots` | Featured image + gallery          |

## Features

- Talks to the **official TopTex v3 API** (`https://api.toptex.io`), not a
  third-party index.
- **Import all or part** of the catalog: full catalog, a selected list of
  `catalogReference`s, or just the first N products (handy for testing).
- Full color × size variation matrix with distinct SKUs, EANs, and prices.
- **Live dealer pricing** (tiered quantity) and **stock** imported alongside.
- **Deleted/discontinued sizes** reconciled automatically (marked out of stock).
- Configurable markup, language (7 languages), and usage-right.
- Product images, descriptions, brands, and categories mirrored automatically.
- Scheduled background sync (hourly to weekly, or manual only).
- Idempotent imports: re-running updates products in place, never duplicates.
- Audit log of every sync run.
- A copyable, secret-free **diagnostic report** and a **test connection** button
  to surface API/auth problems from the WordPress backend.

## Requirements

- WordPress 5.8+
- PHP 7.4+
- WooCommerce 8.0+
- TopTex partner API credentials (key + username + password)

## Installation

1. Upload the plugin to `/wp-content/plugins/`, or build it as a ZIP and install
   through **Plugins → Add New → Upload Plugin**.
2. Activate through the **Plugins** screen.
3. Go to **WooCommerce → TopTex** and enter your **API key**, **username** and
   **password** under *TopTex API connection*.
4. Configure scope, markup, language, and schedule.
5. Click **Run import now**.

## Configuration

All settings live under **WooCommerce → TopTex**:

| Setting             | Description                                                  |
| ------------------- | ------------------------------------------------------------ |
| API key             | Your partner API key from `portal.toptex.io`.                |
| API username/password | Credentials used to obtain the OIDC token.                 |
| Usage right         | `b2b_b2c`, `b2b_uniquement`, or `b2c_uniquement`.           |
| Language            | Language for imported names/descriptions (7 supported).      |
| Import scope        | Full catalog, selected references, or first N products.      |
| Catalog references  | Comma-separated references (for "selected" scope).           |
| Price markup (%)    | Percentage added to the dealer price.                        |
| Import images       | Download and attach product images from the TopTex CDN.      |
| Import into category | Optional single destination category (defaults to families). |
| Product status      | Publish immediately or import as drafts.                     |
| Automatic sync      | Schedule (hourly/twice-daily/daily/weekly/manual).           |
| SKU suffix          | Optional suffix appended to every imported SKU.              |

The settings page also has a **Test connection** button and a **Diagnostic
report** block (under *Synchronization*) so you can verify credentials and copy
a secret-free report when something goes wrong.

## Data source

The plugin reads the official TopTex partner API. This exposes the complete
style × color × size matrix plus **live dealer pricing and stock** (per
warehouse), which are not available in the public storefront index.

Authentication is two-step: the API key is sent as `X-Api-Key`, and a short-lived
OIDC token (obtained via `POST /v3/authenticate`) is sent as
`X-Toptex-Authorization`. The token is cached and auto-refreshed.

## Development

```bash
composer install
composer phpcs          # run WordPress coding-standards checks
composer phpcbf         # auto-fix fixable issues
```

A local WordPress + WooCommerce sandbox is provided for testing:

```bash
docker compose up -d
docker compose exec cli wp core install --url=http://localhost:8081 \
  --title="TopTex Test" --admin_user=admin --admin_password=admin \
  --admin_email=admin@example.test --skip-email
docker compose exec cli wp plugin install woocommerce --activate
docker compose exec cli wp plugin activate toptex-for-woocommerce
```

## License

[GPL-2.0-or-later](LICENSE)
