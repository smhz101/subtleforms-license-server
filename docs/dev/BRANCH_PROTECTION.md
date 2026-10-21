# Branch protection for main

GitHub settings cannot be changed from a pull request. The repository owner sets
them once. Until then `main` is unprotected and nothing forces the checks below.

## Settings steps

1. Open the repository on GitHub, then Settings, Branches.
2. Under Branch protection rules choose Add branch protection rule (or edit the
   existing rule). Branch name pattern: `main`.
3. Tick Require a pull request before merging. Tick Require approvals only if a
   second person exists (there is no other developer today, so leave it off).
4. Tick Require status checks to pass before merging and Require branches to be
   up to date before merging.
5. In the search box add each check name listed below, exactly as written.
   A name only appears in the search after it has run once on the repository.
   Leave the GitGuardian check out of the required list unless you want it to
   block merges; it is a separate app, not part of this workflow.
6. Tick Require conversation resolution before merging.
7. Leave Allow force pushes and Allow deletions off. Save changes.

## Required checks

All four come from `.github/workflows/checks.yml`:

- PHP lint
- PHPUnit
- Build zip
- POT freshness check

## Notes

- `PHPUnit` installs a fresh WordPress and WooCommerce on the runner (MySQL
  service container), links this repository in as the plugin and runs
  `phpunit -c phpunit.xml` with `SFLS_WP_PATH`. WooCommerce is installed at its
  latest version, so a WooCommerce release can fail this check for reasons
  outside the pull request.
- `POT freshness check` is strict only for pull requests from `release/*`
  branches. On every other pull request a stale POT is a warning.
- The workflow has no paths filter on purpose. A required check that is skipped
  by a paths filter never reports and would block the merge forever.
- Plugin Check on the release zip is not run in CI yet. It stays part of the
  release gate in `docs/dev/MERGING.md`.
