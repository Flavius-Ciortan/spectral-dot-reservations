#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
wp_path="${1:?Usage: tests/hpos-matrix.sh /path/to/wordpress}"
original="$(wp eval "echo Automattic\\WooCommerce\\Utilities\\OrderUtil::custom_orders_table_usage_is_enabled() ? 'yes' : 'no';" --path="$wp_path")"

set_mode() {
	local mode="$1"
	wp wc hpos sync --batch-size=500 --path="$wp_path" >/dev/null
	wp wc hpos "$mode" --path="$wp_path" >/dev/null
}

restore() {
	set_mode "$([[ "$original" == yes ]] && printf enable || printf disable)" >/dev/null 2>&1 || true
}
trap restore EXIT

set_mode enable
enabled="$(wp eval "echo Automattic\\WooCommerce\\Utilities\\OrderUtil::custom_orders_table_usage_is_enabled() ? 'yes' : 'no';" --path="$wp_path")"
if [[ "$enabled" != yes ]]; then
	echo "WooCommerce did not enable HPOS." >&2
	exit 1
fi
wp eval "define('SDPR_INTEGRATION_TEST', true); require '$root/tests/integration-smoke.php';" --path="$wp_path"

set_mode disable
disabled="$(wp eval "echo Automattic\\WooCommerce\\Utilities\\OrderUtil::custom_orders_table_usage_is_enabled() ? 'yes' : 'no';" --path="$wp_path")"
if [[ "$disabled" != no ]]; then
	echo "WooCommerce did not enable legacy order storage." >&2
	exit 1
fi
wp eval "define('SDPR_INTEGRATION_TEST', true); require '$root/tests/integration-smoke.php';" --path="$wp_path"

echo "PASS: Integration suite completed with HPOS enabled and disabled."
