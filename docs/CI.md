# Publishing SubtleForms Pro from Git and CI

SubtleForms License Server 1.6.0 accepts new Pro releases in three ways:

1. **Upload by hand** in **Licenses › Pro releases** (or `wp sfls release add`).
2. **Direct upload from CI** with a deploy token: the CI job builds the zip and POSTs it to `/wp-json/sfls/v1/releases`. This is what the GitHub Actions workflow in the SubtleForms Pro repository (`.github/workflows/release.yml`) does.
3. **GitHub release webhook**: you publish a release on GitHub with the zip attached, GitHub calls `/wp-json/sfls/v1/github/webhook`, and the server downloads the zip itself.

Use path 2 or path 3, not both for the same tag. If both run, the second one finds the same version with the same SHA-256 and does nothing, so it is harmless, but path 2 alone is simpler.

Every way runs the same checks: the zip must contain `subtleforms-pro/subtleforms-pro.php` with a valid `Version:` header, it must be at most 50 MB (`sfls_release_max_size` filter), and its SHA-256 and size are stored. Zips are kept in `wp-content/uploads/sfls-private/` (denied by `.htaccess`; on Nginx add `location ^~ /wp-content/uploads/sfls-private/ { deny all; }`) and are only deleted when you delete that release.

Channels: a version with a hyphen (`2.4.0-beta.1`, `2.4.0-rc.1`) or a GitHub pre-release goes to the **beta** channel. Sites are offered the highest published **stable** version unless they ask for `channel=beta`. Drafts and yanked versions are never offered.

---

## Path 2: direct upload from CI (recommended)

### One-time setup

1. On the license server (subtleforms.com), open **Licenses › Settings › Releases › Deploy tokens**, enter a name such as `GitHub Actions (subtleforms-pro)` and click **Create token**. Copy the token (it starts with `sflsdt_`). It is shown only once; the server stores a hash. Or on the server: `wp sfls release token create "GitHub Actions" --porcelain`.
2. In the SubtleForms Pro repository on GitHub, open **Settings › Secrets and variables › Actions** and add two repository secrets:
   * `SFLS_URL` = `https://subtleforms.com` (no trailing slash)
   * `SFLS_DEPLOY_TOKEN` = the token from step 1
3. Optional: add a repository **variable** `SFLS_PUBLISH` = `false` if releases should arrive as drafts for you to publish by hand. Without it the workflow publishes right away. (When a request does not send `publish`, the server uses **Auto publish CI releases** in Licenses › Settings › Releases.)
4. Make sure the server accepts the upload size: the Pro zip is about 4.5 MB, so PHP needs `upload_max_filesize` and `post_max_size` of at least 8M (the PHP default of 2M is too small; the API then answers `413 upload_failed`).

### Releasing a version

1. Bump the version in `subtleforms-pro.php` (header and `SUBTLEFORMS_PRO_VERSION`), `package.json` and `readme.txt` (Stable tag and a `= 2.4.0 - date =` changelog section). Commit.
2. Tag and push:

   ```
   git tag -a v2.4.0 -m "* New: something" -m "* Fixed: something else"
   git push origin v2.4.0
   ```

   The changelog sent to the server is the annotated tag message, or, for a lightweight tag, the `= 2.4.0 ... =` section of `readme.txt`.
3. The **Release** workflow checks that the tag matches `SUBTLEFORMS_PRO_VERSION` (it fails otherwise), runs `npm ci` (or `npm install` while `package-lock.json` is not committed), `npm run build`, `composer install --no-dev`, and `bin/build-zip.sh dist`, checks that the zip contains the plugin and dompdf, attaches `subtleforms-pro-2.4.0.zip` to a GitHub Release (a pre-release for tags with a hyphen), and POSTs it to the server. A non-2xx answer fails the job and the server's JSON answer is printed in the log.
4. The release shows up in **Licenses › Pro releases** with source **CI API**, the tag and the commit.

Note: `bin/build-zip.sh` in the Pro repository reads the version with the pattern `[0-9.]+`, so a pre-release version such as `2.4.0-beta.1` does not build yet. Change that `sed` pattern to `[^']+` in the Pro repository before tagging a beta.

### Sample curl

```
curl --fail-with-body -X POST "https://subtleforms.com/wp-json/sfls/v1/releases" \
  -H "Authorization: Bearer $SFLS_DEPLOY_TOKEN" \
  -F "package=@dist/subtleforms-pro-2.4.0.zip;type=application/zip" \
  -F "sha256=$(sha256sum dist/subtleforms-pro-2.4.0.zip | cut -d' ' -f1)" \
  -F "changelog=<CHANGELOG.txt" \
  -F "channel=stable" \
  -F "git_ref=v2.4.0" \
  -F "git_commit=$(git rev-parse HEAD)" \
  -F "publish=true"
```

Answers:

| Status | `result` / `code` | Meaning |
|---|---|---|
| 201 | `created` | New release stored. |
| 200 | `existing` | The same version with the same SHA-256 is already stored. Nothing changed (safe to re-run a job). |
| 201 | `replaced` | `replace=true` was sent and the package of an existing version was replaced. The old zip is kept until the release is deleted. |
| 409 | `version_exists` | The version exists with a different package. Send `replace=true` if that is intended. |
| 422 | `sha256_mismatch`, `bad_layout`, `no_version`, `bad_zip` | The zip failed a check. |
| 413 | `too_large`, `upload_failed` | Over 50 MB, or over the PHP upload limit. |
| 401 | `missing_token`, `invalid_token` | No token, a wrong token or a revoked token. |
| 429 | `rate_limited` | Too many failed tokens from this IP (10 in 15 minutes by default, `sfls_token_auth_limit` filter). Wait for `Retry-After` seconds. |
| 403 | `https_required` | The API only works over https (local sites excepted, `sfls_release_api_allow_http` filter). |

