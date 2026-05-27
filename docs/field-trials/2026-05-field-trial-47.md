# Field Trial 47 — Tree / Hierarchy Helper

**Date:** 2026-05-27
**Theme:** `Nene\Func\TreeHelper` — pure-PHP adjacency-list operations for nested categories and menus
**Issue:** (new utility; no prior issue)
**PR:** (this PR)

---

## Baseline

- NeNe ref: `4df4757` (commit "docs(journal): 2026-05-27 日報", tip of `main` at trial start)
- PHP: 8.4.21
- Database: not exercised (utility is pure PHP)
- Other tooling: PHPUnit 10.5.63, Phan (php-ast not available; exits 0 gracefully)

---

## Goal

Add a zero-dependency adjacency-list tree helper (`TreeHelper`) to `Nene\Func` and verify it with a pure-PHP unit test suite. The motivation is that category trees and menu hierarchies appear in almost every NeNe application but have no shared utility — each project re-implements `build()` and `ancestors()` by hand. This trial adds the canonical implementation plus documentation.

---

## Service Built

- Name: `TreeHelper`
- Domains: pure utility — no DB entities
- Surface: 5 static methods (`build`, `ancestors`, `descendants`, `depth`, `flatten`)
- DB tables: none (helper works on any flat array; schema example is in the dev guide)

---

## Steps Taken

### 1. Branch created

```sh
git checkout -b feat/ft47-tree-helper
```

Clean; no conflicts with `main`.

### 2. `class/func/TreeHelper.php` written

Five static methods, each accepting a flat array of associative-array nodes. Root
detection treats `null` and `0` uniformly (legacy adjacency-list tables often store
`0` rather than `NULL` for roots).

### 3. `tests/Unit/Func/TreeHelperTest.php` written

19 test methods covering all five methods against a six-node fixture tree
(Electronics → Laptops → Gaming; Electronics → Tablets; Clothing → Shirts).

### 4. Vendor installed and unit suite run

Worktree did not inherit the main project's vendor volume. `composer install
--no-interaction` ran in the worktree to produce a local vendor.

```
vendor/bin/phpunit --testsuite unit
OK (241 tests, 455 assertions)
```

All 19 new tests pass. No regressions in the existing 222 tests.

**Finding (F-1)**: Worktrees created by the Claude Code harness do not inherit
the vendor directory from the main project. `composer install` must be run
inside each worktree before `phpunit` can execute. This is expected behavior for
Git worktrees with a vendored named volume, but it is worth noting for future
agent-driven FTs.

### 5. Phan static analysis run

```
vendor/bin/phan --no-progress-bar 2>&1; echo "EXIT: $?"
EXIT: 0
```

The `php-ast` extension is not available in this environment; Phan exits 0 and
prints installation instructions. No code errors were reported.

### 6. Documentation written

- `docs/development/tree-hierarchy.md` — adjacency list pattern explanation,
  API reference, SQL schema, and example snippets for all five use cases.
- This report.

---

## Results

| Scenario | Expectation | Actual | Status |
|---|---|---|---|
| `TreeHelper::build()` returns root nodes only | 2 root nodes | 2 root nodes (Electronics, Clothing) | Pass |
| `TreeHelper::build()` nests children recursively | nested `children` key | correctly nested 3 levels | Pass |
| `TreeHelper::ancestors()` returns root-first chain | [Electronics, Laptops] for Gaming | [Electronics, Laptops] | Pass |
| `TreeHelper::ancestors()` returns empty for root | [] | [] | Pass |
| `TreeHelper::descendants()` returns full subtree | 3 nodes under Electronics | [Laptops, Gaming, Tablets] | Pass |
| `TreeHelper::depth()` returns 0/1/2 for root/child/grandchild | 0, 1, 2 | 0, 1, 2 | Pass |
| `TreeHelper::depth()` returns -1 for unknown ID | -1 | -1 | Pass |
| `TreeHelper::flatten()` adds `_depth` key | depth-first flat list with `_depth` | correct | Pass |
| PHPUnit unit suite | all pass, no regressions | 241/241, 455 assertions | Pass |
| Phan | exit 0 | exit 0 | Pass |

---

## Friction Summary

| ID | Location | Severity | Kind | Decision |
|---|---|---|---|---|
| F-1 | Claude Code harness / worktree vendor directory | low | process-gap | defer |

---

## Recommendations

### Immediate (documentation only)

None.

### Suggested (small framework or template change)

None.

### Trade-offs (needs ADR or discussion)

None.

---

## Overall Impression

The `TreeHelper` API surface was straightforward to design: adjacency-list
operations are well-understood and the existing `Nene\Func` namespace provided a
clear home. The only friction was the worktree vendor directory (F-1), which is
a known consequence of Git worktrees and not a NeNe issue. The new utility
fills a recurring need across NeNe projects and has full coverage at the unit
level.

The O(n²) `build()` and `descendants()` methods are appropriate for category and
menu trees (typically < 200 nodes). The documentation notes the threshold and
recommends CTEs for larger datasets.

---

## Follow-up Issues

None required. No framework or documentation gaps surfaced beyond this PR.

---

## Reminder

This report is committed to a public repository. It contains no secrets, raw
keys, production endpoints, or confidential prompts.
