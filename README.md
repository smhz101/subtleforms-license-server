# SubtleForms License Server

Runs on **subtleforms.com**. It sells SubtleForms Pro through WooCommerce, issues license keys, connects customer sites to their account, gives customers a members area, answers activation requests from the Pro plugin, and ships Pro updates to licensed sites.

SubtleForms Pro talks to it at `https://subtleforms.com/wp-json/sfls/v1/`.

## 1. Install

1. Install WordPress and WooCommerce on subtleforms.com (HTTPS required).
2. Upload `subtleforms-license-server.zip` in **Plugins › Add New › Upload** and activate it. Activation creates the tables, registers `/connect-site/` and the My Account pages, and schedules the daily reminder check. (If `/connect-site/` or **My Account › Licenses** ever returns a 404, go to **Settings › Permalinks** and click **Save**.)
3. In **Licenses › Settings**, set the pricing page URL and support email.

On Nginx, block direct access to the release folder (Apache is handled by the bundled `.htaccess`). Since 1.7.0 the zips also have random file names and are only served through the signed download endpoint:

```
location ^~ /wp-content/uploads/sfls-private/ { deny all; }
```

## 2. Set up pricing (Licenses › Products)

One form, one row per plan (Starter, Pro, Agency):

| Column | What it creates |
|---|---|
| Sites | Site limit for the plan (0 = unlimited). Prefilled from the plan: Starter 1, Pro 5, Agency 25. A blank value is refused. |
| Yearly price | `SubtleForms Pro <Plan>`: 12 months. |
| Renewal price | `<Plan> Renewal`: 12 months, hidden from the shop, used by the Renew button. |
| Lifetime price (optional) | `<Plan> Lifetime`: never expires. |

Click **Save products**. Products are simple, virtual and sold one at a time. Saving again updates the same products (their IDs are kept in the `sfls_products` option); products you set up by hand before are adopted instead of duplicated. Clearing a price moves that product to draft. Under each price a chip shows the product it maps to (`#3002`, linking to the editor) and its status: Published, Draft, Missing (deleted, recreated on the next save) or Not created. The wizard never changes the downloadable flag of an existing product; nothing depends on it.

Each license product carries this meta, which you can also set by hand in **Product data › General**:

| Meta | Values |
|---|---|
| `_sfls_enabled` | `yes` |
| `_sfls_plan` | `starter`, `pro`, `agency` |
| `_sfls_sites` | site limit, empty = plan default, `0` = unlimited |
| `_sfls_months` | `12` yearly, `0` lifetime |
| `_sfls_renewal` | `yes` for renewal products |

Plans and the features each unlocks are defined in `includes/class-sfls-plans.php` and must match `PLAN_FEATURES` in SubtleForms Pro's `LicenseManager`.

## 3. Blocks and shortcodes for the marketing site

| Block | Shortcode | Output |
|---|---|---|
| SubtleForms Pro pricing (`sfls/pricing`) | `[sfls_pricing]` | Pricing cards from the license products: price, "/year" or "one-time", sites, features, and a Buy button that goes straight to checkout. When lifetime products exist a Yearly/Lifetime toggle appears. Block settings: highlighted plan, show renewal note, yearly or lifetime first, button labels (`{plan}` is replaced by the plan name). Shortcode attributes: `period="yearly"` or `"lifetime"`, `popular="pro"`, `renewal_note="yes"`, `button="Get {plan}"`, `button_lifetime="Own {plan}"`. |
| SubtleForms Pro changelog (`sfls/changelog`) | `[sfls_changelog]` | Every Pro release, newest first, with date and changes. `limit="3"` (block: Releases to show) shows only the latest three. |
| SubtleForms Pro latest version (`sfls/latest-version`) | `[sfls_latest_version]` | The current Pro version number, or "Coming soon" before the first release. The block has an optional text before the version. |
| SubtleForms Pro downloads (`sfls/downloads`) | `[sfls_downloads]` | For signed-in customers: the latest release and every older version with its changelog and a download button (the same list as My Account › Pro downloads). Visitors see a sign-in link. |
| Account link (`sfls/account-link`) | | "Sign in" (links to My Account) for visitors, the customer's avatar and "My licenses" (links to My Account › Licenses) once signed in. Both labels and the avatar can be changed. If you use a full-page cache, make sure it does not serve cached pages to signed-in users (most do this by default). |

The blocks are server-rendered (`blocks/*/block.json`); the editor script `assets/js/sfls-blocks-editor.js` is plain JavaScript on the `wp.*` globals, so there is no build step.

Styles load only on pages that use a shortcode and are scoped under `.sfls`. Override colours with custom properties, for example `.sfls { --sfls-accent: #111; }`.

## 4. What happens on a sale

