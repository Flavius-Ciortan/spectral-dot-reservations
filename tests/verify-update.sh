#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
wp_path="${1:?Usage: tests/verify-update.sh /path/to/disposable/wordpress}"

wp eval "define('SDPR_INTEGRATION_TEST', true); require '$root/tests/integration-smoke.php';" --path="$wp_path"
SDPR_REVIEW_TEST=1 wp eval-file "$root/tests/review-coverage.php" --path="$wp_path"
SDPR_VARIATION_TEST=1 wp eval-file "$root/tests/variation-reservations.php" --path="$wp_path"
SDPR_QUERY_TEST=1 wp eval-file "$root/tests/query-efficiency.php" --path="$wp_path"
SDPR_REBRAND_TEST=1 wp eval-file "$root/tests/rebranding-smoke.php" --path="$wp_path"
SDPR_ADMIN_LIST_TEST=1 wp eval-file "$root/tests/admin-list-smoke.php" --path="$wp_path"
bash "$root/tests/concurrency-stress.sh" "$wp_path"
SDPR_STRESS_VARIATIONS=1 bash "$root/tests/concurrency-stress.sh" "$wp_path"
bash "$root/tests/transition-races.sh" "$wp_path"
bash "$root/tests/hpos-matrix.sh" "$wp_path"

echo 'PASS: Serial update regression suites completed.'
