---
name: pkg-start
description: Inspect and prepare one approved SubtleForms development package (Free, Pro, or License Server).
---

Prepare exactly one approved package.

1. Identify package ID / path from the user message or ask once if missing.
2. Follow the `package-development` skill through scope discovery, policy lookup, and git safeguards only (do not implement yet unless the user already authorized implementation in the same message).
3. Prefer delegating deep implementation to the `package-worker` subagent after the prep summary.
4. Output: owning repo, package path, in-scope IDs, ledger statuses, branch expectation, risks, and the next recommended step.

## Multi-root note

This command is duplicated intentionally in Free, Pro, and License Server so each standalone checkout keeps `/` discovery. In a multi-root workspace Cursor may list it more than once; pick the entry for the repository you mean. See `.cursor/AI_TEAM.md`.
