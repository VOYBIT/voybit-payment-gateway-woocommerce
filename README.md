# Voybit payment gateway for WooCommerce

## Get an API key

1. Create an account at [dashboard.voybit.com](https://dashboard.voybit.com).
2. Open **Gateways** and create a payment gateway. Keep it enabled. Copy the asset ID you will charge, and store the webhook secret (`whsec_…`) shown once at creation.
3. Open **API keys**, choose **Create secret key**, and bind it to that gateway. Copy the full `vb_live_…` value once.

The API key and webhook secret stay in WooCommerce. They are not sent to the browser.

## Install

WordPress 6.5 or newer, PHP 7.4 or newer, and WooCommerce 8.3 or newer. The store address must use HTTPS. Not published to WordPress.org yet.

1. Download [voybit-for-woocommerce.zip](https://github.com/VOYBIT/voybit-payment-gateway-woocommerce/releases/download/v1.0.2/voybit-for-woocommerce.zip).
2. In WordPress, open **Plugins → Add New → Upload Plugin**, choose that zip, and install it.
3. Activate **Voybit for WooCommerce**. Version 1.0.2 can remain active while WooCommerce is being installed.
4. Install and activate WooCommerce 8.3 or newer.
5. Open **WooCommerce → Settings → Payments → Voybit**.
6. Paste the API key, webhook secret, and asset ID. Leave a secret blank on a later save to keep the saved value.
7. Copy the webhook URL and the return URL shown there into the same gateway in the Voybit dashboard.
8. Select **Enable Voybit** and save the settings.

Both addresses have to be HTTPS. The webhook path is `/wp-json/voybit/v1/webhook`. The return path is `/?wc-api=voybit_return`.

The order total is the amount the customer pays, in the store currency. A 25.00 order asks for 25.00 of the selected asset. Use a stablecoin that matches the store currency, such as USDT for a USD store.

Placing the order opens Voybit checkout. The order stays unpaid until a signed webhook says `paid` or `overpaid`. A repeated delivery is ignored. The return page does not mark the order paid.

## Support

If the plugin does not install, or a payment does not complete as described here, contact Voybit support at [voybit.com/contact](https://voybit.com/contact).