1. Payment completes (Stripe calls `$order->payment_complete()`). An order whose items are all license products goes straight to **Completed** through `woocommerce_payment_complete_order_status`, whatever the downloadable flag says. A paid order that also holds other items stays at **Processing** for shipping, and its keys are issued there. Cash on delivery waits for Completed. The order flag `_sfls_issued` (plus a short lock) makes sure keys are issued once.
2. A new purchase creates one key per quantity, e.g. `SFP-1A2B3C4D-…`.
3. A **Renew** started from My Account extends exactly that license. A renewal product bought another way extends the customer's license for that plan that expires first.
4. An **Upgrade** started from My Account moves the existing key to the bigger plan and its site limit. It costs `(new plan price - current plan price) × days left / 365` and keeps the renewal date; the checkout shows the credit. Lifetime to lifetime costs the difference of the lifetime prices. An expired license pays the full price and gets a new year. An order note records it. No new key is issued.
5. Keys are shown on the order received page (right under "Thank you"), in the order emails and in **My Account › Licenses**, with a Download Pro button and three install steps. License emails reply to the support address (and come from it when it is on your domain).

The cart holds one plan at a time: a Buy button swaps out any other plan, other ways of adding a license (shop buttons, the Store API) swap too and say so, and the checkout order summary has a **Change plan** link. When the cart holds only licenses, checkout asks for name, email and country only (Licenses › Settings).
6. Refunded or cancelled orders suspend the keys they issued. A refunded or cancelled renewal order takes off the time it added, and a refunded upgrade order moves the license back to its previous plan, site limit and expiry (each order records what it changed). Partial refunds change nothing.
7. Renewal products can only be bought from **Renew** in My Account for a license of the signed-in customer; anything else is refused in the cart, and an order with a renewal product never issues a new key.
8. Licenses belong to a user ID: the customer of the order. Guest orders get a signed link (30 days) in the order email and on the thank-you page that downloads Pro and adds the license to an account. A guest license is also added when the buyer signs in to, or creates, an account with the order's email and opens the one-time link emailed to that address. Changing an account email never moves a license.

Local hosts (`localhost`, `*.local`, `*.test`, `*.example`, `*.invalid`, Lando, DDEV) and the staging hosts in **Licenses › Settings** never use a site slot. The default list has `staging.*`, WP Engine `*stg` and `*dev` installs, Kinsta `staging-*`, Flywheel staging, Pantheon `dev-` and `test-`, and InstaWP. Production hosts such as `*.wpengine.com` and `*.kinsta.cloud` use a seat. Filter the list with `sfls_staging_patterns`.

## 5. Members area (WooCommerce My Account)

