# Merge rules

These rules apply to every pull request in this repository, whoever opened it.

A pull request is merged only when all of these are true:

1. The integration suite passes on a disposable WordPress with WooCommerce:
   `SFLS_WP_PATH=<wordpress> phpunit -c phpunit.xml`, with no new failures
   against the base branch, and Plugin Check on the zip reports no errors.
   Once this repository has GitHub Actions, every check must also be green.
2. GitHub shows no conflicts with the base branch. Conflicts are resolved on the
   pull request branch, never during the merge, and the suites run again.
3. Every review comment (people, Codex, CodeRabbit) is either fixed on the
   branch or answered with the reason it is not a problem.
4. No conflict markers anywhere: `git grep -nE '^(<<<<<<<|>>>>>>>)'` prints nothing.
5. Feature and fix pull requests add one changelog fragment in
   `docs/dev/changes/unreleased/` and do not edit `readme.txt`, the version
   files or `languages/subtleforms-license-server.pot`. A stale POT does not
   hold a feature pull request. A release pull request (`release/*`) must
   have a current POT as its last commit.

Release order: merge every feature and fix pull request first. Then one
release pull request bumps the version, runs `bin/compile-changes.sh <version>
--apply` (readme.txt block, release notes, fragments moved), regenerates the
POT as its last step and is merged and tagged last. Commands:
docs/dev/RELEASING.md.

Merging is done with the merge button (or the API), never by pushing a local
merge to the base branch. A release branch is merged into `main` only after the
same rules pass on the release branch itself and the release zip is built.

Commit messages and pull requests carry no AI or tool attribution.
