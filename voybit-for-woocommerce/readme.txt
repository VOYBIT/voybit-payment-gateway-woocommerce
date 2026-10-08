=== Voybit for WooCommerce ===
Contributors: voybit
Tags: woocommerce, payments, checkout
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Voybit crypto payments in WooCommerce. Customers pay on the Voybit page.

== Description ==

Voybit for WooCommerce adds a payment method to WooCommerce. The customer pays on the Voybit page. The order stays unpaid until Voybit sends a signed notice that the payment is paid or overpaid.

The API key and webhook secret are entered in WooCommerce and stay on the store. They are not sent to the browser.

A payment sends the order total, currency, order number, and a short description to Voybit at api.voybit.com. The order total is the amount of the asset you select. A USD store should use a stablecoin such as USDT. Use of the service is covered by these pages:

* https://voybit.com/terms
* https://voybit.com/docs
* https://voybit.com/privacy

WooCommerce is a trademark of Automattic Inc. This plugin is not affiliated with Automattic.

== Installation ==

1. Copy the `voybit-for-woocommerce` folder into `wp-content/plugins`, or zip that folder and upload it from Plugins, Add New, Upload Plugin.
2. Activate Voybit for WooCommerce.
3. Install and activate WooCommerce 8.3 or newer. Voybit can remain active while WooCommerce is being installed.
4. Open WooCommerce, Settings, Payments, Voybit.
5. Paste the API key, webhook secret, and asset ID from the Voybit dashboard. Leave a secret blank to keep the saved value.
6. Copy the webhook URL and the return URL shown on that page into the same gateway in the Voybit dashboard. Both must use HTTPS.
7. Select Enable Voybit and save the settings.

The store address in WordPress must use HTTPS. Until the three values are saved, Voybit stays hidden at checkout.

== Frequently Asked Questions ==

= WordPress says required plugins are missing or inactive. =

Install version 1.0.1 or newer. It can be activated before WooCommerce and shows a setup notice instead of blocking activation. WooCommerce still has to be active before the Voybit settings and payment method are available.

= Where do I enter the API key? =

WooCommerce, Settings, Payments, Voybit. Create the gateway and the secret key in the Voybit dashboard first. The key starts with `vb_live_` and the webhook secret starts with `whsec_`.

= I saved the page and the secret fields were empty. Did I erase them? =

No. An empty secret field keeps the value already saved.

= The customer came back and the order is still unpaid. =

That is expected. The return page does not mark the order paid. Voybit marks it paid only after a signed webhook says paid or overpaid.

= Which asset should I use? =

The order total is charged as that asset. For a USD store, choose a stablecoin such as USDT. Do not choose a coin whose price moves if the store currency should match the amount.

= Checkout does not show Voybit. =

Confirm the method is enabled, the store address uses HTTPS, and the API key, webhook secret, and asset ID are saved. Classic checkout and the checkout block both use these settings.

= Who do I contact if this does not match my store? =

Contact Voybit support at https://voybit.com/contact.

== Changelog ==

= 1.0.1 =
* Allow activation before WooCommerce is installed and show the required setup steps in WordPress.

= 1.0.0 =
* First release.