* **Dashboard**: a SubtleForms Pro summary with active licenses, next renewal date and the latest Pro version with a download link.
* **Licenses** (`/my-account/licenses/`): one card per license with plan, status, the full key with a Copy button, expiry, sites used, the connected sites with last seen and a Deactivate button, and Download Pro, Renew and Upgrade (with today's prorated price), plus a three-step "How to install Pro" panel.
* Customers whose orders only contain licenses do not see the Addresses and Downloads tabs (Licenses › Settings › Simplify My Account).
* **Pro downloads** (`/my-account/pro-downloads/`): the latest release with its changelog, size and SHA-256 and a download button, plus every older published version with its own changelog. Each button sends the customer to a signed, single-use link for their license and that version; licenses that are no longer valid keep the versions released while they were. The storage path is never exposed.

## 6. Connecting a site (used by SubtleForms Pro)

Pro sends the admin to:

```
https://subtleforms.com/connect-site/?site=<home url>&return=<wp-admin callback>&state=<random>&name=<site name>&v=<pro version>
```

* `return` must be https (http only for local hosts) and on the same host as `site` (compared without `www.`), otherwise an error page is shown.
* Signed-out visitors are sent to the My Account login and come back afterwards.
* The customer picks one of their licenses (matched by user ID only). Suspended, expired or full licenses are disabled with the reason, unless the site is already active on them or is a local/staging host.
* **Connect site** activates the domain with the same rules as `/license/activate`, then redirects to `return?sfls_code=<40 chars>&state=<state>`. The code is valid for 10 minutes and works once.
* **Cancel** redirects to `return?sfls_error=cancelled&state=<state>`.

Pro then calls:

```
POST /wp-json/sfls/v1/connect/exchange   { "code": "...", "site_url": "https://example.com" }

200 { "license_key": "SFP-…", "account": { "email": "...", "name": "..." },
      "valid": true, "status": "valid", "plan": "pro", "features": [...],
      "expires_at": "...", "grace_period_ends_at": null,
      "sites_allowed": 5, "sites_used": 2, "check_again_in": 43200 }

400 { "valid": false, "reason": "invalid_code", "error": "..." }
```

`site_url` must normalise to the domain the code was issued for.

## 7. Renewal reminders

A daily WP-Cron event (`sfls_daily`) emails customers whose license expires within the days set in **Licenses › Settings** (default `30,7`), and once more the day after expiry if that option is on. Each reminder goes out once per license, stage and expiry date, so a renewal starts fresh. Emails use the WooCommerce email template when available and link to My Account › Licenses. **Send due reminders now** in Settings, or `wp sfls remind`, runs the check by hand. On low-traffic sites, trigger WP-Cron from a real cron job.

## 8. Issue keys by hand

**Licenses › Issue license** creates complimentary, partner or test keys. **Licenses** lists every key with overview cards, status filters (including expiring in 30 days), a plan filter, search by key, email or activated domain, and **Export as CSV** for the current filters. Click a key to edit plan, sites, expiry, status, email and notes, see activations and the activity log (every activation and deactivation with site, IP, time and who did it), deactivate sites, regenerate the key or delete it. From the command line:

```
wp sfls create --plan=agency --email=you@example.com --months=0
wp sfls create --plan=pro --user=customer-login
wp sfls list
wp sfls remind --dry-run
```

## 9. Ship a Pro update (1.6.0 release management)

Every version is a row in `wp_sfls_releases` with a channel (stable or beta), a status (draft, published or yanked), its zip in `uploads/sfls-private/`, size, SHA-256, requirements, changelog, source (upload, api, github, cli), Git tag and commit, private notes and a download counter. Nothing is capped and a zip is only deleted when its release is deleted.

* **Licenses › Pro releases**: upload `subtleforms-pro-x.y.z.zip` (headers are read from the zip; choose the channel, publish now or save a draft, add the changelog with one `* ` line per change). Uploading an existing version with a different zip needs the **Replace the package** box. The table lists every version with channel, status, date, size, short SHA-256, downloads, the sites that last checked in with it, and the source with its tag and commit (linked to GitHub when a repository is set). Row actions: Download zip, Publish, Unpublish to draft, Yank (with a reason), Make this the latest / Unpin, Edit, Delete.
* **Latest**: the update API offers the highest published stable version, or the pinned one. Drafts and yanked versions are never offered, so yanking rolls back to the previous version. Sites may opt in to betas with `channel=beta` on `/update/check` and `/update/package` (Pro sends nothing, so it stays on stable).
* **Customers**: My Account › Pro downloads (and the `[sfls_downloads]` shortcode / SubtleForms Pro downloads block) shows the latest release and every older published version with its changelog. Each button creates a signed, single-use link for that license and version. Licenses that expired or were suspended keep the versions released while they were valid (`released_at <= expires_at`), not newer ones.
* **Git and CI**: Licenses › Settings › Releases has deploy tokens, the release API and the GitHub webhook connection. See [docs/CI.md](docs/CI.md) for both CI paths, the GitHub Actions workflow in the Pro repository and WP-CLI (`wp sfls release …`).

Update downloads: `/update/check` returns `package` = `/update/package?lid&site_url&ptoken[&channel]` (since 1.7.0 the license ID and a token for that license and site, never the key; a request with `license_key` instead still works). That address checks the license and the site and redirects to a signed `/update/download` link for that version that expires after 15 minutes and works once (used tokens are kept in `wp_sfls_download_tokens`). WordPress follows the redirect when it installs the update, so a cached update check never hands out a dead link. Links signed by 1.5 (without a version) still download the current release.

## Selling with Paddle (1.5.0: WooCommerce checkout, Paddle payments)

Paddle Billing (API v2, not Paddle Classic) takes the payments, and WooCommerce runs the cart and checkout. Customers add a plan to the WooCommerce cart, check out with the usual fields (the simple license-only checkout and the one-plan-per-cart rules still apply), and pay with **Pay with Paddle**, a real WooCommerce payment gateway (`sfls_paddle`). Since 1.5.1, Place order opens the Paddle payment form inline on the checkout page (Paddle inline checkout, one-page variant, prefilled with the order's email and country), and the customer pays there by card, PayPal, Apple Pay or Google Pay. Paddle is the merchant of record: it adds sales tax or VAT, sends receipts and renews yearly subscriptions automatically, with optional 7 or 14 day free trials. Every purchase, renewal, upgrade and refund is a WooCommerce order.

Licenses › Settings › Paddle › **Sell licenses with** has three modes:

* **WooCommerce checkout with Paddle payments (recommended).** Described here.
* **WooCommerce with other gateways.** The license products from Licenses › Products, paid with the shop's other gateways, manual renewals, WooCommerce tax.
* **Paddle overlay (legacy).** The 1.4.0 flow: pricing buttons open the Paddle overlay directly and the license is issued without a WooCommerce order. Kept for existing setups; the 1.4.0 behaviour is unchanged.

Updating from 1.4.0: a shop that sold with Paddle in 1.4.0 moves to the recommended mode once, and the Pay with Paddle gateway is switched on. A shop on WooCommerce stays on WooCommerce. Licenses sold before keep working either way.

### Setup (do everything in the Paddle sandbox first)

1. **Paddle sandbox account.** Sign up at https://sandbox-vendors.paddle.com/signup. Sandbox payments are not real.
2. **API key.** Paddle › Developer tools › Authentication › API keys › New API key. Permissions: Subscriptions read and write, Customers read and write, Addresses read and write, Customer portal sessions write, Transactions read and write, Adjustments read and write, Discounts read (only if you map coupons to Paddle discounts), Products read and write, Prices read and write. Copy it (`pdl_sdbx_apikey_…` in the sandbox).
3. **Client-side token.** Paddle › Developer tools › Authentication › Client-side tokens › New client-side token (`test_…` in the sandbox, `live_…` in live).
4. **Checkout domain.** Paddle › Checkout › Checkout settings: set the **Default payment link** to your checkout page (for example `https://subtleforms.com/checkout/`) and add the domain under approved domains. In live, Paddle reviews the domain before checkouts open on it; the overlay refuses to open on an unapproved domain.
5. **Plugin settings.** WordPress › Licenses › Settings › Paddle: Environment = Sandbox, paste the API key and the client-side token, **Save Paddle settings**, then **Test connection**.
6. **Webhook.** Paddle › Developer tools › Notifications › New destination. Type: Webhook. URL: the **Webhook URL** on the Paddle tab (`https://subtleforms.com/wp-json/sfls/v1/paddle/webhook`). Events: `transaction.completed`, `transaction.payment_failed`, `subscription.created`, `subscription.trialing`, `subscription.activated`, `subscription.updated`, `subscription.past_due`, `subscription.paused`, `subscription.resumed`, `subscription.canceled`, `adjustment.created`, `adjustment.updated`. Save, copy the destination's **secret key** (`pdl_ntfset_…`) into **Webhook secret** and save. Until the secret is set the endpoint answers 503.
7. **Catalog: one product per plan, with and without trials.**
   * **Automatic:** Licenses › Products, fill in the yearly prices (these are the WooCommerce prices too), then under **Paddle products and prices** choose 7 days, 14 days or No trial per plan, check the currency and click **Create in Paddle**. It creates a product per plan (tax category Standard), a yearly price without a trial, and, for plans with a trial, a second yearly price with the trial period and **payment method required**, and maps them.
   * **By hand:** Catalog › Products › New product per plan, tax category Standard. Add a price: billing period Yearly, no trial. For a trial add a second price: Yearly, trial 7 or 14 days, Require payment method on. Then **Sync products** on the Paddle tab, pick the yearly price and the trial price per plan and **Save plans**.
   * **Keep the prices equal.** The WooCommerce yearly product price of each plan must equal its Paddle price (same currency as the shop). Place order checks this and refuses a mismatch with an order note, so a customer is never charged a different amount than the cart showed.
8. **Gateway.** WooCommerce › Settings › Payments › **Paddle** (or the link on the Paddle tab): Enable, title (*Pay with Paddle*) and description. The keys are not repeated there; the screen links back to the Paddle tab and says whether it is connected.
9. **Sell.** Paddle tab › Sell licenses with: **WooCommerce checkout with Paddle payments (recommended)** › Save. The notice at the top says what is missing, if anything.

### How it works

* **Pricing:** the pricing block and `[sfls_pricing]` show the Paddle plans. *Start 14-day free trial* links to `?sfls_buy={product}&sfls_trial=1` (with *or buy now without a trial* under it), *Buy* links to `?sfls_buy={product}`. Both put the plan in the cart and open the checkout. Signed-in customers who already had a trial see *Buy now* and the reason. Paddle.js is not loaded on the pricing page.
* **Cart and checkout:** a trial line costs $0.00 and shows *Free trial: Free for 14 days, then $79.00/year*. Pay with Paddle is the only payment method for license-only carts (filter `sfls_paddle_only_gateway` to show other gateways too) and is hidden for carts with other products. License products carry no WooCommerce tax in this mode, and the checkout says *Sales tax or VAT is calculated by Paddle at payment.* Classic checkout and the Checkout block are both supported (HPOS and the Cart and Checkout blocks are declared compatible).
* **Trial rules at checkout:** one trial per email, per company email domain (exact address only on free mail) and per site, checked in `woocommerce_after_checkout_validation` (classic) and right before payment in the Store API (block). A refused trial shows *This email address already had a free trial. You can buy the plan right away instead.* with a **Buy without the free trial** link that swaps the cart line.
* **Place order:** the order is created Pending. The gateway finds or creates the Paddle customer by email (`GET /customers?email=`, `POST /customers`), adds the country and postcode as an address, and creates a transaction (`POST /transactions`) with `items` (the plan's price, or its trial price for a trial), `customer_id`, `address_id`, `currency_code`, `collection_mode: automatic`, `custom_data` (`wc_order_id`, `order_key`, `sfls_plan`, `sfls_kind`, `sfls_license_ids` for renewals and upgrades) and, for a WooCommerce coupon, a one-time flat discount (`discount` with `recur: false`) so renewals charge the normal price. A coupon with the meta `_sfls_paddle_discount_id` uses that (non-recurring) Paddle discount instead. Paddle's amount before tax must match the order total, or the transaction is canceled and the order is not paid.
* **Payment form (1.5.1):** the checkout stays on the page. The gateway result carries `sfls_paddle_txn`, `sfls_paddle_success`, `sfls_paddle_pay_url` and `sfls_paddle_trial`; `assets/js/sfls-paddle-inline.js` opens `Paddle.Checkout.open({ transactionId, settings: { displayMode: 'inline', variant: 'one-page', frameTarget, successUrl } })` under the payment method (Checkout block: from `onCheckoutSuccess`, which holds the checkout until Paddle confirms; classic: from `checkout_place_order_success`, with the redirect set to `#sfls-paddle`). Paddle's Pay button replaces Place order and the details above are locked; **Change details** closes the form, nothing is charged, and placing the order again reuses the pending order and cancels the old transaction. The order payment page (order-pay) shows the same inline form, for orders paid later from My Account and as the fallback when the checkout cannot run the script. Abandoned orders are cancelled by WooCommerce after the hold stock time, which also cancels the Paddle transaction.
* **Completion:** `transaction.completed` (or the first subscription event, whichever comes first) finds the order through `custom_data`, checks the order key, the currency and the amount before tax, adds Paddle's tax as a fee line *Sales tax / VAT (collected by Paddle)* so the WooCommerce total equals what Paddle charged, sets the payment method title (for example *Paddle (Visa ending 4242)*) and calls `$order->payment_complete( $transaction_id )`. The existing order completion issues the license once (`_sfls_issued`), and the license is then linked to the Paddle subscription (`billing = paddle`, subscription and customer IDs on the license and on the order), so trials, automatic renewals, reminders, past due, cancel, resume, the customer portal and upgrades all work as in 1.4.0. A wrong order key changes nothing; a wrong amount or currency puts the order on hold with a note. A second payment for an already paid order gets a note asking you to refund it.
* **Order received:** when the customer is back before the webhook, the license panel says *Confirming your payment* and polls `GET /wp-json/sfls/v1/paddle/checkout?order=…&key=…`, which also asks Paddle for the transaction every few seconds, so the key appears even if the webhook is late. When Paddle added tax the page reloads once to show the final total.
* **Trials:** a trial is a $0 order that still goes through Paddle (Paddle saves the card). The license starts as a trial for 1 site and gets the plan's site limit with the first payment.
* **Renewals:** each yearly charge (`transaction.completed` with origin `subscription_recurring`, including the first charge after a trial) extends the license as before and records a Completed WooCommerce renewal order (payment method Paddle, the Paddle transaction ID, the plan's renewal or yearly product, the tax as a fee line, `_sfls_parent_order` pointing to the first order, an order note on both). Renewal orders never issue a key and do not send the WooCommerce completed email (the Paddle receipt and the license's payment email already go out). A failed renewal adds a note to the first order and sends the existing past due email.
* **Upgrades:** My Account › Licenses › *Upgrade to …* goes through the cart with the prorated price. Paid with Paddle, the gateway previews and then updates the existing subscription (`PATCH /subscriptions/{id}` with the new price, `proration_billing_mode: prorated_immediately`, `on_payment_failure: prevent_change`), Paddle charges the saved payment method, the key stays, the order records Paddle's prorated amount, and the transaction ID is added when Paddle confirms it. During a trial the new plan's trial price is used with `do_not_bill` and the order is $0.
* **Lifetime licenses and renewals of non-Paddle licenses:** a lifetime product, or an upgrade of a license that is not billed by Paddle, is a one-time Paddle charge (a non-catalog price on the plan's Paddle product). Renewing a license that was sold through WooCommerce creates a yearly Paddle subscription at the renewal price, so it renews automatically from then on.
* **Refunds:** the WooCommerce **Refund** button creates a Paddle refund (`POST /adjustments`, `action: refund`, `type: full` or `partial` with the transaction's line item). Paddle approves refunds (sandbox: about every ten minutes); if it rejects one, the WooCommerce refund is removed again with a note. Refunds made in the Paddle dashboard and chargebacks (`adjustment.created` / `adjustment.updated`, once approved) are recorded as WooCommerce refunds. Each adjustment is handled once. A fully refunded first order cancels the Paddle subscription at once and suspends the license.
* **Webhook security:** `Paddle-Signature` (HMAC-SHA256 of `ts:raw body`, several `h1` accepted while secrets rotate, 5 minute tolerance, filter `sfls_paddle_webhook_tolerance`), each `event_id` handled once (`wp_sfls_paddle_events`), out-of-order subscription events skipped, and a failure answers 500 so Paddle retries.
* **License states, members area, emails and Pro:** as in 1.4.0 (see the table below).

  | Paddle | License |
  |---|---|
  | `trialing` | Status active, **1 site**, `trial_ends_at`, `expires_at` = trial end. A trial has no grace period. |
  | `active` / first payment | Full plan sites, trial over, `expires_at` = end of the billing period, `renews_at` = next charge. |
  | renewal `transaction.completed` | `expires_at` moves to the end of the new period. Payment email, renewal order. |
  | `past_due` | Stays valid until the paid period ends, then the one 14 day grace period applies (it was 3 + 14 days before). Payment failed email. |
  | scheduled cancel | `cancels_at` set, no renewal date, email. Stays active until then. |
  | `canceled` | A paid license keeps its expiry. A trial canceled before paying ends at once. |
  | another plan's price | Plan, sites and features change, same key. |

### Sandbox test checklist (owner, before going live)

Paddle could not be reached from the development machine, so the gateway was built against the Paddle API docs and tested with mocked HTTP and a stubbed Paddle.js. Run these once in the sandbox. Test card: `4242 4242 4242 4242`, any future date, CVC `100` (more at https://developer.paddle.com/concepts/payment-methods/credit-debit-card).

1. Paddle tab: **Test connection** says *Connected to the Paddle sandbox*. **Sync products**, check each plan's prices, **Save plans**. WooCommerce › Settings › Payments shows **Paddle** as Active.
2. Signed out, /pricing/ › Pro › **or buy now without a trial**. The checkout shows only *Pay with Paddle* and the tax note, and no WooCommerce tax. Place order: the Paddle payment form opens under Pay with Paddle on the same page, with the price plus tax for your test address, and Place order is replaced by Paddle's Pay button. Click **Change details**: the form closes and nothing was charged; Place order again. Pay with the test card.
3. You land on the order received page. Within seconds it shows the key (*Confirming your payment* first if the webhook is slower). The order is Completed with the Paddle transaction ID, a *Sales tax / VAT (collected by Paddle)* line if tax applied, and notes. Licenses › All licenses: the license is billed by Paddle with the subscription ID. Paddle tab › Recent webhook events lists the events.
4. Coupon: create a WooCommerce coupon (fixed amount), apply it at checkout, pay. In Paddle the transaction shows the discount; the subscription's next charge is the full price.
5. Trial: /pricing/ › Pro › **Start 14-day free trial**, a new email, Place order. The inline form shows $0.00 due today and asks for the card. After paying: order Completed at $0.00, license *Free trial*, 1 site. Try again with the same email (block and classic checkout): refused with *Buy without the free trial*. Try with another address at the same company domain: refused.
6. Trial conversion and renewal: in Paddle, on the trial subscription, bill now (or wait). A Completed renewal order appears in WooCommerce › Orders and in the customer's My Account › Orders, with the transaction ID and a note linking the first order; the license has its full site limit and a later expiry; no new key.
7. Failed renewal: in the sandbox, change the subscription's payment method to a declining test card (`4000 0000 0000 0002`) and bill now: the license shows *Past due*, the customer gets the payment failed email, and the first order has a note.
8. Upgrade: My Account › Licenses › **Upgrade to Agency** › checkout › Pay with Paddle. No overlay opens (the saved card is charged); the order is Completed with Paddle's prorated amount, the key is unchanged, and the Paddle subscription now has the Agency price. Repeat on a trialing license: nothing is charged and the trial continues.
9. Refunds: on a paid order click **Refund** for part of the amount, then for the rest. Paddle shows the adjustments; after approval nothing changes in WooCommerce (no second refund). In the Paddle dashboard refund another order: after approval it appears as a WooCommerce refund. A fully refunded first order cancels the subscription in Paddle and suspends the license.
10. Abandoned order: start a checkout, Place order and leave the page without paying. With WooCommerce › Settings › Products › Inventory › Hold stock set (for example 60 minutes), the order is cancelled after that time and the Paddle transaction shows as canceled.
11. Webhook replay: Paddle › Notifications › destination › Logs › replay a `transaction.completed`: Recent webhook events shows it once and no second license or order is created.
12. Switch Environment to Live only after all of the above, with the live API key, client-side token, prices (Create in Paddle again in live, then keep the WooCommerce prices equal), the approved live domain and the live destination's secret.

Signed test webhooks can also be sent from the command line, for example to replay an event body saved from Paddle:

```
php -r '$b=file_get_contents("event.json");$t=time();echo "ts=$t;h1=".hash_hmac("sha256","$t:$b",getenv("SECRET"));' > sig.txt
curl -X POST https://subtleforms.com/wp-json/sfls/v1/paddle/webhook -H "Content-Type: application/json" -H "Paddle-Signature: $(cat sig.txt)" --data-binary @event.json
```

### Legacy Paddle overlay (1.4.0 mode)

With **Paddle overlay (legacy)** selected, the pricing cards open the Paddle overlay directly (trial buttons first check `POST /wp-json/sfls/v1/paddle/eligibility`), the webhook creates the license from the subscription (with a second-trial backstop that cancels the subscription before any charge), and the customer lands on `/license-welcome/` (or a page with `[sfls_license_welcome]`), which polls `GET /wp-json/sfls/v1/paddle/checkout?txn=…` and shows the key to the browser that paid. No WooCommerce order is created. Plan changes happen directly from My Account with `PATCH /subscriptions/{id}`.

## API

| Method | Endpoint | Body / query |
|---|---|---|
| POST | `/license/activate` | `license_key`, `site_url` |
| POST | `/license/validate` | `license_key`, `site_url` |
| POST | `/license/deactivate` | `license_key`, `site_url` |
| GET | `/license/status` | `license_key`, `site_url` |
| GET | `/update/check` | `license_key`, `site_url`, `version`, optional `channel=beta` |
| GET | `/update/download` | signed link from `/update/package` (`version` since 1.6.0) |
| GET | `/update/package` | `lid`, `ptoken`, `site_url`, optional `channel`, optional `version` (302 to a fresh signed download); `license_key` and `site_url` are still accepted |
| GET | `/update/versions` | `license_key`, `site_url`, optional `channel=beta`: `{ status, valid, activated, channel, versions: [ { version, channel, latest, released_at, requires, tested, requires_php, size, sha256, changelog, package } ] }`, the versions this license may download |
| GET, POST | `/releases` | deploy token (`Authorization: Bearer`): list, or create from multipart `package` or `package_url` (see docs/CI.md) |
| GET, DELETE | `/releases/<version>` | deploy token |
| POST | `/releases/<version>/publish`, `/releases/<version>/yank` | deploy token |
| POST | `/github/webhook` | GitHub `release` and `ping` events, signed with `X-Hub-Signature-256` |
| POST | `/connect/exchange` | `code`, `site_url` |
| POST | `/paddle/webhook` | Paddle notification, signed with `Paddle-Signature` |
| POST | `/paddle/eligibility` | `email`, `plan`, optional `site`: `{ eligible, reason, message, trial_days }` |
| GET | `/paddle/checkout` | `txn`, `token`: the license a legacy Paddle checkout created; or `order`, `key` (1.5.0): the license of a WooCommerce order paid with Paddle (`{ ready, html, license_key, ... }`), or `{ ready: false }` |

Errors return 4xx with `{ "valid": false, "reason": "…", "error": "…" }`.

License payloads (activate, validate, status, connect exchange) carry `is_local`: true when the requesting site is a local or staging host that does not use a seat. Since 1.4.0 they also carry `billing`, `billing_status`, `trial`, `trial_ends_at`, `renews_at`, `cancels_at`, `renewal_amount` and `manage_url` (see Selling with Paddle).

Rate limits: activate, validate, status, package and versions allow 30 requests per 10 minutes per IP. A valid key on a site it is activated on is then limited per key and site, so nobody else can lock that site out; every other request (unknown key, a site the key is not on, an inactive license) counts against the key's failure budget of 30 times the sites it is activated on. Deactivate and connect exchange are limited per IP. Over the limit they answer `429` with `Retry-After` (seconds). Change it with the `sfls_rate_limit` filter (`array( 'limit' => 30, 'window' => 600 )`). Sites with `WP_ENVIRONMENT_TYPE` `local` skip it unless `sfls_rate_limit_enabled` returns true. Behind a proxy or CDN, tick **Proxy or CDN** in Licenses › Settings: the address is then read from `CF-Connecting-IP`, else the first address of `X-Forwarded-For` (only do this when all traffic comes through the proxy, because the headers can be forged otherwise), and the `sfls_client_ip` filter still has the last word. Without it every request looks like it comes from the proxy and shares one bucket. Since schema 8 the counters are rows in `wp_sfls_rate_limits`, raised atomically; `/update/check` has its own bucket (60 per 10 minutes per IP, unknown keys count as failures) and the checkout status polling of `/paddle/checkout` has its own bucket (240 per 10 minutes per IP and order), so neither uses up the allowance of the license API.

Free trials: the customer confirms the email with a one-time 6 digit code on the payment page before the Paddle form opens (valid 15 minutes, 5 tries, stored hashed; turn it off in Licenses › Settings). The trial is claimed when the order is created, inside a transaction and a lock per email, and given back when the order fails or is canceled; claims of an unpaid checkout lapse after 30 minutes. Throw-away addresses are refused (built-in list, more domains in Licenses › Settings, filter `sfls_disposable_domains`), and the company-domain rule skips 49 free mail domains (`sfls_free_mail_domains`).

## Database

| Version | Change |
|---|---|
| Next release (schema 8) | New table `wp_sfls_rate_limits` (bucket, window_start, hits). New table `wp_sfls_trial_codes` (email_hash, code_hash, attempts, expires_at, verified_until, created_at; hashes only). `wp_sfls_trial_claims` gains `order_id` and `status` (pending until the payment). `wp_sfls_licenses` gains indexes on `status` and `expires_at`. The private release folder's `.htaccess` is rewritten to Apache 2.4 syntax (`Require all denied`). `sfls_settings` gains `trusted_proxy`, `trial_verify`, `block_disposable` and `disposable_extra`. The Agency plan allows 25 sites; licenses already issued keep their stored limit until a Paddle event applies the plan. |
| 1.6.0 (schema 5) | New table `wp_sfls_releases` (id, version unique, channel, status, file, size, sha256, requires, tested, requires_php, changelog, source, git_ref, git_commit, notes, yank_reason, replaced_files, downloads, released_at, created_at, updated_at). On the first request after updating, the releases in the options `sfls_release` and `sfls_release_history` are copied into it as published stable releases (released_at from their date, size and sha256 from the files), and `sfls_releases_migrated` is set. The two options are left in place and only read as a fallback until that copy ran; nothing writes them any more. New table `wp_sfls_github_deliveries` (delivery_id, event, action, tag, result, message, release_version, payload, received_at, processed_at; the last 200 are kept). New options `sfls_release_pin`, `sfls_release_settings` (auto_publish), `sfls_deploy_tokens` (name, hash, scopes, created, last used, revoked; never the token) and `sfls_github` (repo, token, webhook secret, asset_pattern, auto_publish), none autoloaded. |
| 1.5.0 (schema 4, no table change) | `sfls_settings.sell_with` gains `woo_paddle` (the new default) next to `woocommerce` and `paddle` (legacy overlay); a 1.4.0 shop on `paddle` moves to `woo_paddle` once (option `sfls_migrated_150`) and `woocommerce_sfls_paddle_settings.enabled` is set to yes. New gateway option `woocommerce_sfls_paddle_settings` (enabled, title, description). Orders paid with Paddle get `_sfls_paddle_txn_id`, `_sfls_paddle_paid_txn`, `_sfls_paddle_kind`, `_sfls_paddle_customer_id`, `_sfls_paddle_subscription_id`, `_sfls_paddle_env`, `_sfls_paddle_adjustments` and `_sfls_paddle_notes`; renewal orders `_sfls_paddle_renewal` and `_sfls_parent_order`; upgrade orders `_sfls_paddle_upgrade_pending` until Paddle confirms; trial lines `_sfls_trial` (days) and a visible *Free trial* meta; refunds `_sfls_paddle_adjustment_id`; users `_sfls_paddle_customer_id` and `_sfls_paddle_customer_email`; coupons may carry `_sfls_paddle_discount_id`. Runs on the first request after updating (or on activation). |
| 1.4.0 (schema 4) | `wp_sfls_licenses` gains `billing` (paddle, woocommerce or manual; existing rows are set to woocommerce when they have an order, manual otherwise), `billing_status`, `trial`, `trial_started_at`, `trial_ends_at`, `renews_at`, `cancels_at`, `billing_amount`, `billing_currency`, `paddle_customer_id`, `paddle_subscription_id` (indexed), `paddle_price_id`, `paddle_transaction_id` (indexed), `checkout_token_hash` and `billing_synced_at`. New table `wp_sfls_trial_claims` (kind email, domain or site; value; license_id; created_at). New table `wp_sfls_paddle_events` (event_id, event_type, occurred_at, received_at, status, license_id, note). New options `sfls_paddle` (Paddle keys and plan mapping, not autoloaded) and `sfls_paddle_rejected`; `sfls_settings` gains `sell_with` (woocommerce), `renewal_notice` (14,3) and `trial_notice` (3). Applied by dbDelta on activation or on the first request after updating; the `/license-welcome/` rewrite rule is flushed on the version change. |
| 1.3.0 (schema 3) | New table `wp_sfls_activation_log` (license_id, domain, event, source, ip, created_at). New table `wp_sfls_download_tokens` (token, used_at). `sfls_settings` gains `simplify_account` and `simple_checkout` (both on), and `support_email` defaults to support@ your domain when empty. Orders get `_sfls_issued`; upgrade lines get `_sfls_upgrade_credit` and `_sfls_upgrade_keep_expiry`. The tables are created on activation or on the first request after updating. |

## Uninstall

Deleting the plugin keeps all data unless **Delete all licenses, activations and release files when the plugin is deleted** is ticked in **Licenses › Settings**. WooCommerce products and orders are never removed.

## Testing Pro without a server

On a site whose environment type is `local` or `development` (Local by Flywheel sets this automatically, or add `define( 'WP_ENVIRONMENT_TYPE', 'local' );` to wp-config.php), these keys work in **SubtleForms › Settings › License**:

- `SF-DEV-STARTER`
- `SF-DEV-PRO`
- `SF-DEV-AGENCY`

They are rejected on production sites. To point Pro at a staging license server, add `define( 'SUBTLEFORMS_LICENSE_API', 'https://staging.subtleforms.com/wp-json/sfls/v1/' );`.
