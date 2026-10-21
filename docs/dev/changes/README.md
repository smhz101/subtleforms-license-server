# Changelog fragments

Feature and fix pull requests never edit `readme.txt`, the release notes or the version files. Two pull requests that edit the same lines conflict; two pull requests that each add a new file do not. So every pull request that a user can notice adds exactly one new file:

```
docs/dev/changes/unreleased/<short-branch-slug>.md
```

Use the branch name without its prefix, for example `fix-renewal-gate.md` for `fix/renewal-gate`. The file has no frontmatter. The first line is the type, the rest is one plain sentence:

```
type: Fixed
A renewal order for a license in another account is refused.
```

Types: `Added`, `Changed`, `Fixed`, `Security`. They print as `New`, `Change`, `Fix` and `Security` in the readme. A scope is allowed in brackets when the entry only applies to one plan, for example `type: Added (Agency)`.

Rules for the text: plain words, no leading bullet, no em dashes, one change per file. A pull request with no user-visible change (tests, docs, tooling) adds no fragment.

At release time the release pull request runs `bin/compile-changes.sh <version> --apply`. It prints the readme.txt block, writes `docs/dev/releases/<version>-changes.md` and moves the fragments to `docs/dev/changes/<version>/`. See `docs/dev/RELEASING.md`. Entries that were already released are not migrated.

Check the script with `bash bin/test-compile-changes.sh`.
