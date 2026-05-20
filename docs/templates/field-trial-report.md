# Field Trial {N} — {topic}

Copy this skeleton to `docs/field-trials/YYYY-MM-field-trial-{N}.md` and fill it in. Keep it short. The full direction lives in `docs/field-trials/README.md`.

Before committing, confirm the report contains no secrets, raw API keys, local `.env` contents, private customer data, production URLs, or confidential prompts.

## Date

YYYY-MM-DD

## Baseline

- NeNe ref: {tag or commit hash from the cloned working tree}
- Clone path: `../NeNe-FT/ft{N}-{topic}/`
- PHP: {version reported by `php -v`}
- Database: {SQLite / MySQL / both, with version}
- Other relevant tooling: {Composer, Docker image, browser, etc.}

## Goal

One or two sentences on what this trial is verifying. Anchor it to a release, an Issue, a rewritten docs section, or a new convention. Do not list speculative improvements here.

## Service Built

- Name: {service short name}
- Domains: {entity list, e.g. `Memo`, `Tag`}
- Surface: {number of pages, REST endpoints, OpenAPI operations}
- DB tables: {list}

## Steps Taken

Number the steps in the order they actually happened. Embed `**Finding (F-N)**` notes inline at the point the friction occurred.

### 1. {short step title}

{what was attempted, what happened, code snippets if useful}

**Finding (F-1)**: {one to three sentences describing the friction. Include the rough location in NeNe — file path, doc path, or convention name — when known.}

### 2. {next step}

...

## Results

| Scenario | Expectation | Actual | Status |
| --- | --- | --- | --- |
| {what was tried} | {what should have happened} | {what happened} | Pass / Partial / Blocked |

Include only scenarios that the trial actually exercised. Do not pad the table with speculative checks.

## Friction Summary

| ID | Location | Severity | Kind | Decision |
| --- | --- | --- | --- | --- |
| F-1 | {file, doc, or convention} | high / medium / low | docs-gap / feature-gap / design-trade-off / legacy-preserved / process-gap | fix-in-framework / document / keep-legacy / defer |

Severity guide:

- **high** — a new project is likely to stall here until someone reads the framework source or asks a maintainer.
- **medium** — a new project will lose time but can recover by trial and error.
- **low** — a minor papercut, worth noting but not blocking.

Use the kinds and decisions defined in `docs/field-trials/README.md`. When `Decision` is `keep-legacy`, write one sentence in the recommendations explaining why the legacy shape is intentional.

## Recommendations

### Immediate (documentation only)

1. **{Finding ID} — {one-line title}**: {what to change, where to change it}.

### Suggested (small framework or template change)

1. **{Finding ID} — {one-line title}**: {what to change, where to change it. Include the smallest reasonable scope.}

### Trade-offs (needs ADR or discussion)

1. **{Finding ID} — {one-line title}**: {state the trade-off plainly. Do not pick a side here — that is what the ADR is for.}

If a category has nothing in it, write "None." rather than removing the section. Future readers should be able to tell the difference between "no trade-offs surfaced" and "trade-offs section was forgotten."

## Overall Impression

Two to four sentences. What felt easy, what felt slow, and whether the trial changed your read on a current convention or release. This section is for orienting future readers, not for marketing language.

## Follow-up Issues

- [ ] {Finding ID} — {one-line summary} → Issue #...
- [ ] {Finding ID} — {one-line summary} → Issue #...

Update this checklist as Issues are opened. When all are merged or closed with a rationale, the trial is complete.

## Reminder

This report is committed to a public repository. Confirm it omits secrets, raw keys, production endpoints, and confidential prompts before opening the PR.
