=== SubtleForms License Server ===
Contributors: subtleforms
Tags: license, woocommerce, updates
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sells SubtleForms Pro licenses through WooCommerce, connects customer sites, runs the members area and serves Pro updates.

== Description ==

Private plugin for subtleforms.com. See README.md for setup.

* One-click account connection for SubtleForms Pro (/connect-site/).
* WooCommerce My Account: Licenses dashboard, Pro downloads and a summary on the account dashboard.
* Licenses › Products creates every license product from one form.
* Pricing, changelog, latest version and account link blocks (and shortcodes) for the marketing site.
* Renewal reminder emails, renew and prorated upgrades from the account.
* Rate-limited license API with an activation log and single-use download links.
* Optional Paddle Billing: yearly subscriptions that renew automatically, 7 or 14 day free trials with one trial per customer, and billing emails.

== Changelog ==

= 1.4.0 - 2026-10-12 =
* New: licenses can be sold with Paddle Billing. Licenses › Settings › Paddle has a "Sell licenses with: Paddle or WooCommerce" choice, the sandbox or live environment, the API key, client-side token and webhook secret, a Test connection button, the webhook URL to paste into Paddle, and a Sync products helper to map each plan to its Paddle prices. WooCommerce stays for My Account, and licenses sold through WooCommerce keep working.
* New: free trials of 7 or 14 days per plan. The customer pays nothing today, Paddle collects the card and charges when the trial ends. A trial license covers 1 site and gets the plan's full site limit when the first payment goes through.
* New: one free trial per email address, per company email domain (only the exact address on Gmail, Outlook, Yahoo, Hotmail, iCloud, Proton and other free mail) and per site. The pricing cards check this before the checkout opens, and the webhook checks it again: a second trial that slips through is canceled in Paddle before any charge and the customer gets an email.
* New: every Paddle plan renews automatically each year. Customers get emails before the trial ends (3 days), before each renewal (14 and 3 days, configurable), when a payment goes through, when a payment fails (with a link to update the card), when auto-renew is turned off and when the license ends. Every email can be sent as a test from Licenses › Settings.
* New: the pricing block and shortcode open the Paddle overlay checkout. Plans with a trial show "Start 14-day free trial" and "Then $79/year, cancel anytime". Customers who already had a trial see "Buy now" and the reason.
* New: /license-welcome/ (or a page with the [sfls_license_welcome] shortcode) waits for the license after a Paddle checkout, then shows the key, Download Pro, the install steps and the trial end date.
* New: My Account › Licenses shows the billing state of Paddle licenses (Trial ends on, Renews on, Cancels on, Past due) with Update payment method, Cancel auto-renew, Resume and Upgrade buttons. Upgrades charge the prorated difference at once, keep the key and raise the limits right away.
* New: webhook endpoint POST /wp-json/sfls/v1/paddle/webhook checks the Paddle-Signature header, refuses old timestamps, handles every event once and skips events that arrive out of order. The last events are listed in Licenses › Settings › Paddle.
* New: Licenses › Products can create the Paddle products and yearly prices, with or without a trial, from the WooCommerce yearly prices.
* New: license responses include trial, trial_ends_at, renews_at, cancels_at, billing, billing_status, renewal_amount and manage_url. During a trial the license is valid with status "valid", so older Pro versions keep working.
* New: the license editor shows the billing source, the Paddle subscription and the trials a license used, with Allow another trial. The licenses list marks trials, past due and canceling subscriptions.
* Changed: renewal reminders for manual renewals are no longer sent for Paddle licenses, which renew by themselves.

= 1.3.0 - 2026-10-11 =
* Fixed: paid license orders now complete on their own. When every item in an order is a license product, payment moves it straight to Completed, whether or not the products are marked downloadable. Paid orders that also contain other items issue their keys at Processing. An order never issues keys twice.
* Fixed: the cart holds one plan at a time. A Buy button replaces any other plan in the cart, adding a license another way swaps it with the notice "You can buy one plan at a time. We swapped your cart to (plan).", and the checkout order summary has a Change plan link to the pricing page.
* Fixed: every plan feature has a label, so the Products screen no longer shows stray commas.
* Fixed: before the first Pro release, the latest version shortcode and the My Account dashboard say Coming soon, and every Licenses screen in wp-admin warns that customers cannot download Pro yet.
* Fixed: the support email defaults to support@ your site domain on activation instead of the admin's own address, the Settings screen warns when it is a Gmail, Outlook or Yahoo address, and it is the Reply-To (and the From address when it is on your domain) of every license email.
* New: My Account hides the Addresses and Downloads tabs for customers whose orders only contain licenses. Turn it off in Licenses › Settings.
* New: checkout asks only for name, email and country when the cart holds only licenses, in the Checkout block and the classic checkout. Turn it off in Licenses › Settings.
* New: each active license in My Account › Licenses has a Download Pro button, and the page shows three steps to install Pro.
* New: the order received page and the order emails show the license key, a Download Pro button and the three install steps.
* Changed: upgrading from My Account charges only the difference between the plans for the time left on the license, keeps the same key and renewal date, raises the site limit and features at once and adds an order note.
* New: Licenses › Products shows which WooCommerce product each price belongs to, with a link and a Published, Draft or Missing status. Saving never creates a second product.
* New: Pro releases read Version, Requires at least, Tested up to and Requires PHP from the uploaded zip, check that it contains subtleforms-pro/subtleforms-pro.php, and refuse a version lower than the current one.
* New: the licenses list filters by plan, status and expiry within 30 days, searches by key, email or site domain, and exports the results as CSV.
* New: Send test reminder in Licenses › Settings emails an example renewal reminder to you without touching any license.
* New: Pricing, Changelog and Latest version blocks with settings for the highlighted plan, renewal note, yearly or lifetime first and button labels. The shortcodes keep working.
* New: Account link block that shows Sign in to visitors and My licenses with the customer's avatar once they are signed in.
* New: license activate, validate and status requests are limited to 30 per 10 minutes per IP and per key, and answer 429 with Retry-After when the limit is reached.
* New: every activation and deactivation is logged with the site, IP address and time, and shown on the license screen.
* Changed: signed update download links expire after 15 minutes and work only once. The update check now returns a package address that hands out a fresh link when the update is downloaded.
* New: license responses include is_local, so SubtleForms Pro can tell customers when a site is recognised as staging and does not use a seat.

= 1.2.0 - 2026-10-11 =
* Pricing cards list each plan's real features: WooCommerce checkout and integrations on Pro, white label and translation on Agency, and the features every plan shares

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
