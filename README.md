# mailtonic for WordPress

The official WordPress plugin for [mailtonic](https://mailtonic.in): embed mailtonic forms and popups, send consented Contact Form 7, WPForms and Elementor Pro Forms sign-ups to a list, and sync opted-in WooCommerce customers, orders and abandoned carts.

It is a thin client of mailtonic's public API v2. It contains no tracking, telemetry, licence checks or update checks, and calls nothing but your mailtonic app URL.

The WordPress.org listing text, install steps and the "External services" disclosure are in [`readme.txt`](readme.txt).

## Requirements

- WordPress 6.5 or later, PHP 8.0 or later.
- WooCommerce features: WooCommerce 8.6 or later (custom order tables and the cart and checkout blocks are supported).
- Optional: Contact Form 7, WPForms (Lite or Pro), Elementor and Elementor Pro. Each integration appears only while its plugin is active.

## Install and set up

1. Install the plugin from WordPress.org, or copy this folder to `wp-content/plugins/mailtonic` and activate it. On a multisite network, network activation sets it up on every site; each site connects separately.
2. In mailtonic, create an API key with write access (Settings › API keys).
3. In WordPress, open **mailtonic › Connection**, paste the key and connect, then pick the default list.
   - Or define it in `wp-config.php`: `define( 'MAILTONIC_API_KEY', 'mt_…' );`. `MAILTONIC_APP_URL` overrides the app URL (staging only).
4. Embed forms with the **mailtonic form** block, the Elementor widget or `[mailtonic_form id="<form uid>"]`; pick site-wide popups under **mailtonic › Forms**.
5. Bridge form plugins under **mailtonic › Integrations**, and configure WooCommerce under **mailtonic › WooCommerce**.

Every write to mailtonic goes through the plugin's outbox (sent after the response and every 5 minutes by WP-Cron, retried with backoff). **mailtonic › Sync log** shows each entry and lets you retry failures. If your site disables WP-Cron, run `wp cron event run --due-now` from a system cron every few minutes.

### For developers

- `mailtonic_order_placed_properties` and `mailtonic_cart_abandoned_properties` filter event properties before they are queued (the 20-key, scalar and 255-character rules are applied afterwards).
- `mailtonic_show_popups` (bool) turns site-wide popups off for the current page.
- `mailtonic_integration_active` (bool, slug) overrides integration detection.

## Development

Tests use PHPUnit with the WordPress test suite, against a MySQL container. No test reaches mailtonic: every HTTP request is answered by a fake on `pre_http_request`, and unscripted requests fail.

```sh
composer install
docker compose up -d                  # MySQL 8 on 127.0.0.1:33071 (project "mailtonic-wp")
bin/install-woocommerce.sh            # pinned WooCommerce into .cache/ (optional: without it, WooCommerce tests skip)
vendor/bin/phpunit                    # single site
WP_MULTISITE=1 vendor/bin/phpunit --group multisite
vendor/bin/phpcs                      # WordPress Coding Standards
scripts/brand-guard.sh                # forbidden-name guard
bin/build-zip.sh                      # release zip in build/
```

Database settings can be changed with `WP_DB_HOST`, `WP_DB_NAME`, `WP_DB_USER` and `WP_DB_PASSWORD`; `MAILTONIC_WC_VERSION` picks the WooCommerce version and `MAILTONIC_WC_DIR=""` runs without it.

Contact Form 7, WPForms and Elementor Pro are exercised through fixture stubs (`tests/fixtures/form-plugins.php`) that fire the same after-submit hooks with the same arguments as the real plugins.

### Dev dependencies (exact versions, all GPL-compatible)

| Package | Version | Licence | Why |
|---|---|---|---|
| phpunit/phpunit | 9.6.38 | BSD-3-Clause | Test runner; 9.6 is the newest line the WordPress test suite supports on PHP 8.0. |
| yoast/phpunit-polyfills | 2.0.5 | BSD-3-Clause | Required by the WordPress test suite. |
| wp-phpunit/wp-phpunit | 7.1.3 | GPL-2.0-or-later | The WordPress test suite library. |
| roots/wordpress-no-content | 7.1.4 | GPL-2.0-or-later | WordPress core for the test suite. |
| wp-coding-standards/wpcs | 3.4.1 | MIT | PHPCS with WordPress Coding Standards. |
| dealerdirect/phpcodesniffer-composer-installer | 1.2.1 | MIT | Registers the standards with PHPCS. |

WooCommerce for tests is downloaded from WordPress.org by `bin/install-woocommerce.sh` at a pinned version (11.2.1 locally, 8.6.0 in the minimum-versions CI job). No npm packages are used: the block's editor script is plain JavaScript using WordPress's globals.

## Releases

Releases happen only from CI on a version tag (`v1.2.3`): the workflow checks that the plugin header version, `readme.txt`'s stable tag and the tag agree, builds the zip without tests or dev files, runs Plugin Check on it, and commits it to WordPress.org SVN. Nothing is uploaded by hand.

## Licence and trademark

The code is licensed under the GNU General Public License v2 or later (see [`LICENSE`](LICENSE)).

The mailtonic name and logo are trademarks of mailtonic and are not covered by the GPL. Forks must not use them in a way that suggests they are the official plugin.

Security issues: see [`SECURITY.md`](SECURITY.md).
