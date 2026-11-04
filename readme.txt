=== mailtonic ===
Contributors: mailtonic
Tags: newsletter, email marketing, signup form, popup, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Embed mailtonic forms and popups, send consented form sign-ups to your lists, and sync opted-in WooCommerce customers, orders and abandoned carts.

== Description ==

mailtonic is an email marketing service. This plugin connects a WordPress site to your mailtonic account.

* **Forms and popups.** Pick a mailtonic form in the "mailtonic form" block, the Elementor widget or the `[mailtonic_form id="…"]` shortcode. Choose up to 10 popups to load on every page. Forms keep their own design, consent text, triggers and bot protection from mailtonic.
* **Contact Form 7, WPForms and Elementor Pro Forms.** Bridge any form to a mailtonic list: map its fields, add tags and choose the consent checkbox. Only visitors who tick that box are sent, with the wording they agreed to.
* **WooCommerce.** An unticked opt-in box at checkout (classic and block checkout) and, optionally, on registration. Buyers who tick it are signed up; their orders send an `order_placed` event and order fields (orders count, total spent, last order) for your flows; their abandoned carts send a `cart_abandoned` event with a signed link that restores the cart. You can also import past customers.
* **Reliable.** Every call to mailtonic goes through a queue that is sent after the page has been served and retried with backoff, so a slow or unavailable mailtonic never slows a form or a checkout. The Sync log shows what was sent.
* **Privacy.** The plugin adds no cookies, no tracking and no analytics. It registers with WordPress's personal data exporter and eraser.

== Installation ==

1. Install and activate the plugin (Plugins › Add New, search for "mailtonic").
2. In mailtonic, open Settings › API keys and create a key with **write** access, preferably as an Owner.
3. In WordPress, open **mailtonic › Connection**, paste the key and click **Connect**. Choose the default list.
4. Embed a form: add the "mailtonic form" block to a page, or copy a shortcode from **mailtonic › Forms**.
5. Optional: bridge a Contact Form 7, WPForms or Elementor Pro form under **mailtonic › Integrations**, and set up WooCommerce under **mailtonic › WooCommerce** (click "Add WooCommerce fields to my list" to create the order and cart fields).

You may instead define the key in `wp-config.php`: `define( 'MAILTONIC_API_KEY', 'mt_…' );`. The key is otherwise stored encrypted and never shown again.

If your site sends its own Content-Security-Policy, allow your embed host (your mailtonic tracking domain, or app.mailtonic.in) in `script-src` and `frame-src`.

== Frequently Asked Questions ==

= Does the plugin add cookies? =

No. mailtonic forms keep their state in the browser's local storage, and the plugin sets no cookies.

= Who is sent to mailtonic? =

Only people who tick a consent box: a bridged form's consent checkbox, or the WooCommerce opt-in box. Buyers who do not tick it are never sent, unless they opted in on an earlier order.

= What happens if I uninstall? =

The plugin deletes its settings (including the key), its tables, and the order and user data it wrote. It never deletes anything in your mailtonic account.

== External services ==

This plugin connects to **mailtonic** (https://mailtonic.in), an email marketing service, at the app URL you configure (by default https://app.mailtonic.in). It is needed to list your forms and lists and to add contacts to them. Nothing is sent until you connect an API key.

* **When you connect or check the connection:** the API key is sent to read your account, lists and list fields.
* **When a page shows a mailtonic form or popup:** the visitor's browser loads the embed script from your embed host (your mailtonic tracking domain or the app host); the form itself runs on mailtonic.
* **When a visitor ticks the consent box on a bridged form, or the opt-in box at WooCommerce checkout or registration:** their email address, the mapped form or billing fields, the consent wording, the time, their IP address, browser user agent and the page address are sent.
* **When an opted-in buyer's order is paid:** an order summary (order number, total, currency, items count, first item name, coupon codes and the order link) and order fields (orders count, total spent, last order) are sent.
* **When an opted-in shopper leaves a cart:** a cart summary (total, currency, items count, first item name and a link that restores the cart) is sent.
* **When you import past customers:** the email, mapped billing fields and order fields of your customers are sent.
* **When you use Tools › Export or Erase Personal Data:** the email is sent to find, export or delete the matching contacts.

mailtonic terms of service: https://mailtonic.in/terms — privacy notice: https://mailtonic.in/privacy

== Changelog ==

= 1.0.0 =
* First release: connection, forms and popups, Contact Form 7, WPForms and Elementor Pro Forms bridges, WooCommerce opt-in, sign-ups, order events and fields, abandoned carts, past-customer import, sync log, privacy tools.
