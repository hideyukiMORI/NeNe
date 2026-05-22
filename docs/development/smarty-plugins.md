# Smarty Plugins

How to add a project-specific Smarty modifier / function / block plugin to NeNe under `view/plugins/`.

Audience: anyone authoring a small piece of template logic (a date formatter, an HTML escaper variant, a domain-specific helper) that does not belong in a controller or `View` method. Trial source: FT9 (`docs/field-trials/2026-05-field-trial-9.md`).

## Where plugins live

```
view/plugins/
    modifier.shout.php
    function.greet.php
    block.callout.php
```

One plugin per file. The filename encodes both the plugin kind and the name the template uses:

| Filename             | Kind     | Used in template as     |
| -------------------- | -------- | ----------------------- |
| `modifier.shout.php` | modifier | `{$value\|shout}`       |
| `function.greet.php` | function | `{greet name='world'}`  |
| `block.callout.php`  | block    | `{callout}…{/callout}`  |

`View::registerProjectPlugins()` (in `class/xion/View.php`) scans this directory at construction time and registers each matching file via `Smarty::registerPlugin()`. Files that do not match the `modifier.NAME.php` / `function.NAME.php` / `block.NAME.php` pattern are skipped silently.

The directory is created by `init.sh` alongside `view/compile/` and `data/`.

## Function signatures

### Modifier

```php
<?php
// view/plugins/modifier.shout.php

function smarty_modifier_shout(string $input): string
{
    return strtoupper($input) . '!!!';
}
```

The function name must be exactly `smarty_modifier_<filename-stem>`. The first argument is the value being modified; additional arguments correspond to modifier parameters in the template (`{$value|shout:'?'}` would pass `'?'` as the second argument).

### Function

```php
<?php
// view/plugins/function.greet.php

function smarty_function_greet(array $params, Smarty\Template $template): string
{
    $name = $params['name'] ?? 'world';
    return 'Hello, ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '.';
}
```

`$params` carries the named attributes from the template (`{greet name='FT9'}` → `$params['name'] === 'FT9'`). Return the rendered string. The second argument is the Smarty `Template` instance — useful when the plugin needs to read or write template variables.

### Block

```php
<?php
// view/plugins/block.callout.php

function smarty_block_callout(array $params, ?string $content, Smarty\Template $template, bool &$repeat): string
{
    if ($repeat) {
        return '';
    }
    $level = htmlspecialchars((string)($params['level'] ?? 'note'), ENT_QUOTES, 'UTF-8');
    return '<aside class="callout callout--' . $level . '">' . ($content ?? '') . '</aside>';
}
```

Blocks are called twice — once at the opening tag (`$repeat=true`, `$content=null`) and once at the closing tag (`$repeat=false`, `$content` is the rendered inner block). Return an empty string on the first pass, the wrapped content on the second.

## Output escaping

The framework calls `setEscapeHtml(true)` on every `View` instance, which auto-escapes `{$variable}` references. **Plugins return raw strings** — if the return value contains HTML you intend to render unescaped, the caller must use `{plugin nofilter}` or `{$value|shout nofilter}` to bypass the auto-escape. For untrusted input inside the plugin, escape with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` as shown above.

The same trade-off was surfaced earlier by FT4 around `|nl2br` — see `docs/frontend/assets.md` § "Smarty HTML Escaping" for the full pattern.

## Compile-cache hygiene

Smarty invalidates compiled templates when the **template source** changes, but **not** when a plugin source file changes. After adding a new plugin file (or editing an existing one), clear the compile cache:

```bash
docker compose exec app rm -rf /var/www/html/view/compile/*
```

Otherwise Smarty will reuse the previously-compiled template, which already resolved the modifier/function/block names — and you'll see `unknown modifier 'shout'` even though the file is now in place. This is the same hygiene point covered by `docs/frontend/assets.md` (PR #268).

## Why `registerPlugin` instead of `addPluginsDir`

NeNe historically defined `DIR_SMARTY_PLUGINS` but never registered it with Smarty (FT9 F-1). The straightforward Smarty 3/4 fix would have been `Smarty::addPluginsDir(DIR_SMARTY_PLUGINS)`, but that API is **deprecated in Smarty 5** and emits a `Deprecated:` notice on every `View` construction. With `display_errors` on (default for development), the notice gets echoed into the HTTP response *before* the response headers, breaking session and CSRF flow.

The framework instead scans the directory once at `View` construction and calls `Smarty::registerPlugin()` for each discovered file. This matches Smarty 5's preferred API while preserving the simple "drop a file in `view/plugins/`" authoring shape contributors expect.

## Related

- `docs/frontend/assets.md` — Smarty escape behavior, asset auto-discovery, compile cache.
- `docs/field-trials/2026-05-field-trial-9.md` — the trial that surfaced the missing wiring.
- `class/xion/View.php` — `View::registerProjectPlugins()` is the auto-discovery entry point.
- Upstream Smarty 5 docs: <https://smarty-php.github.io/smarty/api/extending/registering-plugins>
