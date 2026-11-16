# SubtleForms License Server — Agent guide

Private WordPress plugin for subtleforms.com only. Not WordPress.org software and not shipped to customers. Owns license lifecycle, customer/agency flows, WooCommerce/Paddle, and Pro distribution (ingest, store, sign, update delivery).

## Authoritative policies

| Doc | When |
| --- | --- |
| `.cursor/rules/subtleforms-license-server.mdc` | Standing rules for every task |
| `docs/dev/MERGING.md` | PR readiness |
| `docs/dev/RELEASING.md` | Plugin release pull requests (this codebase) |
| `docs/dev/BRANCH_PROTECTION.md` | Required Checks names |
| `docs/dev/bug-ledger.csv` / `docs/dev/packages/` | Package bug work |

## Product boundaries

- Prefixes: classes `SFLS_`, functions/options `sfls_`, REST `sfls/v1`, text domain `subtleforms-license-server`.
- Public license/update routes authenticate by license key, signed download links, deploy tokens, or GitHub webhook signatures — not casual WP cookie auth. Do not "simplify" open `permission_callback` routes without a security plan.
- Pro sites consume activate/validate/update/check/package/download and signed answers. Keep compatibility with Pro `Client` / `Signature` / `Updater`.
- Code CI (`.github/workflows/checks.yml`) is not the same as publishing a Pro zip to customers.

## Task categories

| Kind | Notes |
| --- | --- |
| Bug-fix / feature / security packages | One package; integration tests in `tests/integration` |
| Architecture investigation | Read-only default; map `includes/class-sfls-*.php` |
| Docs / AI config | Minimal; no product changelog unless user-visible |
| Release preparation (this plugin) | `RELEASING.md` on `release/<version>` |
| Production operations | Inspect and prepare only unless explicitly authorized (see below) |

Do not invent bug IDs. Read-only audits: no branch, no changelog.

## Production operations (explicit human authorization required)

Agents must not perform these against production without clear written approval:

- Publish, yank, pin, or delete Pro releases / stored packages
- Rotate Ed25519 signing keys (`SFLS_SIGNING_*`)
- Create or revoke production deploy tokens
- Change billing, Paddle/Woo, or live license entitlements
- Deploy this plugin to subtleforms.com
- Trigger customer-facing Pro updates or mass re-sign of packages
- Modify GitHub webhook secrets or production GitHub tokens

Local inspection, tests on disposable WordPress, and drafting PRs are allowed.

## Agent workflow and merging

- Agents may prepare commits and open PRs when authorized.
- Agents must never merge, push to `main`, tag, or deploy.
- Humans merge when `MERGING.md` is satisfied (Checks CI + integration suite).
- No AI or tool attribution in git or GitHub.

## Local commands

- Integration suite: `SFLS_WP_PATH=/path/to/wordpress phpunit -c phpunit.xml` (WooCommerce required)

## Code checks

- `composer install`, then `composer lint:phpcs` (WordPress standard) and `composer lint:phpstan` (level 5). CI job "Code checks" runs both.
- Existing findings are baselined in `phpcs.baseline.xml` and `phpstan-baseline.neon`; only new ones fail. Fix new findings, do not regenerate the baselines to hide them.

## Running tests

- One command: `composer install && bin/test-env` (extra args go to phpunit, e.g. `bin/test-env --filter ReleaseApi`).
- With `SFLS_WP_PATH` set it uses that WordPress. Otherwise it builds a throwaway site (needs `wp`, MySQL via `DB_HOST/DB_USER/DB_PASS/DB_NAME`) with WooCommerce `WC_VERSION` (default: the CI pin). Env vars are documented at the top of `bin/test-env`.
- The bootstrap allows http for the release API, as CI does via `WP_ENVIRONMENT_TYPE=local`.
- CI runs PHPUnit against WooCommerce 10.2.1 and 11.3.0 (production runs 11.3.x).

## Cursor AI team

Project subagents, skills, and commands live under `.cursor/` (see `.cursor/AI_TEAM.md`). Orchestration and stop rules: `.cursor/AI_TEAM.md`. They delegate to this file and `.cursor/rules/subtleforms-license-server.mdc`; they do not replace them.
