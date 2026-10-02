#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
wp_path="${1:?Usage: tests/release-lifecycle.sh /path/to/disposable-wordpress}"

if [[ "${SDPR_ALLOW_DESTRUCTIVE_RELEASE_TEST:-}" != 1 ]]; then
	echo "Set SDPR_ALLOW_DESTRUCTIVE_RELEASE_TEST=1 for a disposable WordPress installation." >&2
	exit 1
fi

setup="$(SDPR_ALLOW_DESTRUCTIVE_RELEASE_TEST=1 wp eval-file "$root/tests/release-lifecycle-setup.php" --path="$wp_path")"
product_id="$(printf '%s\n' "$setup" | sed -n 's/^PRODUCT_ID=//p')"
user_id="$(printf '%s\n' "$setup" | sed -n 's/^USER_ID=//p')"
reservation_id="$(printf '%s\n' "$setup" | sed -n 's/^RESERVATION_ID=//p')"
if [[ ! "$product_id" =~ ^[0-9]+$ || ! "$user_id" =~ ^[0-9]+$ || ! "$reservation_id" =~ ^[0-9]+$ ]]; then
	echo "Lifecycle setup did not return valid IDs." >&2
	exit 1
fi

export SDPR_ALLOW_DESTRUCTIVE_RELEASE_TEST=1
export SDPR_RELEASE_PRODUCT_ID="$product_id"
export SDPR_RELEASE_USER_ID="$user_id"
export SDPR_RELEASE_RESERVATION_ID="$reservation_id"

cleanup() {
	wp plugin activate spectral-dot-reservations --path="$wp_path" >/dev/null 2>&1 || true
	wp post delete "$reservation_id" "$product_id" --force --path="$wp_path" >/dev/null 2>&1 || true
	wp user delete "$user_id" --yes --path="$wp_path" >/dev/null 2>&1 || true
}
trap cleanup EXIT

wp plugin deactivate spectral-dot-reservations --path="$wp_path" >/dev/null
wp eval-file "$root/tests/release-lifecycle-deactivated.php" --path="$wp_path"
wp plugin activate spectral-dot-reservations --path="$wp_path" >/dev/null
wp eval-file "$root/tests/release-lifecycle-reactivated.php" --path="$wp_path"
wp plugin deactivate spectral-dot-reservations --path="$wp_path" >/dev/null
wp plugin uninstall spectral-dot-reservations --skip-delete --path="$wp_path" >/dev/null
wp eval-file "$root/tests/release-lifecycle-uninstalled.php" --path="$wp_path"
wp plugin activate spectral-dot-reservations --path="$wp_path" >/dev/null
wp eval-file "$root/tests/release-lifecycle-final.php" --path="$wp_path"
