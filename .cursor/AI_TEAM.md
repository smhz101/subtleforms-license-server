# SubtleForms Cursor AI team

Authoritative product policy remains each repo's `AGENTS.md`, RULES/mdc, MERGING, RELEASING, and Pro `CONTRACT.md`.

## Layout (identical in Free, Pro, License Server)

- Agents: `.cursor/agents/*.md`
- Skills: `.cursor/skills/<name>/SKILL.md`
- Commands: `.cursor/commands/*.md`

## Orchestration (main agent owns this)

The **main Cursor Agent** (orchestrator) selects specialists, sequences work, and decides when a task is done.

Specialist agents (`package-worker`, `qa-engineer`, `contract-guardian`, `architecture-reviewer`, `license-security-reviewer`, `release-prep`, `gutenberg-ui-reviewer`, and any others in this folder):

1. **Do not spawn nested Task / scout / extractor / other subagents** unless the human explicitly authorizes nested delegation in that turn.
2. Prefer **direct** Read / Grep / Shell (read-only when `readonly: true`) for locating and inspecting source.
3. If a tool is blocked (sandbox, Ask mode, Frugal budget) or usage limits hit: **report the blocker** or use a permitted lightweight alternative (e.g. narrower Read). **Do not** recursively delegate to bypass limits.
4. Implementation → QA loops: at most **two** correction-and-retest attempts after QA findings; then escalate to the human with evidence.
5. Every task needs explicit **acceptance criteria** and a **completion condition**. When criteria pass, **stop** — do not repeat reviews or invent follow-on audits.
6. Never merge, push to `main`, tag, publish/yank/pin packages, rotate keys, deploy, or run production ops. Preparing checklists is fine; executing production ops needs explicit human authorization.

These rules are **prompt-level guidance**. Cursor does not expose project settings that hard-enforce “no nested Task” or “max two QA loops”; specialists and the orchestrator must follow them deliberately.

## Delegation map

| Situation | Agents |
| --- | --- |
| Small localized package task | Main agent or `package-worker` |
| Cross-plugin feature | `architecture-reviewer` → `package-worker` → `contract-guardian` |
| Licensing / signing / sfls | `architecture-reviewer` or `license-security-reviewer` before writes |
| Complex bug | `qa-engineer` before implementation when useful |
| Admin / builder / Gutenberg UI review | `gutenberg-ui-reviewer` (read-only) |
| Release branch | `release-prep` (+ security/contract as needed) |

Research agents may run in parallel when the orchestrator launches them. **Never** run two writing agents on the same working tree concurrently. Parallel isolation requires separate git worktrees (not assumed).

## Multi-root and slash commands

Each workspace folder loads its own `.cursor/` tree. Agent/skill/command **names stay identical** across Free, Pro, and License Server so single-root checkouts keep full discovery.

In a **multi-root** workspace, Cursor may show **duplicate** `/pkg-start`, `/pkg-report`, and `/contract-check` entries (one per root). There is no supported, testable project setting to hide duplicates without removing commands from a repo and breaking standalone use. **Keep per-repo command files.** Prefer invoking by intent (“run pkg-start for Free”) or the entry under the repo you mean. User-global `~/.cursor/commands` is not used for these (would affect unrelated projects).

## Models

Agents use `model: inherit` (no hardcoded model IDs).
