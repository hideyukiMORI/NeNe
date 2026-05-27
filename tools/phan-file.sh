#!/usr/bin/env bash
# Run Phan analysis limited to specific files (fast local check before push).
#
# Usage:
#   bash tools/phan-file.sh class/xion/Foo.php tests/Unit/Xion/FooTest.php
#   bash tools/phan-file.sh class/xion/Foo.php   # class only
#
# Phan still parses all vendor stubs for type info, but only analyses the
# listed files — typically ~14s vs ~40s for the full baseline run.
#
# Exit code mirrors Phan: 0 = clean, 1 = errors found.

set -euo pipefail

if [[ $# -eq 0 ]]; then
    echo "Usage: $0 <file1.php> [file2.php ...]" >&2
    exit 1
fi

# Join args with comma for --include-analysis-file-list
FILES=$(IFS=,; echo "$*")

exec php vendor/bin/phan \
    --allow-polyfill-parser \
    --load-baseline .phan/baseline.php \
    --include-analysis-file-list "${FILES}"
