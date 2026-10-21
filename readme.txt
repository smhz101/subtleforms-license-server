=== SubtleForms License Server ===
Contributors: subtleforms
Tags: license, woocommerce, updates
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.7.0
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
* Pro release management: every uploaded version is kept and stays downloadable, with stable and beta channels, drafts, yank and pin, download counts and per-version signed links.
* Git and CI/CD: a token-authenticated release API for CI, a GitHub release webhook, and WP-CLI release commands.
* Pay with Paddle: a WooCommerce payment gateway for license products. WooCommerce runs the cart and checkout, Paddle Billing takes the payment as merchant of record, with yearly subscriptions that renew automatically, 7 or 14 day free trials with one trial per customer, renewal orders, refunds and billing emails.

== Changelog ==

= Unreleased =
* Fix: A valid license key can activate a new site while it has free seats even after others sent failing requests for the key from other addresses and sites. Failures are now budgeted per key, address and site, with a separate high ceiling per key.

= 1.7.0 - 2026-10-20 =
* Change: License status and update checks also accept POST, so SubtleForms Pro 2.4.1 sends the license key in the request body instead of the address.
* Fix: A Paddle purchase made with the email of an existing account is no longer linked to that account until its owner confirms the address.
* Fix: Licenses belong to the account that bought them, so changing an account email to a buyer's address no longer shows their licenses, and a license bought as a guest is added to an account only after the buyer confirms the address through an emailed link.
* Fix: A guest who buys a license gets a link in the order email and on the thank-you page that downloads Pro and adds the license to an account for 30 days, without an account.
* Fix: Refunding or cancelling a renewal order removes the time it added, and refunding an upgrade order moves the license back to its previous plan, site limit and expiry, while partial refunds change nothing.
* Fix: Renewal products can only be bought for a license in the buyer's account and never issue a new key.
* Fix: Someone who knows a license key can no longer lock its connected sites out of validation and updates, and licenses on many sites no longer hit the rate limit when they update at once.
* Change: Only local hosts and known staging hosts of hosting companies are free, so production sites on *.wpengine.com, *.kinsta.cloud or dev. subdomains use a seat, and the staging hosts can be edited in Licenses › Settings.
* Fix: Release zips are stored under random file names, so they cannot be fetched directly even when the server ignores the .htaccess rule.
* Fix: A Paddle event that happened after an order was linked to its subscription is applied instead of being skipped as out of date.
* Fix: Connecting a site needs https unless it is a local development site.
* Change: The activation log and Paddle events are deleted after 365 days (Licenses › Settings), uninstall also removes the plugin's user meta and lock options, and download links no longer contain the license key.
* New: The [sfls_renewal_note] shortcode prints the renewal prices and discount from the yearly and renewal products, so the pricing page can no longer promise a discount the products do not give.
* New: Issue license has an option to email the key to the customer, with a link to the license page and Pro download and the install steps.
* Fix: The license CSV export reads activations in bulk, so 10,000 licenses export in a few seconds instead of timing out on large stores.
* Fix: License API errors all carry valid, reason and error, and a missing key, a missing site or an unusable site_url each get their own message.
* Fix: License-only orders get a completed email subject about the license instead of "Your order is on its way!", and a renewal or upgrade order page says the key stays the same.
* Fix: Release sizes under 1 KB show whole bytes, such as 478 B.
* Fix: The update check sends a bulleted changelog as a list, so the WordPress update screen shows list items instead of asterisks.
* Fix: Renewal and trial reminders go out for every due license on the same day instead of stopping at 500 per run.
* Fix: Pro downloads says Not available yet for a version without a package and Renew to download only when no license covers it, install steps mention the Download Pro button only where one is shown, and the account link shows an initial when the profile photo does not load.

