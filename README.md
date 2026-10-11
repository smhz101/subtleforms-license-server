# SubtleForms License Server

Runs on **subtleforms.com**. It sells SubtleForms Pro through WooCommerce, issues license keys, answers activation requests from the Pro plugin, and ships Pro updates to licensed sites.

SubtleForms Pro 2.0 already talks to it at `https://subtleforms.com/wp-json/sfls/v1/`.

## 1. Install

1. Install WordPress and WooCommerce on subtleforms.com (HTTPS required).
2. Upload `subtleforms-license-server.zip` in **Plugins › Add New › Upload** and activate it.
3. Go to **Settings › Permalinks** and click **Save** once (registers the My Account › Licenses page).

On Nginx, block direct access to the release folder (Apache is handled by the bundled `.htaccess`):

```
location ^~ /wp-content/uploads/sfls-private/ { deny all; }
```

## 2. Create the products

For each plan, create a **Simple, Virtual** product in WooCommerce. In **Product data › General**:

| Product | SubtleForms license | Plan | Sites | Duration (months) |
|---|---|---|---|---|
| SubtleForms Pro Starter | ✓ | Starter | (empty = 1) | 12 |
| SubtleForms Pro | ✓ | Pro | (empty = 5) | 12 |
| SubtleForms Pro Agency | ✓ | Agency | (empty = unlimited) | 12 |

For renewals, duplicate a product, tick **Renewal**, and price it as your renewal price. A renewal extends the customer's existing key by the duration instead of issuing a new one.

Plans and the features each unlocks are defined in `includes/class-sfls-plans.php` and must match `PLAN_FEATURES` in SubtleForms Pro's `LicenseManager`.

## 3. What happens on a sale

1. The order reaches **Completed** (virtual-only orders are handled at **Processing** too).
2. One key per quantity is created, e.g. `SFP-1A2B3C4D-…`.
3. The key is shown on the thank-you page, in the order emails, and under **My Account › Licenses**, where the customer can see and deactivate their sites.
4. Refunded or cancelled orders suspend their keys.

Local and staging hosts (`localhost`, `*.local`, `*.test`, `staging.*`, `dev.*`, WP Engine/Kinsta staging, InstaWP) never use a site slot.

## 4. Issue keys by hand

**Licenses › Issue license** creates complimentary, partner or test keys. From the command line:

```
wp sfls create --plan=agency --email=you@example.com --months=0
wp sfls list
```

## 5. Ship a Pro update

**Licenses › Pro releases**: upload the new `subtleforms-pro-x.y.z.zip`, set the version and changelog, and publish. Licensed sites see it in **Dashboard › Updates** within 12 hours. Downloads use one-hour signed links tied to the license and site.

## API

| Method | Endpoint | Body / query |
|---|---|---|
| POST | `/license/activate` | `license_key`, `site_url` |
| POST | `/license/validate` | `license_key`, `site_url` |
| POST | `/license/deactivate` | `license_key`, `site_url` |
| GET | `/license/status` | `license_key`, `site_url` |
| GET | `/update/check` | `license_key`, `site_url`, `version` |
| GET | `/update/download` | signed link from `/update/check` |

Errors return 4xx with `{ "reason": "…", "error": "…" }`. Requests are limited to 30 per minute per IP.

## Testing Pro without a server

On a site whose environment type is `local` or `development` (Local by Flywheel sets this automatically, or add `define( 'WP_ENVIRONMENT_TYPE', 'local' );` to wp-config.php), these keys work in **SubtleForms › Settings › License**:

- `SF-DEV-STARTER`
- `SF-DEV-PRO`
- `SF-DEV-AGENCY`

They are rejected on production sites. To point Pro at a staging license server, add `define( 'SUBTLEFORMS_LICENSE_API', 'https://staging.subtleforms.com/wp-json/sfls/v1/' );`.
