#!/usr/bin/env bash
#
# Bootstrap a Field Trial report file from a template.
#
# Usage:
#   tools/ft-report-new.sh <FT-number> <topic-kebab> [--xion]
#
# Examples:
#   tools/ft-report-new.sh 265 daily-streak --xion   # Xion class report (Format B, ~50 lines)
#   tools/ft-report-new.sh 18  session-storage        # Full exploratory report (Format A)
#
# Templates:
#   (default)  docs/templates/field-trial-report.md       — Format A, full exploratory
#   --xion     docs/templates/field-trial-report-xion.md  — Format B, Xion helper class
#
# What it does:
#   1. Copies the selected template to
#      `docs/field-trials/YYYY-MM-field-trial-<N>.md`.
#   2. Substitutes `{N}` → trial number and `{topic}` → topic name.
#   3. Fills `{YYYY-MM-DD}` with today's date.
#   4. Refuses to overwrite an existing file.
#
# Every FT MUST have a report file — the archive trail entry in
# candidates.md is an index pointer, not a substitute.
#
# Exit codes:
#   0  report created
#   1  CLI usage error
#   2  target file already exists / template missing

set -euo pipefail

if [[ $# -lt 2 ]]; then
    echo "usage: tools/ft-report-new.sh <FT-number> <topic-kebab>" >&2
    echo "       e.g. tools/ft-report-new.sh 18 session-storage-backend" >&2
    exit 1
fi

ft_num="$1"
topic="$2"
xion_mode=0
if [[ "${3:-}" == "--xion" ]]; then
    xion_mode=1
fi

if ! [[ "$ft_num" =~ ^[0-9]+$ ]]; then
    echo "error: FT number must be numeric (got: $ft_num)" >&2
    exit 1
fi
if ! [[ "$topic" =~ ^[a-zA-Z][a-zA-Z0-9-]*$ ]]; then
    echo "error: topic must be kebab-case or PascalCase (got: $topic)" >&2
    exit 1
fi

framework_root="$(cd "$(dirname "$0")/.." && pwd)"
if [[ $xion_mode -eq 1 ]]; then
    template="$framework_root/docs/templates/field-trial-report-xion.md"
else
    template="$framework_root/docs/templates/field-trial-report.md"
fi
year_month="$(date +%Y-%m)"
today="$(date +%Y-%m-%d)"
target="$framework_root/docs/field-trials/${year_month}-field-trial-${ft_num}.md"

if [[ ! -f "$template" ]]; then
    echo "error: template not found at $template" >&2
    exit 2
fi
if [[ -e "$target" ]]; then
    echo "error: $target already exists — refusing to overwrite" >&2
    exit 2
fi

# Substitute placeholders:
#   {N}          → trial number
#   {N-1}        → trial number minus one
#   {topic}      → topic string
#   {YYYY-MM-DD} → today's date (braced form used in xion template)
#   YYYY-MM-DD   → today's date (bare form used in full-report template, first occurrence)
prev_num=$(( ft_num - 1 ))
awk -v num="$ft_num" -v prev="$prev_num" -v topic="$topic" -v today="$today" '
    BEGIN { date_replaced = 0 }
    {
        gsub(/\{N\}/, num)
        gsub(/\{N-1\}/, prev)
        gsub(/\{topic\}/, topic)
        gsub(/\{YYYY-MM-DD\}/, today)
        if (!date_replaced && $0 == "YYYY-MM-DD") {
            print today
            date_replaced = 1
            next
        }
        print
    }
' "$template" > "$target"

echo "✓ created $target"
echo
if [[ $xion_mode -eq 1 ]]; then
    echo "Format: B (Xion class report)"
    echo "Next:"
    echo "  - Fill in {ClassName}, API table, test list, key design points"
    echo "  - After PR merges: composer ft:done -- FT${ft_num} {ClassName} \"desc\" {PR}"
else
    echo "Format: A (full exploratory report)"
    echo "Next:"
    echo "  - Fill in Baseline / Goal / Service Built / Steps Taken"
    echo "  - Replace remaining {placeholders} as the trial progresses"
fi
echo "  - Commit via:  git checkout -b docs/ft${ft_num}-report"
