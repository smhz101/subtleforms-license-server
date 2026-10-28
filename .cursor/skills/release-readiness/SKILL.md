---
name: release-readiness
description: Selects and verifies the correct SubtleForms release procedure for Free, Pro, or License Server without publishing. Use on release/<version> branches or when asked for release gates. Stops for human authorization before any production operation.
---

# Release readiness

## Select pathway

| Product | Authority |
| --- | --- |
| Free | `docs/dev/RELEASING.md`, `docs/dev/MERGING.md`, WordPress.org guidelines |
| Pro | `docs/dev/RELEASING.md`, `docs/dev/MERGING.md`, `CONTRACT.md` compatibility |
| License Server | `docs/dev/RELEASING.md`, `docs/dev/MERGING.md`; production distribute/sign/deploy are separate human-authorized ops |

## Verify (read-only)

- Version consistency across main plugin file / package.json / readme as required by that RELEASING.md
- Changelog / changes compilation steps documented for the release PR
- POT / i18n freshness rules (branch-dependent)
- Required Checks names from `docs/dev/BRANCH_PROTECTION.md`
- Free: packaging/`dist` expectations if documented; Plugin Check concerns
- Pro: Free API/LEVEL gate; contract tests; build artifacts; update/signature readiness (do not rotate keys)
- License Server: integration suite readiness; signing/release API integrity; GitHub package distribution behavior as documented

## Human authorization checkpoint

**STOP** before: WordPress.org deploy, Pro package publish/yank/pin/delete, signing key rotation, deploy tokens, subtleforms.com deploy, customer-facing update triggers, billing changes.

Preparing a checklist or draft commands is allowed. Executing production ops is not part of this skill.
