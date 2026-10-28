#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
wp_path="${1:?Usage: tests/store-api-checkout.sh /path/to/disposable/wordpress}"
for shared in 0 1; do
	for minutes in 0 60; do
		SDPR_STORE_API_TEST=1 SDPR_STORE_API_SHARED="$shared" SDPR_STORE_API_HOLD_MINUTES="$minutes" wp eval-file "$root/tests/store-api-checkout.php" --path="$wp_path"
	done
	SDPR_STORE_API_TEST=1 SDPR_STORE_API_SHARED="$shared" SDPR_STORE_API_HOLD_MINUTES=0 SDPR_STORE_API_MISMATCH=1 wp eval-file "$root/tests/store-api-checkout.php" --path="$wp_path"
done
echo 'PASS: Real Store API checkout and uncovered-option rejection completed.'
