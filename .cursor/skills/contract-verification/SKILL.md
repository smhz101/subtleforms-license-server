---
name: contract-verification
description: Verifies SubtleForms Free–Pro compatibility (hooks, ExtensionPoints LEVEL, API version, JS registries, store/payment lifecycle, HooksContractTest). Use for /contract-check or before merging contract-affecting changes.
---

# Contract verification

## Checklist

1. Free `SUBTLEFORMS_API_VERSION` and ExtensionPoints LEVEL (`src/Support/ExtensionPoints.php` in Free)
2. Free `docs/hooks.md` vs code (hooks, filters, JS `window.SubtleFormsExtend` registries)
3. Pro `CONTRACT.md` requirements vs Free
4. Pro `tests/Contract/HooksContractTest.php` expectations
5. ProPlugin compatibility gate / admin notice behavior when mismatched
6. Submission store hooks and payment-related consumers if touched

## Commands (verify env first)

From Pro, with Free checkout available (see Pro `docs/dev/MERGING.md` / `phpunit.xml.dist`):

```bash
SUBTLEFORMS_FREE_DIR=<absolute-path-to-subtleforms> <free>/vendor/bin/phpunit -c phpunit.xml.dist --filter HooksContract
```

Adjust filter only if the test class name requires it; prefer running the Contract suite as configured in Pro PHPUnit config when unsure.

## Report

Gate versions, diffs/risks with paths, breaking-change verdict, required Free-then-Pro sequence, tests to run. Read-only unless the human authorized implementation.
