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

Click **Save products**. Products are simple, virtual and sold one at a time. Saving again updates the same products (their IDs are kept in the `sfls_products` option); products you set up by hand before are adopted instead of duplicated. Clearing a price moves that product to draft.

Each license product carries this meta, which you can also set by hand in **Product data › General**:

| Meta | Values |
|---|---|
| `_sfls_enabled` | `yes` |
| `_sfls_plan` | `starter`, `pro`, `agency` |
| `_sfls_sites` | site limit, empty = plan default, `0` = unlimited |
| `_sfls_months` | `12` yearly, `0` lifetime |
| `_sfls_renewal` | `yes` for renewal products |

Plans and the features each unlocks are defined in `includes/class-sfls-plans.php` and must match `PLAN_FEATURES` in SubtleForms Pro's `LicenseManager`.

## 3. Shortcodes for the marketing site

| Shortcode | Output |
|---|---|
| `[sfls_pricing]` | Pricing cards from the license products: price, "/year" or "one-time", sites, features, and a Buy button that goes straight to checkout. Pro is highlighted as "Most popular". When lifetime products exist a Yearly/Lifetime toggle appears. Attributes: `period="yearly"` or `"lifetime"` (which one shows first), `popular="pro"`. |
| `[sfls_changelog]` | Every Pro release, newest first, with date and changes. `limit="3"` shows only the latest three. |
| `[sfls_latest_version]` | The current Pro version number, as plain text. |

Styles load only on pages that use a shortcode and are scoped under `.sfls`. Override colours with custom properties, for example `.sfls { --sfls-accent: #111; }`.

## 4. What happens on a sale

1. The order reaches **Completed** (virtual-only orders are handled at **Processing** too).
2. A new purchase creates one key per quantity, e.g. `SFP-1A2B3C4D-…`.
3. A **Renew** started from My Account extends exactly that license. A renewal product bought another way extends the customer's license for that plan that expires first.
4. An **Upgrade** started from My Account moves the existing key to the bigger plan and its site limit. The expiry is kept, or pushed to a full year from today if that is later; a lifetime upgrade makes it lifetime. No new key is issued.
5. Keys are shown on the thank-you page, in the order emails and in **My Account › Licenses**.
6. Refunded or cancelled orders suspend the keys they issued.

Local and staging hosts (`localhost`, `*.local`, `*.test`, `staging.*`, `dev.*`, WP Engine/Kinsta staging, InstaWP) never use a site slot.

## 5. Members area (WooCommerce My Account)

* **Dashboard**: a SubtleForms Pro summary with active licenses, next renewal date and the latest Pro version with a download link.
* **Licenses** (`/my-account/licenses/`): one card per license with plan, status, the full key with a Copy button, expiry, sites used, the connected sites with last seen and a Deactivate button, and Renew, Upgrade and Download Pro.
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

**Licenses › Issue license** creates complimentary, partner or test keys. **Licenses** lists every key with search, status filters and overview cards; click a key to edit plan, sites, expiry, status, email and notes, see activations, deactivate sites, regenerate the key or delete it. From the command line:

```
wp sfls create --plan=agency --email=you@example.com --months=0
wp sfls create --plan=pro --user=customer-login
wp sfls list
wp sfls remind --dry-run
```

## 9. Ship a Pro update

**Licenses › Pro releases**: upload the new `subtleforms-pro-x.y.z.zip`, set the version and changelog (one change per line starting with `* `), and publish. Licensed sites see it in **Dashboard › Updates** within 12 hours, and customers can download it from **My Account › Pro downloads**. Earlier releases stay listed and downloadable. Update downloads use one-hour signed links tied to the license and site.

## API

| Method | Endpoint | Body / query |
|---|---|---|
| POST | `/license/activate` | `license_key`, `site_url` |
| POST | `/license/validate` | `license_key`, `site_url` |
| POST | `/license/deactivate` | `license_key`, `site_url` |
| GET | `/license/status` | `license_key`, `site_url` |
| GET | `/update/check` | `license_key`, `site_url`, `version` |
| GET | `/update/download` | signed link from `/update/check` |
| POST | `/connect/exchange` | `code`, `site_url` |

Errors return 4xx with `{ "valid": false, "reason": "…", "error": "…" }`. Requests are limited to 30 per minute per IP.

## Uninstall

Deleting the plugin keeps all data unless **Delete all licenses, activations and release files when the plugin is deleted** is ticked in **Licenses › Settings**. WooCommerce products and orders are never removed.

## Testing Pro without a server

On a site whose environment type is `local` or `development` (Local by Flywheel sets this automatically, or add `define( 'WP_ENVIRONMENT_TYPE', 'local' );` to wp-config.php), these keys work in **SubtleForms › Settings › License**:

- `SF-DEV-STARTER`
- `SF-DEV-PRO`
- `SF-DEV-AGENCY`

They are rejected on production sites. To point Pro at a staging license server, add `define( 'SUBTLEFORMS_LICENSE_API', 'https://staging.subtleforms.com/wp-json/sfls/v1/' );`.