Instead of a file, CI can send a JSON body with `package_url` (https on github.com, api.github.com, codeload.github.com or *.githubusercontent.com by default; `sfls_release_download_hosts` filter):

```
curl --fail-with-body -X POST "https://subtleforms.com/wp-json/sfls/v1/releases" \
  -H "Authorization: Bearer $SFLS_DEPLOY_TOKEN" -H "Content-Type: application/json" \
  -d '{"package_url":"https://github.com/OWNER/subtleforms-pro/releases/download/v2.4.0/subtleforms-pro-2.4.0.zip","git_ref":"v2.4.0","publish":true}'
```

(A private repository's release download needs a token, so for private repositories use the multipart upload or the webhook path.)

### Other endpoints (same token)

```
GET    /wp-json/sfls/v1/releases                     every release, plus latest, latest_beta and pinned
GET    /wp-json/sfls/v1/releases/2.4.0
POST   /wp-json/sfls/v1/releases/2.4.0/publish
POST   /wp-json/sfls/v1/releases/2.4.0/yank          reason=...
DELETE /wp-json/sfls/v1/releases/2.4.0               removes the release and its zip
```

On servers that treat a path ending in `.0` as a file (PHP's built-in server, some Nginx setups with a static-file `location` for extensions), use `https://subtleforms.com/?rest_route=/sfls/v1/releases/2.4.0` instead.

---

## Path 3: GitHub release webhook

Use this when you prefer to build and publish the release on GitHub (by hand or with your own workflow) and let the server pull the zip.

### One-time setup

1. On the license server, open **Licenses › Settings › Releases › GitHub connection**:
   * **Repository**: `OWNER/subtleforms-pro`.
   * **GitHub token** (private repositories only): on GitHub, **Settings › Developer settings › Fine-grained tokens › Generate new token**, resource owner = the repository owner, **Only select repositories** = subtleforms-pro, permission **Contents: Read-only**. Paste it and save. It is stored in the `sfls_github` option and only its last four characters are shown again.
   * **Asset name pattern**: `subtleforms-pro-*.zip` (default).
   * **Auto publish GitHub releases**: tick to publish imported releases right away; otherwise they arrive as drafts.
   * Click **Save GitHub settings**, then **Generate webhook secret** and copy the secret (shown once).
   * Click **Test connection**. It calls `GET /repos/OWNER/subtleforms-pro` and shows the repository name, visibility and default branch.
2. In the GitHub repository, open **Settings › Webhooks › Add webhook**:
   * **Payload URL**: `https://subtleforms.com/wp-json/sfls/v1/github/webhook` (shown in the settings)
   * **Content type**: `application/json`
   * **Secret**: the webhook secret
   * **Which events**: "Let me select individual events" and tick **Releases** only.
   * Save. GitHub sends a `ping`, which appears under **Recent GitHub deliveries** with result `ok`.

### Releasing a version

Create a GitHub release for tag `v2.4.0` (or `2.4.0`) and attach `subtleforms-pro-2.4.0.zip` (built with `bin/build-zip.sh`). Mark it as a pre-release to send it to the beta channel. When it is published, the server:

1. checks `X-Hub-Signature-256` (HMAC-SHA256 of the body with the secret),
2. takes the `release` event with action `published` or `released` (drafts are ignored, and the second event for the same release does nothing),
3. finds the first asset matching the pattern and downloads it through the GitHub API asset URL with `Accept: application/octet-stream` and the token, following the redirect to GitHub's storage without the token,
4. checks the zip and that the tag matches its `Version:` header,
5. stores it with source GitHub, the tag, `target_commitish` as the commit, and the release notes as the changelog (Markdown bullets become `* ` lines, headings, links and emphasis become plain text).

If the zip was missing or the check failed, the delivery shows `failed` with the reason. Fix it (for example attach the zip) and click **Retry** next to the delivery, or use **Import a release by tag**. GitHub's own **Redeliver** button in the webhook settings works too.

---

## WP-CLI on the server

```
wp sfls release list [--format=json]
wp sfls release add dist/subtleforms-pro-2.4.0.zip [--channel=beta] [--publish] [--changelog=@CHANGES.txt] [--replace]
wp sfls release publish 2.4.0
wp sfls release yank 2.4.0 --reason="Breaks the payment field on PHP 8.1"
wp sfls release latest 2.3.3        # pin an older version as the latest
wp sfls release latest --unpin
wp sfls release delete 2.4.0 --yes
wp sfls release token create "GitHub Actions"
wp sfls release token list
wp sfls release token revoke 2
```

## Rolling back

* **Yank** a bad version (row action, `POST /releases/<v>/yank` or `wp sfls release yank`). It stops being offered and downloadable, and the previous published version becomes the latest again. Sites that already installed it are not downgraded; customers can download the previous version from **My Account › Pro downloads › Older versions**.
* **Make this the latest** pins an older stable version even while newer published versions exist. Unpin to go back to "highest published stable version".
