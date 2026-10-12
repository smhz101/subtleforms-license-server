# SubtleForms License Server

Runs on **subtleforms.com**. It sells SubtleForms Pro through WooCommerce, issues license keys, connects customer sites to their account, gives customers a members area, answers activation requests from the Pro plugin, and ships Pro updates to licensed sites.

SubtleForms Pro talks to it at `https://subtleforms.com/wp-json/sfls/v1/`.

## 1. Install

1. Install WordPress and WooCommerce on subtleforms.com (HTTPS required).
2. Upload `subtleforms-license-server.zip` in **Plugins › Add New › Upload** and activate it. Activation creates the tables, registers `/connect-site/` and the My Account pages, and schedules the daily reminder check. (If `/connect-site/` or **My Account › Licenses** ever returns a 404, go to **Settings › Permalinks** and click **Save**.)
3. In **Licenses › Settings**, set the pricing page URL and support email.

On Nginx, block direct access to the release folder (Apache is handled by the bundled `.htaccess`):

```
location ^~ /wp-content/uploads/sfls-private/ { deny all; }
```

## 2. Set up pricing (Licenses › Products)

One form, one row per plan (Starter, Pro, Agency):

| Column | What it creates |
|---|---|
| Sites | Site limit for the plan (0 = unlimited). Prefilled from the plan. |
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
6. Refunded or cancelled orders suspend the keys they issued.

Local and staging hosts (`localhost`, `*.local`, `*.test`, `staging.*`, `dev.*`, WP Engine/Kinsta staging, InstaWP) never use a site slot.

## 5. Members area (WooCommerce My Account)

