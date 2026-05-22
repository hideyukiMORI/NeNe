#!/usr/bin/env bash
#
# Show every Issue and PR mentioning a Field Trial in one view.
#
# Usage:
#   tools/trial-status.sh <FT-N>
#
# Examples:
#   tools/trial-status.sh FT17
#   tools/trial-status.sh FT3
#
# Why:
#   When closing out a trial, the maintainer (or AI agent) wants to verify
#   every spawned Issue is closed and every follow-up PR merged. Running
#   `gh issue list --search FT17` and `gh pr list --search FT17` separately
#   was the pre-existing dance — this is the merged view.
#
# Notes:
#   - Searches by the literal string (e.g. "FT17") so the trial number must
#     appear in the Issue title or body. Existing trial Issues follow that
#     convention (`FT17: schema-diff …`).
#   - Includes both open and closed.

set -euo pipefail

trial="${1:?usage: tools/trial-status.sh <FT-N>}"

echo "=== Issues mentioning $trial (open + closed) ==="
gh issue list --search "$trial" --state all --limit 50

echo
echo "=== PRs mentioning $trial (open + closed) ==="
gh pr list --search "$trial" --state all --limit 50
