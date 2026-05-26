# ADR-0011 — Smarty as template engine

Status: **accepted** (retrospective — recorded 2026-05-27, decision predates ADR practice)

## Context

NeNe is a legacy PHP framework that pre-dates the ADR practice introduced by ADR-0001. The choice to use Smarty as the template engine was made in the original project and was never formally recorded. As the project moves toward AI-assisted maintenance and onboarding new contributors, the "なぜ Smarty?" question will arise and the answer should not be institutional memory held only by the original author.

The relevant alternatives at the time of the original decision (and at the time of this retrospective) are:

| Engine | Maintained | PHP 8.x | Learning curve | Legacy compat |
| --- | --- | --- | --- | --- |
| **Smarty** | yes (v5.x) | yes | low (designers can learn) | high (large install base) |
| Twig | yes | yes | medium | medium |
| Blade | via package | yes | low | Laravel-specific idioms |
| plain PHP | n/a | n/a | zero | high |

## Decision

Use Smarty (`smarty/smarty ^5.x`) as NeNe's template engine. Maintain the existing `view/source/` / `view/compile/` / `view/config/` / `view/plugins/` layout.

### Rationale

1. **Pre-existing codebase.** The original AYANE / NeNe codebase was already built on Smarty before the OSS cleanup. Migrating would require rewriting every template, updating the `View` class, and training any existing deployers. The migration cost is high and the benefit is unclear for the target audience.

2. **Target audience familiarity.** NeNe's stated audience is developers maintaining legacy PHP codebases (ADR-0001 context). Smarty has a large legacy install base and is often already understood by the team a legacy deployer is working with. Switching to Twig would introduce a different DSL for that audience to learn.

3. **Designer-friendly DSL.** Smarty's `{$variable}` / `{if}` / `{foreach}` syntax is approachable for front-end developers and HTML designers who do not write PHP — a common split in the team setups NeNe targets. Twig is similarly approachable, but a switch would provide no incremental value here.

4. **Actively maintained.** Smarty v5 (released 2023) drops PHP 5/7 support, adds full PHP 8.x compatibility, and removes global constants that caused issues in earlier versions. The project is actively maintained and the `^5.x` constraint in `composer.json` picks up security and compatibility releases automatically.

5. **Plugin extensibility.** Smarty's plugin system (`view/plugins/`) lets NeNe ship custom modifiers, functions, and block tags without forking the engine. ADR-0001 through ADR-0010 have not needed any engine-level customizations — the plugin surface exists if needed. See `docs/development/smarty-plugins.md`.

6. **No framework lock-in.** Smarty is decoupled from NeNe's controller / router layer. A future migration (if warranted) would be contained to `class/xion/View.php` and the template files in `view/source/`. No routing, session, or DB code depends on the template engine.

### Why not Twig?

Twig would be a reasonable choice for a new project. The reasons not to migrate from Smarty to Twig are:

- Template rewrite cost with no functional gain for the existing deployer base.
- Twig templates are Twig — not reusable as Smarty templates and vice versa. Any migration is a flag day.
- Symfony 7 dependency chain: `twig/twig` is standalone, but the NeNe ecosystem (Symfony Mailer) already uses Symfony components. Adding Twig does not increase the Symfony surface, but it does add another moving part that tracks Symfony release cadence.

### Why not plain PHP templates?

Plain PHP is zero-dependency and universally understood. The counterarguments:

- Auto-escaping: Smarty escapes by default; plain PHP requires manual `htmlspecialchars()` everywhere. The failure mode (forgotten escape → XSS) is silent and hard to audit.
- The `view/source/` → `view/compile/` precompilation step provides a natural boundary between "template author" and "PHP execution". Removing it merges those roles.

## Consequences

- **Positive**: existing templates and the `View` class require no changes.
- **Positive**: deployers familiar with Smarty 2/3/4 can read Smarty 5 templates with minimal retraining.
- **Positive**: `view/plugins/` extensibility is available when needed.
- **Neutral**: template engine migration is a medium-sized effort if a future ADR decides to switch.
- **Negative**: Smarty adds a compile step and a `view/compile/` directory that must be writable. This is already handled by `init.sh` and `docker/app/`.

## Revisit trigger

Reconsider if:

- Smarty stops receiving security updates (currently maintained by the Smarty community).
- A real deployer proposes a migration with a concrete cost estimate and migration plan.
- PHP deprecates a feature Smarty relies on and Smarty does not fix it within a reasonable window.

Do **not** revisit based on "Twig is more popular" alone — popularity is not a correctness argument for an existing, working system.

## Related

- `docs/development/smarty-plugins.md` — custom plugin authoring under `view/plugins/`.
- `class/xion/View.php` — the Smarty wrapper; the only file that would change in a migration.
- `docs/field-trials/candidates.md` — this ADR closes the `Smarty selection ADR` candidate.
