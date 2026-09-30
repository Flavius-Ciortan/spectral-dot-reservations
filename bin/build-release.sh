#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
slug="spectral-dot-reservations"
main_file="$root/spectral-dot-reservations.php"
dist_dir="$root/dist"

cd "$root"

if [[ -n "$(git status --porcelain --untracked-files=normal)" ]]; then
	echo "Release builds require a clean working tree." >&2
	exit 1
fi

header_version="$(sed -n 's/^ \* Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$main_file" | head -n 1)"
constant_version="$(sed -n "s/^define( 'SDPR_VERSION', '\([^']*\)' );/\1/p" "$main_file" | head -n 1)"
stable_version="$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$root/readme.txt" | head -n 1)"
if [[ -z "$header_version" || "$header_version" != "$constant_version" || "$header_version" != "$stable_version" ]]; then
	echo "Plugin header, SDPR_VERSION and readme stable tag must contain the same version." >&2
	exit 1
fi

# A stable default makes equivalent source trees reproducible across commits and hosts.
source_date_epoch="${SOURCE_DATE_EPOCH:-946684800}"
archive_name="$slug-$header_version.zip"
temporary="$(mktemp -d)"
trap 'rm -rf "$temporary"' EXIT

git archive --format=tar --prefix="$slug/" HEAD | tar -xf - -C "$temporary"

while IFS= read -r excluded || [[ -n "$excluded" ]]; do
	[[ -z "$excluded" || "$excluded" == \#* ]] && continue
	excluded="${excluded#/}"
	rm -rf "$temporary/$slug/$excluded"
done < "$root/.distignore"

find "$temporary/$slug" -type d -exec chmod 0755 {} +
find "$temporary/$slug" -type f -exec chmod 0644 {} +
export TZ=UTC
find "$temporary/$slug" -exec touch -h -d "@$source_date_epoch" {} +
mkdir -p "$dist_dir"
rm -f "$dist_dir/$archive_name" "$dist_dir/$archive_name.sha256"

(
	cd "$temporary"
	find "$slug" -print | LC_ALL=C sort | zip -X -q "$dist_dir/$archive_name" -@
)

(
	cd "$dist_dir"
	sha256sum "$archive_name" > "$archive_name.sha256"
)

echo "$dist_dir/$archive_name"
