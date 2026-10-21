# Releasing SubtleForms License Server

This repository has no GitHub Actions yet, so these rules are followed by hand. The release is one pull request, `release/<version>`, opened after every feature and fix pull request for that version is merged. It is the only pull request that touches `readme.txt`, the version files and `languages/subtleforms-license-server.pot`, so those files stop conflicting.

## Order

1. Merge every feature and fix pull request into `main`. Each one carries its changelog fragment in `docs/dev/changes/unreleased/` and none of them edits the readme, the versions or the POT.
2. Branch from `main`: `git switch -c release/<version> origin/main`.
3. Bump the version: `Version:` and `SFLS_VERSION` in `subtleforms-license-server.php` and `Stable tag:` in `readme.txt`.
4. Compile the changelog: `bin/compile-changes.sh <version> --apply`. Paste the printed block into `readme.txt` under `== Changelog ==`. If a `= Unreleased =` heading from before this flow is still there, replace the heading with the version heading and keep its lines in the same block. `docs/dev/releases/<version>-changes.md` is written by the script; commit it.
5. Regenerate the POT as the last edit, after all strings are final. From the repository root (the folder name does not matter for this plugin):
   ```
   env I18N_OFFLINE=1 php /tmp/claude-0/wp-cli-latest-offline.phar --allow-root i18n make-pot . languages/subtleforms-license-server.pot --domain=subtleforms-license-server --exclude=node_modules,vendor,tests,docs,bin,assets/vendor --headers='{"Report-Msgid-Bugs-To":"https://wordpress.org/support/plugin/subtleforms-license-server"}'
   ```
   With a normal WP-CLI, replace `env I18N_OFFLINE=1 php /tmp/claude-0/wp-cli-latest-offline.phar --allow-root` with `wp`. Commit `languages/subtleforms-license-server.pot`. The committed file records source line numbers, so it goes stale as soon as code above a string moves; that is why only the release pull request regenerates it.
6. Open the pull request from `release/<version>` into `main`, run the integration suite listed in `docs/dev/MERGING.md`, build the release zip, merge, then tag `main` as `v<version>`.

## Why the POT is not in every pull request

The POT lists every string with its source line, so two pull requests that touch nearby code change the same POT lines and conflict. Regenerating it once per release removes that. A feature pull request does not have to commit a regenerated POT, and a stale POT is not a reason to hold it. A release pull request with a stale POT is.

## Why there is no merge driver for the POT

`.gitattributes` marks `languages/*.pot` as `-diff linguist-generated` so it collapses in review. It deliberately has no `merge=union`: a union merge keeps both sides of a conflicting hunk, which produces duplicate `msgid` entries and duplicate header lines, and such a file is invalid. If a POT ever conflicts, take either side and run the command in step 5.
