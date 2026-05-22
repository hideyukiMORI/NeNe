# Journal

Day-by-day session summaries — what was worked on, what merged, what got learned, what's queued for next time. Different from:

- `docs/field-trials/` — trial-specific reports, scoped to one FT each.
- `docs/todo/current.md` — current state of the work, kept fresh; loses history when items move out of "Active".
- `docs/roadmap.md` — phase-level direction, slow-moving.
- `docs/releases.md` — versioned release notes for users of the framework.

The journal is the **working log** for maintainers and reviewing AI agents. It captures the texture of a session (what was hard, what surprised, what was deferred) that a list-of-merged-PRs would lose.

## File naming

`docs/journal/YYYY-MM-DD.md`, one file per session-day. If multiple sessions happen on the same date, append the file in place rather than creating a sibling.

## What belongs here

- Work completed (with PR / Issue references)
- ADRs accepted / drafted
- Notable bugs surfaced + fixed
- Technical traps documented (so future selves don't re-discover them)
- Cross-repo handoffs
- What's queued for the next session

## What does **not** belong here

- Code recipes — those go in `docs/development/`.
- Design decisions — those become ADRs.
- Active TODO — that lives in `docs/todo/current.md`.

A journal entry should be useful 6 months later as "what was the context when this PR was merged?" not as a substitute for the actual artifacts.
