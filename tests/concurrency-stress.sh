#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
wp_path="${1:?Usage: tests/concurrency-stress.sh /path/to/wordpress}"
temporary="$(mktemp -d)"

cleanup() {
	wp eval-file "$root/tests/concurrency-cleanup.php" --path="$wp_path" >/dev/null 2>&1 || true
	rm -rf "$temporary"
}
trap cleanup EXIT

setup="$(wp eval-file "$root/tests/concurrency-setup.php" --path="$wp_path")"
product_id="$(printf '%s\n' "$setup" | sed -n 's/^PRODUCT_ID=//p')"
if [[ ! "$product_id" =~ ^[0-9]+$ ]]; then
	echo "Concurrency setup did not return a product ID." >&2
	exit 1
fi

SDPR_STRESS_ROLE=holder wp eval-file "$root/tests/concurrency-worker.php" --path="$wp_path" >"$temporary/holder.log" 2>&1 &
holder_pid=$!

lock_key="sdpr_lock_product_${product_id}"
for _ in $(seq 1 100); do
	if wp option get "$lock_key" --path="$wp_path" >/dev/null 2>&1; then
		break
	fi
	sleep 0.05
done
if ! wp option get "$lock_key" --path="$wp_path" >/dev/null 2>&1; then
	echo "Holder process did not acquire the product lock." >&2
	exit 1
fi

SDPR_STRESS_ROLE=contender wp eval-file "$root/tests/concurrency-worker.php" --path="$wp_path" >"$temporary/contender.log" 2>&1
wait "$holder_pid"
wp eval-file "$root/tests/concurrency-verify.php" --path="$wp_path"
