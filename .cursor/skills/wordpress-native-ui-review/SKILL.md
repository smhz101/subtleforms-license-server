---
name: wordpress-native-ui-review
description: Checklist for SubtleForms WordPress-native admin and Gutenberg UI reviews. Use with gutenberg-ui-reviewer or when reviewing Free/Pro builder and admin React against wp.components and WordPress 6.x conventions.
---

# WordPress-native UI review

## When

Admin/builder/Gutenberg UI review for Free or Pro. Prefer the `gutenberg-ui-reviewer` agent. Do not edit application UI in a review-only task.

## Checklist

1. Inventory imports from `@wordpress/components`, `@wordpress/element`, `@wordpress/i18n`, `@wordpress/data`, `@wordpress/block-editor`, `@wordpress/notices`, etc.
2. Flag custom buttons/modals/tabs that duplicate `Button`, `Modal`, `TabPanel`, `Notice`, `Spinner`, `ComboboxControl` without a documented reason.
3. Check `__next40pxDefaultSize` / deprecated prop warnings only when reviewing code already touching those components (do not mass-migrate).
4. A11y: focus traps in modals, `label`/`aria-*` on icon buttons, keyboard paths in builder chrome.
5. i18n: `__()`, `_n()`, `sprintf` with text domain consistency.
6. Gutenberg: if blocks/slots exist, verify SlotFill / plugin sidebar patterns match current WP docs used by this codebase.
7. Output incremental fixes ordered by severity; never propose replacing the entire builder in one change.

## Authority

Product rules stay in each repo `AGENTS.md` / RULES / mdc. This skill is UI review procedure only.
