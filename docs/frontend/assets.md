# Templates and Page Assets

NeNe keeps page presentation close to its legacy URL routing style. A URL segment selects a controller and action, and the matching Smarty template, CSS, and JavaScript files are discovered by convention.

This is intentional. A reader should be able to move from `/page/about` to `PageController::aboutAction()`, then to `view/source/page/about.tpl`, `htdocs/css/page/about.css`, and `htdocs/js/page/about.js` without searching a route table or build manifest.

## Directory Roles

- `view/source/`: Smarty template source files.
- `view/source/layout/`: Shared Smarty layouts.
- `htdocs/css/`: Public CSS files.
- `htdocs/js/`: Public JavaScript files.

Do not place source templates under `htdocs/`. Templates are server-side files. CSS and JavaScript are public browser assets, so they live under `htdocs/`.

## Page Naming Convention

For this URL:

```text
GET /page/about
```

NeNe resolves the page to:

```text
class/controller/PageController.php
PageController::aboutAction()
```

The matching presentation files are:

```text
view/source/page/about.tpl
htdocs/css/page/about.css
htdocs/js/page/about.js
```

Use lower-case directory and file names for the URL-facing parts. The controller class keeps the PHP class convention (`PageController`), while the presentation paths follow the route segments (`page/about`).

## Template Auto Selection

`ControllerBase` automatically selects a template from the current controller and action. New pages should normally use the action-specific directory style.

The practical recommended paths are:

```text
view/source/common.tpl
view/source/{controller}/common.tpl
view/source/{controller}/{action}.tpl
```

For `/page/about`, the preferred template is:

```text
view/source/page/about.tpl
```

If the action-specific template does not exist, NeNe falls back through broader templates. This keeps very small pages possible while still allowing action-specific templates when the page needs its own markup.

Use `view/source/{controller}/common.tpl` for shared controller markup. NeNe does not load `view/source/{controller}.tpl`; keeping templates inside the controller directory makes the route-to-template relationship easier to scan.

Most new pages should extend the shared layout:

```smarty
{extends file='layout/app.tpl'}
{block name='content'}
                <section class="page-about">
                    <h1>{$t_heading}</h1>
                    <p>{$t_body}</p>
                </section>
{/block}
```

## CSS Auto Loading

`ControllerBase` also discovers CSS files by convention.

The lookup order is:

```text
htdocs/css/{controller}.css
htdocs/css/{controller}/common.css
htdocs/css/{controller}/{action}.css
```

For `/page/about`, these files are loaded when they exist:

```text
htdocs/css/page.css
htdocs/css/page/common.css
htdocs/css/page/about.css
```

Use `htdocs/css/{controller}/common.css` for styles shared by pages in the same controller. Use `htdocs/css/{controller}/{action}.css` for page-specific styles.

## JavaScript Auto Loading

JavaScript follows the same pattern as CSS.

The lookup order is:

```text
htdocs/js/{controller}.js
htdocs/js/{controller}/common.js
htdocs/js/{controller}/{action}.js
```

For `/page/about`, these files are loaded when they exist:

```text
htdocs/js/page.js
htdocs/js/page/common.js
htdocs/js/page/about.js
```

Use JavaScript only when the page needs browser behavior. Server-rendered pages can omit JavaScript entirely.

## Layout Output

The shared layout receives the resolved assets as Smarty variables:

```text
$t_css
$t_js
```

`view/source/layout/app.tpl` renders those arrays into `<link>` and `<script>` tags. Normal pages do not need to manually include their own CSS or JavaScript files when they follow the naming convention.

## Cache Busting

When a local CSS or JavaScript file exists, `View` appends its `filemtime()` as a query string:

```text
/css/page/about.css?1234567890
/js/page/about.js?1234567890
```

This keeps browser caches from hiding local asset changes during development and small deployments.

External URLs added through `View::addCSS()` or `View::addJS()` are passed through as-is.

## Smarty HTML Escaping

`class/xion/View.php` enables Smarty's `setEscapeHtml(true)` framework-wide so any `{$variable}` in a template is HTML-escaped before being written. This is the right default for a server-rendered surface and removes the need to write `{$variable|escape:'html'}` on every output.

The subtlety: **auto-escape runs after the modifier chain**. Modifiers that emit markup will have that markup escaped again, which usually breaks the intent. The most common case is `nl2br`:

```smarty
{$body|nl2br}
```

`nl2br` produces `<br />` from `\n`, then the auto-escape turns it into `&lt;br /&gt;`. The line break does not render — the literal `<br />` text shows up.

Two safe patterns:

1. **Use `nofilter` to opt that variable out of auto-escape, and call `escape` explicitly first** when the modifier you want emits markup intentionally:

   ```smarty
   {$body|escape:'html'|nl2br nofilter}
   ```

2. **Avoid the markup-emitting modifier entirely** and let CSS preserve formatting. For line breaks, `white-space: pre-line` works well:

   ```smarty
   <div class="note-body">{$body}</div>
   ```

   ```css
   .note-body { white-space: pre-line; }
   ```

Option 2 keeps the template trivial and the auto-escape contract intact, at the cost of one CSS rule. Prefer it unless you specifically need HTML output from a modifier (e.g. `{$markdown_html|nofilter}` for already-sanitized markup).

When you write a custom Smarty plugin or chain another markup-emitting modifier (`replace` with HTML, `html_*` series, etc.), reach for `nofilter` in the same way and treat any HTML the plugin produces as already trusted. The authoring conventions for those custom plugins are in `docs/development/smarty-plugins.md`.

## Recommended Shape

Prefer this shape for new server-rendered pages:

```text
class/controller/{Name}Controller.php
view/source/{name}/{action}.tpl
htdocs/css/{name}/{action}.css
htdocs/js/{name}/{action}.js
```

Keep markup in Smarty templates, styling in CSS files, and browser behavior in JavaScript files. Avoid inline CSS or large inline scripts unless the page has a narrow, documented reason.

This keeps NeNe's page layer easy to review: URL, controller action, template, CSS, and JavaScript all line up by name.
