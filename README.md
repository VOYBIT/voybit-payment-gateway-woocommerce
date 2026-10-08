# Voybit payment gateway for WooCommerce

## Get an API key

1. Create an account at [dashboard.voybit.com](https://dashboard.voybit.com).
2. Open **Gateways**, create a payment gateway, enable it, and choose every asset customers may use.
3. Open **API keys**, choose **Create secret key**, and bind it to that gateway. Copy the full `vb_live_…` value once.

The API key stays in WooCommerce and is not sent to the browser. The plugin configures the webhook and customer return URL automatically and stores the rotated signing secret securely.

## Install

WordPress 6.5 or newer, PHP 7.4 or newer, and WooCommerce 8.3 or newer. The store address must use HTTPS. Not published to WordPress.org yet.

1. Download [voybit-for-woocommerce.zip](https://github.com/VOYBIT/voybit-payment-gateway-woocommerce/releases/download/v1.1.0/voybit-for-woocommerce.zip).
2. In WordPress, open **Plugins → Add New → Upload Plugin**, choose that zip, and install it.
3. Activate **Voybit for WooCommerce**. Version 1.1.0 can remain active while WooCommerce is being installed.
4. Install and activate WooCommerce 8.3 or newer.
5. Open **WooCommerce → Settings → Payments → Voybit**.
6. Paste the API key. No asset ID or webhook secret is required.
7. Select **Enable Voybit** and save the settings. The plugin registers its webhook and return URL automatically.

Both addresses have to be HTTPS. The webhook path is `/wp-json/voybit/v1/webhook`. The return path is `/?wc-api=voybit_return`.

The order total is sent in the store currency. Voybit shows only assets enabled on that gateway; the customer chooses one and confirms the live crypto quote before the address and QR are created.

Placing the order opens Voybit checkout. The order stays unpaid until a signed webhook says `paid` or `overpaid`. A repeated delivery is ignored. The return page does not mark the order paid.

## Support

If the plugin does not install, or a payment does not complete as described here, contact Voybit support at [voybit.com/contact](https://voybit.com/contact).
