# Voybit payment gateway for WooCommerce

## Get a gateway-bound API key

1. Create a merchant account or sign in at the [Voybit dashboard](https://dashboard.voybit.com/).
2. Open [Gateways](https://dashboard.voybit.com/?view=gateways) and choose **New gateway**.
3. Name the store, select every asset customers may use, keep **Gateway status** active, and save.
4. Open [API keys](https://dashboard.voybit.com/?view=keys) and choose **Create secret key**.
5. Select the gateway from step 3, create the key, and copy the full `vb_live_…` value. The full value is shown once.

The API key stays in WooCommerce and is not sent to the browser. The plugin configures the webhook and customer return URL automatically and stores the rotated signing secret securely.

## Install

WordPress 6.5 or newer, PHP 7.4 or newer, and WooCommerce 8.3 or newer. The store address must use HTTPS. Not published to WordPress.org yet.

1. Install and activate WooCommerce 8.3 or newer.
2. Download [voybit-for-woocommerce.zip](https://github.com/VOYBIT/voybit-payment-gateway-woocommerce/releases/download/v1.2.0/voybit-for-woocommerce.zip).
3. In WordPress, open **Plugins → Add New → Upload Plugin**, choose that zip, and install it.
4. Activate **Voybit for WooCommerce**.
5. Open **WooCommerce → Settings → Payments → Voybit** and follow the illustrated setup guide.
6. Paste the gateway-bound API key, select **Enable Voybit**, and save. No asset ID or webhook secret is required.

The plugin registers its webhook and customer return URL automatically. Both addresses have to be HTTPS. The webhook path is `/wp-json/voybit/v1/webhook`. The return path is `/?wc-api=voybit_return`.

The order total is sent in the store currency. Voybit shows only assets enabled on that gateway; the customer chooses one and confirms the live crypto quote before the address and QR are created.

Placing the order opens Voybit checkout. The order stays unpaid until a signed webhook says `paid` or `overpaid`. A repeated delivery is ignored. The return page does not mark the order paid.

## Support

If the plugin does not install, or a payment does not complete as described here, contact Voybit support at [voybit.com/contact](https://voybit.com/contact).
