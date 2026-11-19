=== Ephemeris Forecasts for WooCommerce ===
Contributors: [CONFIRM: wordpress.org username]
Tags: inventory, forecasting, reorder, stock, purchase orders
Requires at least: 6.5
Tested up to: 7.2
Requires PHP: 7.4
WC requires at least: 8.2
WC tested up to: 11.3
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Demand forecasts and reorder quantities for every product, from pretrained forecasting models. Nothing is trained on your store's data.

== Description ==

Most reorder tools guess next month from last month's average. That misses trends, seasons and slow weeks.

This plugin reads your daily sales from WooCommerce Analytics and asks the Ephemeris API to forecast each product's demand for the days that matter: your supplier's lead time plus the gap until your next order. Then it tells you how much to order.

**What you get**

* A table under WooCommerce > Forecasts with expected demand, a cautious demand figure, days of stock left and a suggested order for every product and variation.
* A "Needs reorder" filter and a CSV export to paste into a purchase order.
* Weekly or daily runs in the background, or on demand.
* Settings for lead time, days between orders and how cautious to be.

**What are pretrained models?**

They are forecasting models that have already learned how sales, traffic and other series tend to behave from large public collections of time series. Like a weather model, they can forecast a new series straight away from its history. You don't need to train anything, and your data is not used to train them.

Ephemeris routes each product to a suitable model from a panel that includes Amazon Chronos-2, Google TimesFM 2.5 and others.

**Pricing**

The plugin is free. Forecasts are billed by Ephemeris at $0.001 per product per run. New Ephemeris accounts include free credits for about 2,000 forecasts. A store with 500 products running weekly spends about $2 a month. The settings screen shows the estimate for your store.

**How the suggestion is worked out**

* Expected demand: the sum of the daily median forecasts over lead time + days between orders.
* Cautious demand: the sum of the daily forecasts at your chosen safety level (80th, 90th or 95th percentile). This is a deliberately high buffer, not an exact probability.
* Suggested order: cautious demand minus stock on hand, rounded up, never below 0.

Products that don't track stock show demand only. Days when a product was out of stock look like days with no demand, so stockout-heavy products may be under-forecast.

== External services ==

This plugin connects to the Ephemeris forecasting API (https://ephemeris.cascade.industries) to produce forecasts. It does nothing until you add an API key.

* **When:** on each scheduled run (weekly by default, or daily) and when you click "Run forecast now".
* **What is sent:** for each product with enough history, a list of daily units sold (numbers only), the number of days to forecast and the percentiles requested, plus your API key and site URL in the request headers. Product names, SKUs, prices, customer details and order details are not sent.
* **Where:** https://ephemeris.cascade.industries/api/v1/forecast

Ephemeris terms of service: [CONFIRM: URL]
Ephemeris privacy policy: [CONFIRM: URL]

== Installation ==

1. Install and activate the plugin. WooCommerce must be active.
2. If WooCommerce > Analytics shows no data, import your historical orders under Analytics > Settings.
3. Get an API key at https://ephemeris.cascade.industries/sign-up and paste it into WooCommerce > Forecasts > Settings.
4. Set your supplier lead time and how often you order, then click "Run forecast now".

== Frequently Asked Questions ==

= Does it change my stock or create orders? =

No. It only reads sales and stock levels and shows suggestions.

= Does it work with High-Performance Order Storage? =

Yes. It reads the WooCommerce Analytics tables, which work with either order storage.

= Which order statuses count as sales? =

Completed and Processing. Developers can change this with the `ephfc_order_statuses` filter. Refunds are netted off.

= How much history do I need? =

At least 28 days for a product to be included. A year or more lets the models pick up seasonal patterns.

= Is my data used to train AI? =

No. The models are pretrained. Your sales numbers are used only to produce your forecast.

== Changelog ==

= 0.1.0 =
* First release.
