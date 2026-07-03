# WordPress.org Fix Plan

## Goal

Track the remaining WordPress.org review risks for `wonder-payment-for-woocommerce`, record the local audit setup, and define the minimum safe remediation set for the next upload.

## Tooling configured locally

- `php` installed via Homebrew: `8.5.5`
- `composer` installed via Homebrew: `2.9.7`
- Global PHPCS stack installed under `~/.composer`:
  - `squizlabs/php_codesniffer 3.13.5`
  - `wp-coding-standards/wpcs 3.3.0`
  - `phpcompatibility/phpcompatibility-wp 2.1.8`

## Automated audit snapshot

### PHPCompatibilityWP

- Result: no PHP 7.4+ compatibility errors detected.

### WordPress-Extra / targeted WPCS

- The full `WordPress-Extra` scan is dominated by formatting noise in `includes/Wonder_Payments_Admin.php`.
- The targeted review-relevant findings were:
  - `includes/Wonder_Payments_Admin.php` filename does not follow WordPress class file naming expectations.
  - `includes/class-wonder-payments-blocks-support.php` class/file name mismatch.
  - `includes/class-wonder-payments-gateway.php` class/file name mismatch.

## Already fixed

- Plugin URI no longer points to `localhost`.
- Plugin name and readme wording were corrected from `Wooemmerce` to `WooCommerce`.
- Text domain was aligned with the plugin slug: `wonder-payment-for-woocommerce`.
- Plugin version and stable tag were updated to `1.0.3`.
- `readme.txt` contributors were changed to the real WordPress.org username `wonderpayment`.
- `readme.txt` now documents Wonder external services, QR login domains, QR rendering, and policy links.
- `Requires Plugins: woocommerce` was added to the plugin header.
- Deprecated `openssl_pkey_free()` usage was removed from plugin code and the bundled SDK.
- Admin-side inline `<script>` / `<style>` blocks were moved out of PHP templates and into registered admin assets.
- Webhook action handling now uses a whitelist.
- `echo json_encode(...)` was replaced with WordPress JSON helpers.
- The remote gateway icon was replaced with a local asset.

## Implemented in this pass

### Harden webhook request URI normalization

- File: `includes/class-wonder-payments-gateway.php`
- Supporting helper: `wonderpay-gateway-for-woocommerce.php`
- Reason: WordPress.org explicitly flagged `$_SERVER['REQUEST_URI']` because it was only unslashed and stripped of control characters before being passed into `PaymentSDK::generateSignatureMessage()`.
- Implemented change:
  - normalize `REQUEST_URI` to a strict path-plus-query form,
  - reject empty, malformed, absolute, or overlong values,
  - validate the request method before signature generation,
  - keep the path/query shape required by signature verification.

### Remove `dev-main` Composer metadata from the release artifact

- Files:
  - `composer.json`
  - `vendor/wonderpayment/sdk/src/PaymentSDK.php`
  - removed: `vendor/autoload.php`, `vendor/composer/*`
- Implemented change:
  - the plugin now loads the bundled SDK source directly,
  - root Composer metadata no longer references `wonderpayment/sdk: dev-main`,
  - Composer-generated release metadata that exposed `dev-main` is no longer shipped.

### Replace direct log writes with `WP_Filesystem`

- File: `wonderpay-gateway-for-woocommerce.php`
- Implemented change:
  - log cleanup now writes files through `WP_Filesystem->put_contents()`,
  - direct `file_put_contents()` calls were removed.

## Still open before the next upload

### 1. Remove `.idea` from the plugin directory before packaging

- Reason: the current working directory still contains `.idea/`.
- Required release step:
  - do not include `.idea/` in the WordPress.org upload zip,
  - keep `.idea/` ignored locally so it does not reappear in release zips.

## Likely next improvement

### Replace remote QR rendering with a local generator

- File: `assets/js/wonder_payments_admin.js`
- Reason: the plugin still constructs a remote image URL for `https://api.qrserver.com/...`.
- Current state:
  - this service is now disclosed in `readme.txt`,
  - disclosure lowers review risk but does not remove the remote dependency itself.
- Recommendation:
  - switch to a bundled local QR generator when practical,
  - treat this as the next cleanup item if WordPress.org raises the remote service again.

## Low-priority cleanup

- Rename `includes/Wonder_Payments_Admin.php` to a WordPress-style class filename and update the include path.
- Consider aligning the long class names with file naming rules if future WPCS compliance becomes important.
- Reformat `includes/Wonder_Payments_Admin.php` if a cleaner WPCS baseline is needed later.

## Submission workflow for local development

1. Run the local PHPCS checks again after code changes.
2. Test setup, QR login, business selection, key generation, payment creation, webhook handling, and refund flows on a clean WooCommerce install with `WP_DEBUG=true`.
3. Build the upload zip from a plugin directory that does not contain `.idea`.
4. Upload the refreshed zip on the WordPress.org review page.
5. Reply briefly to the review thread and state that the latest version has been uploaded.
