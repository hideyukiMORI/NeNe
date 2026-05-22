#!/usr/bin/env bash
#
# Wait until a NeNe instance reports `/health` 200, or timeout.
#
# Usage:
#   tools/wait-healthy.sh [port] [timeout-seconds]
#
# Examples:
#   tools/wait-healthy.sh                # framework default — 127.0.0.1:8080, 60s
#   tools/wait-healthy.sh 8097           # FT17 clone — 127.0.0.1:8097, 60s
#   tools/wait-healthy.sh 8080 120       # explicit timeout
#
# Exit codes:
#   0  /health responded 200 within the budget
#   1  /health did not respond within the budget
#
# Notes:
#   - The check only requires HTTP 200, not `healthStatus=ok`. For a stricter
#     gate (e.g. CI wait that requires the DB to be healthy too), use the
#     inline loop in `.github/workflows/tests.yml`.
#   - Sleep interval is 1s. After timeout, exits 1 with a hint to inspect
#     `docker compose logs app`.

set -euo pipefail

port="${1:-8080}"
timeout="${2:-60}"
url="http://127.0.0.1:${port}/health"

for i in $(seq 1 "$timeout"); do
    if curl -fs -o /dev/null "$url" 2>/dev/null; then
        echo "OK (${i}s) — $url"
        exit 0
    fi
    sleep 1
done

echo "timeout: $url did not respond within ${timeout}s" >&2
echo "       hint: docker compose logs app | tail -50" >&2
exit 1
