---
name: test-and-qa
description: Loads verified SubtleForms test and QA commands for Free, Pro, and License Server from project manifests. Use before running or recommending tests. Never invent commands. Never run DB-changing integration tests on the shared dev site without approval.
---

# Test and QA (verified commands)

Detect the repo from cwd / workspace root. Prefer unit and non-destructive suites first.

## Free (`subtleforms`)

From Free `AGENTS.md`, `docs/dev/MERGING.md`, `package.json`:

- PHPUnit unit: `vendor/bin/phpunit --testsuite unit`
- WordPress suite: `phpunit -c phpunit-wp.xml.dist` (needs WP test env)
- Jest: `npm test` or `npx jest`
- Build: `npm run build:all`
- Playwright: `npm run test:e2e` / `npm run test:browser` / `npm run test:regression` as appropriate to the change
- Lint JS: `npm run lint:js`

## Pro (`subtleforms-pro`)

From Pro `docs/dev/MERGING.md` and `package.json`:

- PHPUnit (Free checkout required): `SUBTLEFORMS_FREE_DIR=<free> <free>/vendor/bin/phpunit -c phpunit.xml.dist`
- Build: `npm run build` (add `npm run build:fields` / `build:payments` / `build:woo` when those resources change)

## License Server (`subtleforms-license-server`)

From LS `AGENTS.md` / `docs/dev/MERGING.md`:

- Integration: `SFLS_WP_PATH=/path/to/wordpress phpunit -c phpunit.xml` (WooCommerce required)
- Use only on disposable/approved WordPress; do not mutate the shared dev site without human approval

## Reporting

Record exact command, cwd, exit status, and relevant failure excerpts. Say when skipped and why.
