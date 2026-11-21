#!/usr/bin/env bash
# COR-297: writes the paste-ready SQL editor copy of orders-profile-export.sql, with the committed file's sha256 written in.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
src="$root/database/seed-profile/orders-profile-export.sql"
out="$root/examples/seed-profile/orders-profile-editor.sql"
placeholder="'__SQL_SHA256__'"

if [[ "$(grep -c -F "$placeholder" "$src")" != 1 ]]; then
    echo "orders-profile-editor-copy: expected exactly one $placeholder in $src" >&2
    exit 1
fi

sha="$(shasum -a 256 "$src" | cut -c1-64)"
mkdir -p "$(dirname "$out")"
sed "s/$placeholder/'$sha'/" "$src" > "$out"
echo "$out"
