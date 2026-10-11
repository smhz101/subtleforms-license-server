=== SubtleForms License Server ===
Contributors: subtleforms
Tags: license, woocommerce, updates
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sells SubtleForms Pro licenses through WooCommerce, connects customer sites, runs the members area and serves Pro updates.

== Description ==

Private plugin for subtleforms.com. See README.md for setup.

* One-click account connection for SubtleForms Pro (/connect-site/).
* WooCommerce My Account: Licenses dashboard, Pro downloads and a summary on the account dashboard.
* Licenses › Products creates every license product from one form.
* Pricing, changelog and latest-version shortcodes for the marketing site.
* Renewal reminder emails, renew and upgrade from the account.

== Changelog ==

= 1.1.0 =
* New: account connection flow at /connect-site/ with a one-time code exchanged at POST /sfls/v1/connect/exchange.
* New: My Account › Licenses rebuilt as a dashboard with copy key, connected sites, Deactivate, Renew, Upgrade and Download Pro.
* New: My Account › Pro downloads with the latest release, its changelog and older versions.
* New: SubtleForms Pro summary on the My Account dashboard.
* New: Licenses › Products setup wizard that creates yearly, renewal and lifetime products per plan.
* New: renewals and upgrades started from the account apply to the exact license chosen.
* New: [sfls_pricing], [sfls_changelog] and [sfls_latest_version] shortcodes.
* New: license editor, search, status filters, pagination and overview cards in Licenses.
* New: Licenses › Settings (pricing page, reminder days, lapsed reminder, support email, delete data on uninstall).
* New: daily renewal reminder emails.
* New: release history, so older Pro versions stay downloadable.
* Changed: release uploads use wp_handle_upload(); SQL uses the table prefix directly.
* Added uninstall.php (removes data only when enabled in settings).

= 1.0.0 =
* First release: licenses, WooCommerce delivery, My Account page, activation API, signed update downloads, admin screens and WP-CLI.
