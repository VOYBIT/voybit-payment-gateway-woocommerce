=== Voybit Payment Gateway for WooCommerce ===
Contributors: voybit
Tags: woocommerce, payments, checkout
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.2.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce payment gateway for Voybit crypto checkout with a gateway-bound API key.

== Description ==

Voybit Payment Gateway for WooCommerce adds Voybit as a payment method. The plugin sends the order total to Voybit in US dollars. A store priced in US dollars sends that total unchanged. Iranian rial (IRR), toman (IRT), and any other store currency are converted to US dollars at the current market rate when the customer opens checkout. The WooCommerce order stays in the store currency. On the hosted payment page, the customer chooses from assets enabled on the merchant's Voybit gateway, confirms a live quote, and then receives the payment address and QR.

Only a gateway-bound Voybit API key is entered in WooCommerce. The plugin registers its HTTPS webhook and return URL automatically and stores the rotated webhook signing secret on the store. Neither secret is sent to the browser.

= How to get the API key =

1. Create a merchant account or sign in at https://dashboard.voybit.com/.
2. Open Gateways at https://dashboard.voybit.com/?view=gateways and choose New gateway.
3. Name the store, select every asset customers may use, keep Gateway status active, and save.
4. Open API keys at https://dashboard.voybit.com/?view=keys and choose Create secret key.
5. Select the gateway from step 2, create the key, and copy the full value. It starts with `vb_live_` and is shown only once.
6. In WordPress open WooCommerce, Settings, Payments, Voybit Payment Gateway. Paste the key, select Enable Voybit, and save.

Screenshots 1–8 show the dashboard steps: name the gateway, choose the assets, leave the webhook and return addresses empty, save it Live, then create a gateway-bound API key.

The store address in WordPress must use HTTPS. Until the API key is saved and automatic setup succeeds, the payment gateway stays hidden at checkout. You do not enter a webhook secret or an asset ID.

A checkout session sends the US dollar total, order number, and a short description to Voybit at api.voybit.com. For Iranian rial and toman totals, this store requests the public USDT/rial price from https://apiv2.nobitex.ir and does not send the order. For every other non-USD currency, this store requests public US dollar rates from https://open.er-api.com and does not send the order. Use of Voybit is covered by these pages:

* https://voybit.com/terms
* https://voybit.com/docs
* https://voybit.com/privacy

WooCommerce is a trademark of Automattic Inc. This plugin is not affiliated with Automattic.

== Installation ==

1. Install and activate WooCommerce 8.3 or newer.
2. Copy the `voybit-for-woocommerce` folder into `wp-content/plugins`, or zip that folder and upload it from Plugins, Add New, Upload Plugin.
3. Activate Voybit Payment Gateway for WooCommerce.
4. Open WooCommerce, Settings, Payments, Voybit Payment Gateway.
5. Follow the guide to open the Voybit dashboard, create an active gateway, and create a secret API key bound to that gateway. The key starts with `vb_live_`.
6. Paste the API key, select Enable Voybit, and save. The plugin registers its webhook and return URL automatically.

The store address in WordPress must use HTTPS. Until the API key is saved and automatic setup succeeds, the payment gateway stays hidden at checkout.

== Frequently Asked Questions ==

= WordPress says required plugins are missing or inactive. =

Install and activate WooCommerce 8.3 or newer first. WordPress uses the plugin dependency header to keep this payment gateway inactive until WooCommerce is available.

= Where do I enter the API key? =

WooCommerce, Settings, Payments, Voybit Payment Gateway. Create the gateway and a gateway-bound secret key in the Voybit dashboard first. The key starts with `vb_live_`. The plugin obtains and stores its webhook signing secret automatically.

= Where do I enter the webhook secret or asset ID? =

You do not enter either value. The plugin configures its signed webhook automatically, and the customer chooses from assets enabled on the gateway linked to the API key.

= The customer came back and the order is still unpaid. =

That is expected. The return page does not mark the order paid. Voybit marks it paid only after a signed webhook says paid or overpaid.

= Which assets can a customer use? =

The hosted checkout lists only payment-ready assets enabled on the gateway in the Voybit dashboard. The customer selects one and confirms the current conversion quote before payment instructions are created.

= Checkout does not show Voybit. =

Confirm the payment gateway is enabled, the store address uses HTTPS, the API key is bound to an enabled gateway, and saving the settings completed without an integration setup notice. Classic checkout and the checkout block both use these settings.

= My store is not priced in US dollars. =

Voybit checkout opens in US dollars. USD totals are sent as entered. IRR and IRT totals use the current public USDT/rial price. One toman is 10 rials. Every other currency uses the current public US dollar exchange rate. The rate is refreshed about every 10 minutes and can still be used for up to an hour if the rate service is briefly unavailable. The WooCommerce order remains in the store currency.

= Prices are entered in toman, but WooCommerce currency is IRR. =

Open WooCommerce, Settings, Payments, Voybit Payment Gateway, and set Iranian price unit to tomans. Automatic treats IRR as rials and IRT as tomans.

= Who do I contact if this does not match my store? =

Contact Voybit support at https://voybit.com/contact.

== Screenshots ==

1. Name the WooCommerce store on the gateway basics step.
2. Select every asset customers may use.
3. Leave the webhook URL and the customer return URL empty, and keep the gateway Active. The plugin registers both addresses after the API key is saved.
4. Review the gateway and create it. Do not copy a webhook secret into WooCommerce.
5. The saved gateway stays Live. Webhook and customer return stay empty until the plugin finishes setup.
6. Open API keys and choose Create secret key.
7. Name the key and select the WooCommerce gateway.
8. Copy the full key when it is shown. It starts with vb_live_ and appears only once. The value in this picture is an example.

== Changelog ==

= 1.2.3 =
* Convert Iranian rial, toman, and any other non-USD store total to US dollars at the current market rate when opening Voybit checkout.

= 1.2.2 =
* Keep the plugin directory text in English so Persian can be translated on translate.wordpress.org.

= 1.2.1 =
* Name the plugin Voybit Payment Gateway for WooCommerce.
* Explain in English and Persian how to create the gateway-bound API key.
* Add Persian translations for the setup guide, payment settings, and checkout messages.

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
