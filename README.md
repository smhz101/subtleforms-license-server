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

Errors return 4xx with `{ "valid": false, "reason": "…", "error": "…" }`.

License payloads (activate, validate, status, connect exchange) carry `is_local`: true when the requesting site is a local or staging host that does not use a seat.

Rate limits: activate, validate, status and package allow 30 requests per 10 minutes per IP and per license key; deactivate and connect exchange per IP. Over the limit they answer `429` with `Retry-After` (seconds). Change it with the `sfls_rate_limit` filter (`array( 'limit' => 30, 'window' => 600 )`). Sites with `WP_ENVIRONMENT_TYPE` `local` skip it unless `sfls_rate_limit_enabled` returns true. Behind a proxy or CDN, filter `sfls_client_ip` to return the visitor address the proxy forwards, otherwise every request looks like it comes from the proxy.

## Tests

Integration tests run inside a real WordPress + WooCommerce site with this plugin active. They create their own users, products, orders and licenses (all `@sfls-test.invalid`) and delete them afterwards.

```
SFLS_WP_PATH=/path/to/wordpress /path/to/phpunit -c phpunit.xml
```

PHPUnit 9.6 is enough (for example the one in the SubtleForms free plugin's `vendor/bin`).

## Database

| Version | Change |
|---|---|
| 1.3.0 (schema 3) | New table `wp_sfls_activation_log` (license_id, domain, event, source, ip, created_at). New table `wp_sfls_download_tokens` (token, used_at). `sfls_settings` gains `simplify_account` and `simple_checkout` (both on), and `support_email` defaults to support@ your domain when empty. Orders get `_sfls_issued`; upgrade lines get `_sfls_upgrade_credit` and `_sfls_upgrade_keep_expiry`. The tables are created on activation or on the first request after updating. |

## Uninstall

Deleting the plugin keeps all data unless **Delete all licenses, activations and release files when the plugin is deleted** is ticked in **Licenses › Settings**. WooCommerce products and orders are never removed.

## Testing Pro without a server

On a site whose environment type is `local` or `development` (Local by Flywheel sets this automatically, or add `define( 'WP_ENVIRONMENT_TYPE', 'local' );` to wp-config.php), these keys work in **SubtleForms › Settings › License**:

- `SF-DEV-STARTER`
- `SF-DEV-PRO`
- `SF-DEV-AGENCY`

They are rejected on production sites. To point Pro at a staging license server, add `define( 'SUBTLEFORMS_LICENSE_API', 'https://staging.subtleforms.com/wp-json/sfls/v1/' );`.
