#!/usr/bin/env bash
# The support number is stored once and read everywhere (docs/roadmap/06-selling-seo-and-whatsapp.md §4):
# config/support.php and App\Support\SupportContact. This scan fails when the number is typed anywhere else.
#
# Allowed places: config/, docs/, *.md and .env*.example. Skipped: vendor/, node_modules/, build/, public/build/.
# Files checked: everything git tracks plus new files it does not ignore yet, so a local run catches them before
# a commit. The number is assembled from parts below so this script does not match itself.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

a=783; b=204; c=665
pattern="${a}[ .-]?${b}[ .-]?${c}"
skip='^(vendor|node_modules|build|public/build)/'
allow='^(config|docs)/|\.md$|(^|/)\.env[^/]*\.example$'

hits=$(git ls-files --cached --others --exclude-standard \
    | grep -vE "$skip" \
    | grep -vE "$allow" \
    | while IFS= read -r f; do [ -f "$f" ] && printf '%s\0' "$f"; done \
    | xargs -0 grep -IlE "$pattern" 2>/dev/null || true)

if [ -n "$hits" ]; then
    echo "FAIL: the support number is typed outside config/, docs/, *.md and .env*.example."
    echo "Read it through App\\Support\\SupportContact (or config('support.*')) instead:"
    echo "$hits" | sed 's/^/  /'
    exit 1
fi

echo "support-number-scan: OK"
