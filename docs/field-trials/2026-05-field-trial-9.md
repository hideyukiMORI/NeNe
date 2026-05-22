# Field Trial 9 — smarty-plugin (custom modifier / function authoring under `view/plugins/`)

Methodology reference: `docs/field-trials/README.md`. Report skeleton: `docs/templates/field-trial-report.md`. Trial Issue: #340.

## Date

2026-05-22

## Baseline

- NeNe ref: post-FT8 main (all #326–#333 follow-up PRs merged: production-mode hardening, log path override, pre-dispatch access log).
- Clone path: `/home/xi/github/NeNe-FT/ft9-smarty-plugin/` (created via `tools/nene-ft-new.sh smarty-plugin`)
- Host ports: app=8089, mysql=3316 (auto-offset N=9)
- PHP: 8.4.21 (in `php:8.4-apache` container)
- Database: MySQL 8.4 (Docker Compose default)

### Baseline verification

| Check | Result |
| --- | --- |
| `docker compose run --rm app composer install` | 58 packages, lock-pinned |
| `docker compose up -d app` + `GET /health` | HTTP 200, `healthStatus=ok` |
| `composer test` | 50 / 50, 134 assertions |

## Goal

Exercise NeNe's **Smarty custom plugin authoring** surface. The `DIR_SMARTY_PLUGINS` constant has existed in `ini/xSystemIni.php` since the legacy port; FT4 / FT5 used HTML rendering but never authored a custom plugin. This trial does the smallest possible thing — drop a single modifier file in `view/plugins/`, reference it in a template — and observes whether the documented convention actually works.

## Service Built

- Name: `view/plugins/modifier.shout.php` (Smarty 5 standard modifier) + `view/plugins/function.greet.php` (function plugin).
- Probe template change in `view/source/index/index.tpl` to call both.
- All changes live in the trial clone only.

## Steps Taken

### 1. Cold survey

Read `class/xion/View.php` and `ini/xSystemIni.php`:

- `ini/xSystemIni.php:214` — `const DIR_SMARTY_PLUGINS = DIR_ROOT . 'view/plugins'`.
- `class/xion/View.php::__construct` (lines 67–74) — calls `setTemplateDir`, `setCompileDir`, `setConfigDir`, `setEscapeHtml(true)`. **No `setPluginsDir` / `addPluginsDir` call.**
- `init.sh` creates `data/`, `view/config/`, `view/compile/`, `log/` — **not `view/plugins/`**.

`grep -rn DIR_SMARTY_PLUGINS` returns exactly one match: the definition. The constant is defined but referenced nowhere.

**Finding (F-1)**: The plugin directory convention is implied by the constant but not wired into the runtime.

### 2. Live trial — plugin invisible by default

Created `view/plugins/modifier.shout.php`:

```php
<?php
function smarty_modifier_shout(string $input): string
{
    return strtoupper($input) . '!!!';
}
```

Modified `view/source/index/index.tpl` to call `{$t_contents|shout}`. Cleared `view/compile/`. Hit `GET /`.

Result: HTTP 500. `error-*.log` captured: `Smarty\CompilerException: Syntax error in template "file:index/index.tpl" on line 4 "<p class="ft79-probe">{$t_contents|shout}</p>" unknown modifier 'shout'`.

The plugin file existed at the location the constant points to, with the standard Smarty 5 naming convention. The Smarty instance simply was never told to look there.

### 3. Live trial — one-line fix in `View::__construct`

Added (inside the trial clone, not committed to framework yet — the fix will ship as a follow-up PR):

```php
if (is_dir(DIR_SMARTY_PLUGINS)) {
    $this->smarty->addPluginsDir(DIR_SMARTY_PLUGINS);
}
```

Cleared `view/compile/`. Hit `GET /` again. The modifier output rendered correctly: `A SMALL LEGACY PHP FRAMEWORK FOR URL-BASED APPLICATIONS.!!!`.

### 4. Live trial — function plugin

Added `view/plugins/function.greet.php`:

```php
<?php
function smarty_function_greet(array $params, Smarty\Template $template): string
{
    $name = $params['name'] ?? 'world';
    return 'Hello, ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '.';
}
```

Called from the template as `{greet name='FT9'}`. Rendered `Hello, FT9.`. Both standard plugin kinds (`modifier`, `function`) work identically with one `addPluginsDir` call. Block plugins (`block.{name}.php`) follow the same auto-discovery rule per Smarty 5 docs but were not exercised in this trial.

### 5. Bumped into the compile-cache invalidation rule

After adding `function.greet.php` (without changing any template source), the first hit produced `unknown function 'greet'` — until `view/compile/` was cleared. Smarty invalidates the compile cache on *template* source change, but not on *plugin* source change. This is the same hygiene point FT4 #268 documents, but the plugin-authoring path bumps into it immediately.

### 6. Documentation cross-check

Searched `docs/` for any Smarty plugin authoring guidance. None found. `docs/roadmap.md:229` lists "Smarty custom plugin authoring (`view/plugins/`)" as one of the "remaining FT-untouched surfaces" — confirming this trial is the first time anyone authored a plugin against the framework.

## Results

| Scenario | Expectation | Actual | Status |
| --- | --- | --- | --- |
| `view/plugins/modifier.shout.php` is auto-discovered by Smarty | yes | no — `DIR_SMARTY_PLUGINS` not registered | Blocked |
| `addPluginsDir(DIR_SMARTY_PLUGINS)` fixes the visibility | yes | yes (one-line fix) | Pass |
| `function.greet.php` plugin also works after fix | yes | yes | Pass |
| `view/plugins/` exists after `init.sh` | yes | no | Partial |
| Compile cache invalidates when a new plugin file appears | yes (would be nice) | no — must `rm -rf view/compile/*` | Partial (Smarty design; not a NeNe bug) |
| `docs/development/smarty-plugins.md` exists | yes | no | Blocked |

## Friction Summary

| ID  | Location                              | Severity | Kind             | Decision        |
| --- | ------------------------------------- | -------- | ---------------- | --------------- |
| F-1 | `class/xion/View.php::__construct`    | high     | feature-gap      | fix-in-framework |
| F-2 | `init.sh`                             | low      | feature-gap      | fix-in-framework |
| F-3 | (no doc)                              | medium   | docs-gap         | document        |
| F-4 | (cross-ref FT4 #268)                  | low      | informational    | document        |

## Recommendations

### Immediate (small framework change)

1. **F-1 + F-2 — Wire up `DIR_SMARTY_PLUGINS`.** Add `$this->smarty->addPluginsDir(DIR_SMARTY_PLUGINS)` in `View::__construct`, guarded by `is_dir()` so the construction still works if the directory was deleted. Add `mkdir -p ./view/plugins` to `init.sh` alongside the other view directories. Single small PR.

### Immediate (documentation only)

2. **F-3 + F-4 — Add `docs/development/smarty-plugins.md`.** Cover (a) the Smarty 5 plugin file naming convention (`modifier.X.php`, `function.X.php`, `block.X.php`), (b) the function signatures (`smarty_modifier_X($input)`, `smarty_function_X(array $params, Smarty\Template $template)`, `smarty_block_X(array $params, $content, Smarty\Template $template, bool &$repeat)`), (c) the per-PHP-file convention (one plugin per file), and (d) the FT4 #268 cross-reference: clear `view/compile/` after adding a new plugin file. Link from `docs/frontend/assets.md` (Smarty section) and `AGENTS.md`.

### Suggested

None for this trial. F-1's "high" severity reflects the misleading constant, not architectural complexity — the actual fix is one method call.

### Trade-offs

None. Smarty 5 plugin auto-discovery is well-defined; NeNe just needs to opt into it.

## Aftermath

- Probe plugin files (`modifier.shout.php`, `function.greet.php`) stay inside the clone; not committed back.
- Two follow-up Issues filed (one for F-1+F-2 code, one for F-3+F-4 docs).
- All Issues to be closed by merged PR before FT10 starts (per ADR-0002 cadence).
