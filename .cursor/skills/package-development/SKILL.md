---
name: package-development
description: Runs the SubtleForms approved package workflow (scope, policies, implementation, regression tests, changelog, ledger-updates). Use when implementing pkg/<ID>, bug-fix packages, feature packages, or security packages in Free, Pro, or License Server.
---

# Package development

## 1. Scope discovery

- Locate `docs/dev/packages/<ID>.md` (or the ID the human named).
- Read matching rows in `docs/dev/bug-ledger.csv`. Ledger `status`/`fixed_in` win over stale package "Still open" text.
- Confirm owning repository from the package and workspace roots.

## 2. Policy lookup

| Repo | Standing rules |
| --- | --- |
| Free | `AGENTS.md`, `docs/dev/RULES.md`, `docs/dev/MERGING.md` |
| Pro | `AGENTS.md`, `.cursor/rules/subtleforms-pro.mdc`, `docs/dev/MERGING.md`, `CONTRACT.md` if hooks touched |
| License Server | `AGENTS.md`, `.cursor/rules/subtleforms-license-server.mdc`, `docs/dev/MERGING.md` |

## 3. Git safeguards

- Prefer branch `pkg/<ID>` from the release branch named in the package.
- Do not commit, push, merge, or amend unless the human explicitly asks.
- Preserve pre-existing `subtleforms-pro/src/Licensing/Signature.php` local changes unless asked.
- One package at a time; no unrelated refactors.

## 4. Investigate → implement → test

- Reproduce or cite evidence before fixing.
- Minimal diff; match existing style.
- Add regression coverage required by RULES/mdc.
- Use `test-and-qa` skill for verified commands only.

## 5. Changelog and ledger

- Follow that repo's RULES/mdc for `changes/` fragments.
- Free: do **not** edit `bug-ledger.csv` in feature/fix PRs; add `docs/dev/ledger-updates/<slug>.csv` replacement rows.
- Do not bump versions or edit release-owned files outside a `release/<version>` PR.

## 6. Final report

Package ID, repo, criteria met/unmet, tests, remaining issues. Escalate contract or licensing concerns to the matching subagent.
