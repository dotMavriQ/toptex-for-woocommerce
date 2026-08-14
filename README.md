# TopTex for WooCommerce

Import the TopTex wholesale garment catalog into WooCommerce as variable
products with full color and size variations.

## What it does

Each TopTex style becomes a WooCommerce variable product with **Color** and
**Size** attributes. The importer builds the complete color x size matrix,
including per-variant SKUs, EAN/GTIN codes, and wholesale prices, then applies a
configurable markup to form the selling price.

| TopTex concept   | WooCommerce mapping                     |
| ---------------- | --------------------------------------- |
| Style            | Variable product                        |
| Color            | `Color` attribute (global taxonomy)     |
| Size             | `Size` attribute (global taxonomy)      |
| Color x size     | Product variation (SKU, EAN, price)     |
| `famille`        | Product category                        |
| `sous_famille`   | Product sub-category                    |
| `marque`         | Product tag                             |
| `images`/`packshots` | Featured image + gallery             |

## Features

- One-click catalog import from the public TopTex data source.
- Full color x size variation matrix with distinct SKUs, EANs, and prices.
- Configurable price markup and price list (France / Italy).
- Product images, descriptions, brands, and categories mirrored automatically.
- Scheduled background sync (hourly to weekly, or manual only).
- Idempotent imports: re-running updates products in place, never duplicates.
- Audit log of every sync run.

## Requirements

- WordPress 5.8+
- PHP 7.4+
- WooCommerce 8.0+

## Installation

1. Upload the plugin to `/wp-content/plugins/`, or build it as a ZIP and install
   through **Plugins → Add New → Upload Plugin**.
2. Activate through the **Plugins** screen.
3. Go to **WooCommerce → TopTex** to configure markup, price list, and schedule.
4. Click **Run import now**.

## Configuration

All settings live under **WooCommerce → TopTex**:

| Setting            | Description                                                  |
| ------------------ | ------------------------------------------------------------ |
| Price markup (%)   | Percentage added to the wholesale price.                     |
| Price list         | France or Italy wholesale price list.                        |
| Import images      | Download and attach product images from the TopTex CDN.      |
| Import into category | Optional single destination category (defaults to families). |
| Product status     | Publish immediately or import as drafts.                     |
| Automatic sync     | Schedule (hourly/twice-daily/daily/weekly/manual).           |
| SKU suffix         | Optional suffix appended to every imported SKU.              |

## Data source

The plugin reads the public TopTex catalog. This exposes the complete
style x color x size matrix, including per-variant SKUs, EANs, and wholesale
prices.

Live stock levels are dealer-specific and are not part of the public catalog;
they require a TopTex dealer account.

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
