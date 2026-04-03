=== Wonder Payment For WooCommerce ===
Contributors: wonderpayment
Tags: woocommerce, payment gateway, payments
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.0.3
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Wonder Payments in WooCommerce with payment links, webhooks, manual sync, and refunds.

== Description ==

Wonder Payment for WooCommerce lets merchants accept Wonder Payments directly from WooCommerce. The plugin supports gateway setup, payment links, webhook-based payment status updates, manual order synchronization, and full or partial refunds.

== External services ==

This plugin connects to Wonder Payment services to create and manage payment orders, generate payment links, query payment and refund status, and process refunds.

A Wonder merchant account, App ID, and related credentials are required to use the gateway.

Depending on the feature used, the plugin may send the following data to Wonder:

- Merchant configuration data such as App ID and environment when testing the connection or configuring the gateway.
- Merchant setup and onboarding data such as login session tokens, business IDs, business names, and related account profile data when the store owner uses the setup wizard to connect a Wonder account and choose a business.
- Order data such as order reference number, amount, currency, due date, line items or labels, callback URL, and return URL when creating or updating a Wonder order.
- Payment and refund data such as Wonder order number, transaction UUID, refund amount, and refund reason when querying status or sending a refund request.

Wonder may send webhook requests back to the store callback URL when order or payment status changes. Those requests can include order numbers, reference numbers, payment state, paid total, transaction identifiers, and related order details.

The plugin uses the following Wonder service endpoints depending on the selected environment:

- Production API: `https://gateway.wonder.today`
- Alpha / test API: `https://gateway-alpha.wonder.app`
- Staging API: `https://gateway-stg.wonder.today`
- Production merchant setup service: `https://main.bindo.co`
- Alpha merchant setup service: `https://main-alpha.bindo.co`
- Staging merchant setup service: `https://main-stg.bindo.co`

The setup wizard also calls the following external QR code rendering service while the merchant setup modal is open:

- QR Code API: `https://api.qrserver.com`

When QR code rendering is used, the plugin sends the short Wonder login URL generated for the current setup session to QR Server so the QR image can be displayed to the merchant. No customer, order, or payment data is sent to QR Server.

Related documentation and policies:

- Open API documentation: `https://developer.wonder.today/api/api_references/open-api`
- Terms and Conditions: `https://wonder.app/terms-conditions`
- Privacy Policy: `https://wonder.app/privacy-policy`
- QR Server privacy policy: `https://goqr.me/privacy-safety-security/`
- QR Server terms of use: `https://goqr.me/de/rechtliches/nutzungsbedingungen-api.html`

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/wonder-payment-for-woocommerce` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce > Settings > Payments
4. Find "Wonder Payments" and click "Manage"
5. Follow the setup wizard to configure your payment gateway

== Changelog ==

= 1.0.3 =
* Fix WordPress text domain alignment for plugin review checks
* Update readme contributors and external services disclosure for WordPress.org review
* Move admin inline scripts and styles into enqueued assets
* Remove deprecated OpenSSL cleanup calls and tighten webhook validation

= 1.0.2 =
* Initial release
