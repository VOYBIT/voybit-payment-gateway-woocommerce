=== Voybit for WooCommerce ===
Contributors: voybit
Tags: woocommerce, payments, checkout
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Voybit crypto payments in WooCommerce with buyer-choice hosted checkout.

== Description ==

Voybit for WooCommerce adds a payment method to WooCommerce. The plugin sends the fixed order total and currency to Voybit. On the hosted payment page, the customer chooses from assets enabled on the merchant's Voybit gateway, confirms a live quote, and then receives the payment address and QR.

Only a gateway-bound Voybit API key is entered in WooCommerce. The plugin registers its HTTPS webhook and return URL automatically and stores the rotated webhook signing secret on the store. Neither secret is sent to the browser.

A checkout session sends the order total, currency, order number, and a short description to Voybit at api.voybit.com. Use of the service is covered by these pages:

* https://voybit.com/terms
* https://voybit.com/docs
* https://voybit.com/privacy

WooCommerce is a trademark of Automattic Inc. This plugin is not affiliated with Automattic.

== Installation ==

1. Install and activate WooCommerce 8.3 or newer.
2. Copy the `voybit-for-woocommerce` folder into `wp-content/plugins`, or zip that folder and upload it from Plugins, Add New, Upload Plugin.
3. Activate Voybit for WooCommerce.
4. Open WooCommerce, Settings, Payments, Voybit.
5. Follow the illustrated guide to open the Voybit dashboard, create an active gateway, and create a secret API key bound to that gateway.
6. Paste the API key, select Enable Voybit, and save. The plugin registers its webhook and return URL automatically.

The store address in WordPress must use HTTPS. Until the API key is saved and automatic setup succeeds, Voybit stays hidden at checkout.

== Frequently Asked Questions ==

= WordPress says required plugins are missing or inactive. =

Install and activate WooCommerce 8.3 or newer first. WordPress uses the plugin dependency header to keep Voybit inactive until WooCommerce is available.

= Where do I enter the API key? =

WooCommerce, Settings, Payments, Voybit. Create the gateway and a gateway-bound secret key in the Voybit dashboard first. The key starts with `vb_live_`. The plugin obtains and stores its webhook signing secret automatically.

= Where do I enter the webhook secret or asset ID? =

You do not enter either value. The plugin configures its signed webhook automatically, and the customer chooses from assets enabled on the gateway linked to the API key.

= The customer came back and the order is still unpaid. =

That is expected. The return page does not mark the order paid. Voybit marks it paid only after a signed webhook says paid or overpaid.

= Which assets can a customer use? =

The hosted checkout lists only payment-ready assets enabled on the gateway in the Voybit dashboard. The customer selects one and confirms the current conversion quote before payment instructions are created.

= Checkout does not show Voybit. =

Confirm the method is enabled, the store address uses HTTPS, the API key is bound to an enabled gateway, and saving the settings completed without an integration setup notice. Classic checkout and the checkout block both use these settings.

= Who do I contact if this does not match my store? =

Contact Voybit support at https://voybit.com/contact.

== Changelog ==

= 1.2.0 =
* Declare WooCommerce as a required plugin through the WordPress dependency header.
* Keep setup messages inside the Voybit payment settings screen instead of the wider administration area.
* Add an illustrated, linked guide for creating a Voybit account, gateway, and gateway-bound API key.

= 1.1.1 =
* Keep the plugin header, package version, and WordPress.org stable tag aligned.
* Refresh the WordPress.org instructions for automatic webhook setup and buyer-choice hosted checkout.

= 1.1.0 =
* Let customers choose from the gateway's enabled assets on Voybit hosted checkout.
* Configure the signed webhook and return URL automatically; remove manual asset ID and webhook secret fields.

= 1.0.2 =
* Keep development-only tests out of the install archive for a clean WordPress Plugin Check.

= 1.0.1 =
* Allow activation before WooCommerce is installed and show the required setup steps in WordPress.

= 1.0.0 =
* First release.
