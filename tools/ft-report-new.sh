#!/usr/bin/env bash
#
# Bootstrap a Field Trial report file from the canonical template.
#
# Usage:
#   tools/ft-report-new.sh <FT-number> <topic-kebab>
#
# Examples:
#   tools/ft-report-new.sh 18 session-storage-backend
#   tools/ft-report-new.sh 19 structured-logs
#
# What it does:
#   1. Copies `docs/templates/field-trial-report.md` to
#      `docs/field-trials/YYYY-MM-field-trial-<N>.md`.
#   2. Substitutes `{N}` → trial number and `{topic}` → topic name
#      in the heading line.
#   3. Fills the `YYYY-MM-DD` placeholder under `## Date` with today's date.
#   4. Refuses to overwrite an existing file (so re-running on the same
#      trial does not blow away in-progress notes).
#
# Why this exists:
#   Every FT report uses the same skeleton, so writing each one from
#   scratch wastes the first 5 minutes of the wrap-up step.
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

if ! [[ "$ft_num" =~ ^[0-9]+$ ]]; then
    echo "error: FT number must be numeric (got: $ft_num)" >&2
    exit 1
fi
if ! [[ "$topic" =~ ^[a-z][a-z0-9-]*$ ]]; then
    echo "error: topic must be kebab-case lowercase (got: $topic)" >&2
    exit 1
fi

framework_root="$(cd "$(dirname "$0")/.." && pwd)"
template="$framework_root/docs/templates/field-trial-report.md"
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
#   {N}      → trial number
#   {topic}  → topic (keep the topic line literal until the author fills it)
#   The `YYYY-MM-DD` literal under `## Date` → today's date (first occurrence only)
awk -v num="$ft_num" -v topic="$topic" -v today="$today" '
    BEGIN { date_replaced = 0 }
    {
        gsub(/\{N\}/, num)
        gsub(/\{topic\}/, topic)
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
echo "Next:"
echo "  - Fill in Baseline / Goal / Service Built / Steps Taken"
echo "  - Replace remaining {placeholders} as the trial progresses"
echo "  - Commit via:  git checkout -b docs/<issue>-field-trial-${ft_num}-${topic}"