= 1.6.0 - 2026-10-14 =
* New: Pro releases are stored in their own database table, with no limit on how many versions are kept. Each version has a channel (stable or beta), a status (draft, published or yanked), its size, SHA-256 checksum, requirements, changelog, source (upload, CI API, GitHub or WP-CLI), Git tag and commit, private notes and a download count. The releases from 1.5 are copied over automatically on the first request after updating.
* New: zip files are never deleted unless you delete that release. Uploading a version again replaces its package only when you tick "Replace the package", and the old zip is kept until the release is deleted.
* New: Licenses › Pro releases lists every version with its channel, status, date, size, short checksum, downloads, the sites running it and its Git tag or commit (linked to GitHub when a repository is set). Row actions: Download zip, Publish, Unpublish to draft, Yank with a reason, Make this the latest and Unpin, Edit and Delete.
* New: the update API offers the highest published stable version. Drafts and yanked versions are never offered, so yanking a bad version makes the previous one the latest again. "Make this the latest" pins an older version until you unpin it. Sites can ask for the beta channel with channel=beta on update/check and update/package. Pro sends nothing today, so it stays on stable.
* New: My Account › Pro downloads shows the latest release and an "Older versions" list with every published version, its changelog and a download button. Each button creates a fresh signed link for that license and version that works once within 15 minutes. A license that expired or was suspended keeps the versions released while it was valid.
* New: GET /wp-json/sfls/v1/update/versions lists the versions a license may download, and update/package accepts a version. Signed download links now include the version; links handed out by 1.5 still work for the current release.
* New: the [sfls_downloads] shortcode and the SubtleForms Pro downloads block show the same download list on any page.
* New: Licenses › Settings › Releases has deploy tokens for CI (shown once, stored as a hash, with last use and revoke), the release API endpoint with an "Auto publish CI releases" option, and the GitHub connection (repository, token, webhook secret, asset name pattern, auto publish, Test connection and Import a release by tag).
* New: release API for CI at /wp-json/sfls/v1/releases with a deploy token: upload a zip or a package_url on GitHub, list, show, publish, yank and delete. Sending the same version and checksum again returns the existing release, and a different package for a published version needs replace=true. It requires https outside local sites and blocks an IP after repeated failed tokens.
* New: GitHub webhook at /wp-json/sfls/v1/github/webhook. It checks the signature, answers ping, and imports the zip attached to a published GitHub release after checking that the tag matches the plugin version. Pre-releases go to the beta channel. The last 20 deliveries are listed with a Retry button.
* New: WP-CLI commands wp sfls release list, add, publish, yank, latest and delete, and wp sfls release token create, list and revoke.
* New: docs/CI.md explains both ways to publish from CI, and the SubtleForms Pro repository has a GitHub Actions workflow that builds the zip on every v* tag and publishes it here.
* Changed: a version lower than the latest release is no longer refused. It is listed as an older version and never becomes the latest by itself.

= 1.5.1 - 2026-10-12 =
* Changed: Pay with Paddle now shows the Paddle payment form inline, right under the payment method on the checkout page, instead of sending the customer to a separate payment page with a popup. The form opens with the customer's email and country already filled in, so the customer only adds a card or picks PayPal, Apple Pay or Google Pay. Works in the Checkout block and the classic checkout.
* Changed: while the form is open, Paddle's Pay button replaces Place order and the details above are locked. "Change details" closes the form without charging anything, and placing the order again reuses the same order.
* Changed: the order payment page (My Account › Orders › Pay) shows the same inline form instead of the overlay.
* New: Paddle.js loads on the checkout page, so Paddle's emailed payment links (?_ptxn=…) open there when the checkout page is the Paddle default payment link.

= 1.5.0 - 2026-10-12 =
* New: Pay with Paddle is a WooCommerce payment gateway. Customers use the WooCommerce cart and checkout, and pay in the Paddle overlay by card, PayPal, Apple Pay or Google Pay. It works in the classic checkout and the Checkout block, and is offered only for carts that hold a SubtleForms license.
* New: Licenses › Settings › Paddle › Sell licenses with now offers "WooCommerce checkout with Paddle payments (recommended)", "WooCommerce with other gateways" and "Paddle overlay (legacy)". Shops that sold with Paddle in 1.4.0 move to the recommended mode once, and the gateway is switched on.
* New: Place order creates the order as Pending and a Paddle transaction from it, with the plan's price (or its trial price), the customer's email and country, the order details in custom data, and WooCommerce coupons as a discount on the first payment only. A Paddle price that does not match the cart is refused before the customer pays.
* New: the order's payment page opens the Paddle overlay. Closing it keeps the order pending, and the customer can pay later from the same page or from My Account.
* New: the Paddle webhook completes the order after checking the order key, currency and amount, adds Paddle's sales tax or VAT as a fee line so the order total matches the payment, and issues the license once. The license follows the Paddle subscription, so trials, automatic renewals, reminders, past due, cancel, resume and the customer portal work as before.
* New: the order received page shows "Confirming your payment" until Paddle confirms, asks Paddle directly every few seconds, and then shows the license key.
* New: free trials are $0 orders with a "Free for 14 days, then $79/year" line. Paddle still collects the card. One trial per email, company domain and site is checked at checkout, with a link to buy without the trial.
* New: every Paddle renewal is recorded as a completed WooCommerce order linked to the first order, so reports and the customer's Orders list show each payment. Renewals extend the existing license and never issue a new key. A failed renewal adds a note to the first order and sends the past due email.
* New: refunds from WooCommerce are sent to Paddle as full or partial refunds, and refunds or chargebacks made in Paddle are recorded as WooCommerce refunds once approved. Nothing is refunded twice. A fully refunded first order cancels its Paddle subscription.
* New: upgrades paid with Paddle update the existing subscription with a prorated charge on the saved card, keep the key, and are recorded as an order with Paddle's amount. During a trial the upgrade costs nothing until the trial ends.
* New: lifetime licenses and upgrades of licenses not billed by Paddle are one-time Paddle charges. Renewing a license sold through WooCommerce turns it into a yearly Paddle subscription.
* New: license products carry no WooCommerce tax while Paddle takes the payment, and the checkout says that Paddle calculates sales tax or VAT.
* New: the pricing block's Buy and Start trial buttons go to the WooCommerce cart in the recommended mode.
* New: the plugin declares compatibility with WooCommerce order storage (HPOS) and the Cart and Checkout blocks.
* Changed: the webhook also handles adjustment.created and adjustment.updated. Add them to the Paddle notification destination.

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
