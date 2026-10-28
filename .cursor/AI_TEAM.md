# SubtleForms Cursor AI team

Authoritative product policy remains each repo's `AGENTS.md`, RULES/mdc, MERGING, RELEASING, and Pro `CONTRACT.md`.

## Layout (identical in Free, Pro, License Server)

- Agents: `.cursor/agents/*.md`
- Skills: `.cursor/skills/<name>/SKILL.md`
- Commands: `.cursor/commands/*.md`

## Delegation

| Situation | Agents |
| --- | --- |
| Small localized package task | Main agent or `package-worker` |
| Cross-plugin feature | `architecture-reviewer` → `package-worker` → `contract-guardian` |
| Licensing / signing / sfls | `architecture-reviewer` or `license-security-reviewer` before writes |
| Complex bug | `qa-engineer` before implementation when useful |
| Release branch | `release-prep` (+ security/contract as needed) |

Research agents may run in parallel. **Never** run two writing agents on the same working tree concurrently. Parallel isolation requires separate git worktrees (not assumed).

## Multi-root

Each workspace folder loads its own `.cursor/` tree. Keep agent/skill/command names identical across the three repos so behavior matches. User-global `~/.cursor/agents` is intentionally unused (would affect unrelated projects).

## Models

Agents use `model: inherit` (no hardcoded model IDs).
