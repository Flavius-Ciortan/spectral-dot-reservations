#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
wp_path="${1:?Usage: tests/transition-races.sh /path/to/wordpress}"
temporary="$(mktemp -d)"
run() { SDPR_TRANSITION_RACE_TEST=1 SDPR_RACE_CASE="$case" SDPR_RACE_PHASE="$1" wp eval-file "$root/tests/transition-race.php" --path="$wp_path"; }
cleanup() { run cleanup >/dev/null 2>&1 || true; rm -rf "$temporary"; }
case=approve-cancel
trap cleanup EXIT
for case in approve-cancel cancel-approve approve-expire cancel-expire transfer-expire transfer-cancel expire-transfer transfer-transfer; do
    run setup
    run holder >"$temporary/holder.log" 2>&1 &
    holder_pid=$!
    ready=false
    for _ in $(seq 1 100); do
        if wp option get sdpr_transition_race_ready --path="$wp_path" >/dev/null 2>&1; then ready=true; break; fi
        sleep 0.05
    done
    if [[ "$ready" != true ]]; then cat "$temporary/holder.log"; exit 1; fi
    run contender
    wait "$holder_pid"
    run verify
    run cleanup
done
echo 'PASS: All eight two-process transition races preserved stock and released locks.'
