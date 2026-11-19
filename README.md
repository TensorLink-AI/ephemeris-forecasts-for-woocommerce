# Ephemeris Forecasts for WooCommerce

Demand forecasts and reorder suggestions for every WooCommerce product and variation, from pretrained forecasting models. Nothing is trained on your store's data.

The plugin reads daily units sold from WooCommerce Analytics. It forecasts demand over your supplier lead time plus the days until your next order, then shows how much to order. Results appear under **WooCommerce > Forecasts**, and you can download them as a CSV.

## Install

1. Download `ephemeris-forecasts-for-woocommerce.zip` from the [latest release](https://github.com/TensorLink-AI/ephemeris-forecasts-for-woocommerce/releases/latest). Don't use GitHub's "Download ZIP" button, because it names the folder differently.
2. In WordPress go to **Plugins > Add New > Upload Plugin**, choose the zip, then click **Activate**. WooCommerce must be active.
3. If **WooCommerce > Analytics** shows no orders, import your history under **Analytics > Settings**.
4. Get an API key at [ephemeris.cascade.industries/sign-up](https://ephemeris.cascade.industries/sign-up). New accounts get free credits for about 2,000 forecasts.
5. Paste the key into **WooCommerce > Forecasts > Settings**, set your lead time, then click **Run forecast now**.

Requirements: WordPress 6.5+, WooCommerce 8.2+, PHP 7.4+. Works with High-Performance Order Storage (HPOS).

## Cost

The plugin is free. Ephemeris charges $0.001 per product for every 64 days forecast. A store with 500 products running weekly spends about $2 a month. The Settings page shows the estimate for your store.

## What leaves the store

Only numbers: one list of daily units sold per product, the number of days to forecast and the percentiles requested. The API key and site URL go in the request headers. Product names, SKUs, prices, customers and orders are not sent.

## How the suggestion is worked out

- **Expected demand**: the sum of the daily median forecasts over lead time + days between orders.
- **Cautious demand**: the sum of the daily forecasts at the chosen safety level (80th, 90th or 95th percentile). This is a deliberately high buffer, not an exact probability.
- **Suggested order**: cautious demand minus stock on hand, rounded up, never below 0.

Limitations: products that don't track stock show demand only. Days when a product was out of stock look like days with no demand, so products that often sell out may be under-forecast.

## Testing

```bash
# Unit tests for the maths and API response parsing (no WordPress needed)
php tests/math-test.php
```

To try it against a full store without spending credits:

1. Install WordPress and WooCommerce locally, then activate this plugin.
2. Copy `tests/mock-api-mu-plugin.php` into `wp-content/mu-plugins/`. It intercepts calls to Ephemeris and returns fake forecasts. **Never put it on a live site.**
3. Seed sample products and 400 days of orders: `wp eval-file tests/seed-store.php`
4. Save any key that starts with `pc_live_` in Settings, then click **Run forecast now**, or run `wp action-scheduler run`.

To test against the real API, remove the mock plugin and use a real key.

## Building a release

Push a tag such as `v0.1.1`. The GitHub Action lints the code, runs the tests, builds the zip and attaches it to a release. You can also build locally with `bin/build-zip.sh`.

## Licence

GPLv2 or later. See [LICENSE](LICENSE).