* **Dashboard**: a SubtleForms Pro summary with active licenses, next renewal date and the latest Pro version with a download link.
* **Licenses** (`/my-account/licenses/`): one card per license with plan, status, the full key with a Copy button, expiry, sites used, the connected sites with last seen and a Deactivate button, and Download Pro, Renew and Upgrade (with today's prorated price), plus a three-step "How to install Pro" panel.
* Customers whose orders only contain licenses do not see the Addresses and Downloads tabs (Licenses › Settings › Simplify My Account).
* **Pro downloads** (`/my-account/pro-downloads/`): the latest release with its changelog and a download button, plus older versions. Zips are streamed only to signed-in customers with a valid license; the storage path is never exposed.

## 6. Connecting a site (used by SubtleForms Pro)

Pro sends the admin to:

```
https://subtleforms.com/connect-site/?site=<home url>&return=<wp-admin callback>&state=<random>&name=<site name>&v=<pro version>
```

* `return` must be http(s) and on the same host as `site` (compared without `www.`), otherwise an error page is shown.
* Signed-out visitors are sent to the My Account login and come back afterwards.
* The customer picks one of their licenses (matched by user ID or email). Suspended, expired or full licenses are disabled with the reason, unless the site is already active on them or is a local/staging host.
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

## 9. Ship a Pro update

**Licenses › Pro releases**: upload the new `subtleforms-pro-x.y.z.zip`, add the changelog (one change per line starting with `* `), and publish. The zip must contain `subtleforms-pro/subtleforms-pro.php`; Version, Requires at least, Tested up to (or readme.txt) and Requires PHP are read from it, and a version lower than the current release is refused (the same version may be re-uploaded to replace its package). Licensed sites see it in **Dashboard › Updates** within 12 hours, and customers can download it from **My Account › Pro downloads**. Earlier releases stay listed and downloadable.

Update downloads: `/update/check` returns `package` = `/update/package?license_key&site_url`. That address checks the license and the site and redirects to a signed `/update/download` link that expires after 15 minutes and works once (used tokens are kept in `wp_sfls_download_tokens`). WordPress follows the redirect when it installs the update, so a cached update check never hands out a dead link.

## Selling with Paddle (1.4.0)

Paddle Billing (API v2, not Paddle Classic) can sell the licenses instead of WooCommerce. Paddle is the merchant of record: it takes the payment, handles tax and sends receipts. Every plan is a yearly subscription that renews automatically, with an optional 7 or 14 day free trial. WooCommerce stays installed for My Account, and licenses sold through WooCommerce before keep working unchanged.

### Setup (do everything in the sandbox first)

1. **Sandbox account.** Sign in at https://sandbox-vendors.paddle.com (a separate login from live). Everything below is repeated later in the live account at https://vendors.paddle.com, with its own keys, prices and secret.
2. **API key.** Paddle › Developer tools › Authentication › API keys › New API key. Give it these permissions: Subscriptions read and write, Customers read, Customer portal sessions write, Transactions read, Products read and write, Prices read and write. Copy it (it starts with `pdl_sdbx_apikey_` in the sandbox).
3. **Client-side token.** Paddle › Developer tools › Authentication › Client-side tokens › New client-side token. Copy it (`test_…` in the sandbox, `live_…` in live).
4. **Default payment link.** Paddle › Checkout › Checkout settings: set the default payment link to your site (for example `https://subtleforms.com/pricing/`) and approve the domain if Paddle asks. Paddle refuses to open checkouts on unapproved domains.
5. **Plugin settings.** WordPress › Licenses › Settings › Paddle: Environment = Sandbox, paste the API key and the client-side token, click **Save Paddle settings**, then **Test connection**.
6. **Webhook.** Paddle › Developer tools › Notifications › New destination. Type: Webhook. URL: the **Webhook URL** shown on the Paddle tab (`https://subtleforms.com/wp-json/sfls/v1/paddle/webhook`). Usage type: Platform. Events: `subscription.created`, `subscription.trialing`, `subscription.activated`, `subscription.updated`, `subscription.past_due`, `subscription.paused`, `subscription.resumed`, `subscription.canceled`, `transaction.completed`, `transaction.payment_failed`. Save, open the destination, copy its **secret key** (`pdl_ntfset_…`) into **Webhook secret** on the Paddle tab and save. Until the secret is set the endpoint answers 503.
7. **Products and prices.** Either:
   * **Automatic:** Licenses › Products, fill in the yearly prices of the WooCommerce wizard (they are reused), then in **Paddle products and prices** pick 7 days, 14 days or No trial per plan, check the currency and click **Create in Paddle**. It creates one product per plan (tax category Standard) with a yearly price, plus a second yearly price with the trial (`trial_period` in days, payment method required), and maps them.
   * **By hand in Paddle:** Catalog › Products › New product per plan (SubtleForms Pro Starter, SubtleForms Pro, SubtleForms Pro Agency), tax category Standard. On each product add a price: billing period Yearly, no trial. For plans with a trial add a second price: Yearly, trial period 7 or 14 days, **Require payment method** on. Then on the Paddle tab click **Sync products** and pick, per plan, the yearly price and the trial price, and **Save plans** (amounts, currency and trial days are read from Paddle).
8. **Sell with Paddle.** Paddle tab › Sell licenses with: Paddle › Save. The pricing block or `[sfls_pricing]` now shows Paddle plans. The notice on the tab says what is missing if anything is not set.
9. **Run the sandbox checklist below.** Then repeat steps 2 to 7 in the live account with Environment = Live (sandbox and live keys, tokens, prices and secrets are never shared).

### How it works

* **Pricing:** a plan with a trial shows *Start 14-day free trial* and *Then $79/year, cancel anytime*. The button asks for an email (or uses the signed-in account), checks `POST /wp-json/sfls/v1/paddle/eligibility` and opens the Paddle overlay checkout with the trial price, the email (locked) and custom data (`sfls_plan`, `sfls_trial`, `sfls_token`, `sfls_email`). Customers who already had a trial get *Buy now* with the reason and buy the yearly price. Paddle.js is loaded from `https://cdn.paddle.com/paddle/v2/paddle.js` only on pages with pricing.
* **One trial per customer:** per email (case, `+tags` and Gmail dots ignored), per company email domain (on free mail such as gmail.com, outlook.com, yahoo.com, hotmail.com, icloud.com and proton.me only the exact address), and per site (a domain activated on a trial license cannot be activated on another trial license; local and staging hosts excepted). The webhook checks again: a second trial is canceled through `POST /subscriptions/{id}/cancel` with `effective_from: immediately` before any charge, no license is issued, and the customer is emailed. The license editor has **Allow another trial** to lift it.
* **After checkout:** the browser goes to `/license-welcome/?txn=…`, which polls `GET /wp-json/sfls/v1/paddle/checkout` until the webhook has issued the license, then shows the key, Download Pro, the install steps and the trial end date. Only the browser that paid (it holds the checkout token in the URL fragment) or the signed-in owner sees the key. To use a themed page instead, add `[sfls_license_welcome]` to a page and return its URL from the `sfls_welcome_url` filter.
* **Webhook:** verifies `Paddle-Signature` (`ts=…;h1=…`, HMAC-SHA256 of `ts:raw body` with the secret, several `h1` accepted during secret rotation, timestamps older or newer than 5 minutes refused; filter `sfls_paddle_webhook_tolerance`), stores each `event_id` once in `wp_sfls_paddle_events`, and skips subscription events older than the last one applied to that license. A failure answers 500 so Paddle retries.
* **License states:**

  | Paddle | License |
  |---|---|
  | `trialing` (created) | New license, status active, **1 site**, `trial_ends_at`, `expires_at` = trial end + 3 days. A WordPress account is created or matched by email (new accounts get the set-password email). Welcome email with the key. |
  | `active` / first `transaction.completed` | Full plan sites, trial over, `expires_at` = end of the billing period + 3 days, `renews_at` = next charge. |
  | renewal `transaction.completed` | `expires_at` moves to the end of the new period + 3 days. Payment email. |
  | `past_due` | Stays valid until the paid period (+ 3 days, then the usual 14 day grace) ends. Payment failed email with an Update payment method link. |
  | scheduled cancel (`subscription.updated` with `scheduled_change.action = cancel`) | `cancels_at` set, no renewal date, email. Stays active until then. |
  | `canceled` | A paid license keeps its expiry. A trial canceled before paying ends at once (no grace period). |
  | `updated` with another plan's price | Plan, sites and features change, same key, a note on the license. |

* **Members area:** Paddle license cards show *Trial ends on*, *Renews on*, *Cancels on* or *Past due*, and the buttons **Update payment method** (a fresh customer portal session, `POST /customers/{id}/portal-sessions`), **Cancel auto-renew** (`POST /subscriptions/{id}/cancel`, next billing period), **Resume** (`PATCH /subscriptions/{id}` with `scheduled_change: null`) and **Upgrade to …** (`PATCH /subscriptions/{id}` with the new price and `proration_billing_mode: prorated_immediately`; during a trial the trial price of the new plan with `do_not_bill`). Limits rise at once, the key stays.
* **Emails** (Paddle sends its own receipts): license key and install steps, trial ends soon (default 3 days before, with amount, date and how to cancel), renewal coming up (default 14 and 3 days before), payment received, payment failed, auto-renew turned off, second trial canceled, and license ended (the existing after-expiry email). Days are set on the Paddle tab; every template can be sent as a test from the General tab. Manual-renewal reminders are not sent for Paddle licenses.
* **Pro:** license responses add `trial`, `trial_ends_at`, `renews_at`, `cancels_at`, `billing` (`paddle`, `woocommerce` or `manual`), `billing_status`, `renewal_amount` and `manage_url`. During a trial `valid` is true and `status` is `valid`, so older Pro versions keep working. SubtleForms Pro 2.3.1 shows them on its License screen.

### Sandbox test checklist (owner, before going live)

Paddle could not be reached from the development machine, so these steps were implemented against the Paddle docs and tested with mocked HTTP; run them once in the sandbox. Test cards: https://developer.paddle.com/concepts/payment-methods/credit-debit-card (for example `4242 4242 4242 4242`, any future date, CVC `100`).

1. Paddle tab: **Test connection** says *Connected to the Paddle sandbox*. **Sync products** lists your prices; save the mapping.
2. Signed out, open /pricing/: Pro shows *Start 14-day free trial* and *Then $…/year*. Click it, enter a new email, Continue: the Paddle overlay opens with the email filled in and *0.00 due today*. Pay with the test card.
3. You land on /license-welcome/, which shows the key within a few seconds and the trial end date. Paddle tab › Recent webhook events lists `subscription.created` (created), `transaction.completed` and others. The license editor shows Paddle subscription, Free trial, 1 site.
4. Mailbox: welcome email with the key; set-password email for the new account. Sign in: My Account › Licenses shows *Trial ends on …*, *Trial covers 1 site*, Update payment method and Cancel auto-renew.
5. Back on /pricing/, start a trial with the same email, then with another address at the same company domain: both get *Buy now* with the reason. To test the webhook backstop, in Paddle open a checkout for the trial price yourself (or temporarily filter `sfls_trial_eligibility` to allow it on the pricing page): the new subscription shows as canceled in Paddle, nothing is charged, the customer gets *We could not start your SubtleForms Pro trial*, and no license is issued.
6. Connect the trial key to a site, then start another trial for a different person and try to connect the same site: refused with *This site already had a SubtleForms Pro free trial*.
7. End the trial early: in Paddle open the subscription and use the sandbox option to bill now (or wait for the trial to end; sandbox keeps real dates). The license moves to the full site limit, the trial flag goes, a *Payment received* email arrives.
8. My Account: **Upgrade to Agency** charges the prorated difference (check the transaction in Paddle), the key is the same and the limit is unlimited. **Cancel auto-renew** shows *Cancels on …* and sends an email; **Resume** brings back *Renews on …*. **Update payment method** opens Paddle's customer portal.
9. Failed payment: update the card to a declining test card (`4000 0000 0000 0002`) and bill the subscription now: the license shows *Past due* and the customer gets *Payment failed* with an Update payment method link.
10. Cancel a trial immediately in Paddle: the license ends at once (status Expired).
11. Licenses › Settings › General › Test reminder: send each Paddle billing email to yourself and check the wording and the amounts.
12. Webhook replay: in Paddle › Notifications › the destination › Logs, replay an event: Recent webhook events shows it once (a replay with the same event ID is not handled twice).
13. Upgrade during a trial: on a trialing subscription click Upgrade to Agency and check in Paddle that the trial is kept and nothing is charged (the code sends `do_not_bill`; confirm Paddle accepts it for trialing subscriptions).
14. Switch Environment to Live only after all of the above, with the live API key, client-side token, prices (Create in Paddle again in live) and the live destination's secret.

Signed test webhooks can also be sent from the command line, for example to replay an event body saved from Paddle:

```
php -r '$b=file_get_contents("event.json");$t=time();echo "ts=$t;h1=".hash_hmac("sha256","$t:$b",getenv("SECRET"));' > sig.txt
curl -X POST https://subtleforms.com/wp-json/sfls/v1/paddle/webhook -H "Content-Type: application/json" -H "Paddle-Signature: $(cat sig.txt)" --data-binary @event.json
```

## API

| Method | Endpoint | Body / query |
|---|---|---|
| POST | `/license/activate` | `license_key`, `site_url` |
| POST | `/license/validate` | `license_key`, `site_url` |
| POST | `/license/deactivate` | `license_key`, `site_url` |
| GET | `/license/status` | `license_key`, `site_url` |
| GET | `/update/check` | `license_key`, `site_url`, `version` |
| GET | `/update/download` | signed link from `/update/check` |
| GET | `/update/package` | `license_key`, `site_url` (302 to a fresh signed download) |
| POST | `/connect/exchange` | `code`, `site_url` |
| POST | `/paddle/webhook` | Paddle notification, signed with `Paddle-Signature` |
| POST | `/paddle/eligibility` | `email`, `plan`, optional `site`: `{ eligible, reason, message, trial_days }` |
| GET | `/paddle/checkout` | `txn`, `token`: the license a Paddle checkout created, or `{ ready: false }` |

Errors return 4xx with `{ "valid": false, "reason": "…", "error": "…" }`.

License payloads (activate, validate, status, connect exchange) carry `is_local`: true when the requesting site is a local or staging host that does not use a seat. Since 1.4.0 they also carry `billing`, `billing_status`, `trial`, `trial_ends_at`, `renews_at`, `cancels_at`, `renewal_amount` and `manage_url` (see Selling with Paddle).

Rate limits: activate, validate, status and package allow 30 requests per 10 minutes per IP and per license key; deactivate and connect exchange per IP. Over the limit they answer `429` with `Retry-After` (seconds). Change it with the `sfls_rate_limit` filter (`array( 'limit' => 30, 'window' => 600 )`). Sites with `WP_ENVIRONMENT_TYPE` `local` skip it unless `sfls_rate_limit_enabled` returns true. Behind a proxy or CDN, filter `sfls_client_ip` to return the visitor address the proxy forwards, otherwise every request looks like it comes from the proxy.

## Tests

Integration tests run inside a real WordPress + WooCommerce site with this plugin active. They create their own users, products, orders and licenses (all `@sfls-test.invalid`) and delete them afterwards.

```
SFLS_WP_PATH=/path/to/wordpress /path/to/phpunit -c phpunit.xml
```

The Paddle tests (`PaddleSignatureTest`, `PaddleWebhookTest`, `TrialEligibilityTest`, `PaddleBillingTest`) never reach Paddle: the API is answered through the `pre_http_request` filter and every call is recorded so the tests can assert it.

PHPUnit 9.6 is enough (for example the one in the SubtleForms free plugin's `vendor/bin`).

## Database

| Version | Change |
|---|---|
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
