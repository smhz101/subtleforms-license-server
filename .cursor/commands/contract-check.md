---
name: contract-check
description: Inspect SubtleForms Free–Pro compatibility and report risks.
---

Run a Free–Pro contract check.

1. Load the `contract-verification` skill.
2. Prefer delegating to the `contract-guardian` subagent.
3. Read-only: no source edits.
4. Return the skill's report format (gate, risks, verdict, tests, sequencing).
