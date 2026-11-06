#!/usr/bin/env bash
set -euo pipefail
target=$1
case "$target" in /home/*/domains/app.transtradeinternational.com/public_html) ;; *) exit 1 ;; esac
[[ -d "$target" && ! -L "$target" && "$(realpath "$target")" == "$target" ]]
cd "$target"
if [[ "${2:-}" == protect-json ]]; then
  for file in accounts/accounting_master_v1.json directors/costing-profiles.json; do
    directory=${file%/*}
    [[ "$(realpath "$directory")" == "$target/$directory" && -f "$file" && ! -L "$file" ]]
    [[ ! -L "$directory/.htaccess" ]]
    [[ ! -e "$directory/.htaccess" || -f "$directory/.htaccess" ]]
    php -r 'json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);' "$file"
  done
  for file in accounts/accounting_master_v1.json directors/costing-profiles.json; do
    directory=${file%/*}
    rule="$directory/.htaccess"
    marker="# Transtrade private JSON: ${file##*/}"
    if [[ -f "$rule" ]] && grep -Fxq "$marker" "$rule"; then continue; fi
    tmp=$(mktemp "$directory/.access-XXXXXXXX")
    if [[ -f "$rule" ]]; then cat "$rule" > "$tmp"; fi
    name=${file##*/}
    printf '\n%s\n<Files "%s">\n  Require all denied\n</Files>\n' "$marker" "$name" >> "$tmp"
    chmod 644 "$tmp"
    mv -f -- "$tmp" "$rule"
    printf 'PRIVATE_JSON_RULE_INSTALLED %s\n' "$file"
  done
  exit 0
fi
folders=(.git .github tests docs exports-canary ops)
for folder in "${folders[@]}"; do
  [[ ! -L "$folder" ]]
  [[ ! -e "$folder" || -d "$folder" ]]
done
runtime_digest() {
  find . \( -path './.git' -o -path './.github' -o -path './tests' -o -path './docs' -o -path './exports-canary' -o -path './ops' -o -path './data' -o -path './cache' -o -path './logs' -o -path './uploads' -o -path './storage' \) -prune -o -type f -print0 |
    sort -z | xargs -0 -r sha256sum | sha256sum
}
before=$(runtime_digest)
umask 077
hold=$(mktemp -d "${target%/public_html}/.housekeeping-XXXXXXXX")
moved=()
rollback() {
  for folder in "${moved[@]}"; do mv -- "$hold/$folder" "$target/$folder"; done
}
trap rollback ERR
for folder in "${folders[@]}"; do
  [[ -d "$folder" ]] || continue
  mv -- "$folder" "$hold/$folder"
  moved+=("$folder")
  printf 'MOVED_OUT_OF_WEB_ROOT %s\n' "$folder"
done
[[ "$before" == "$(runtime_digest)" ]]
trap - ERR
printf 'RUNTIME_FILES_HASH_MATCH=1\nRECOVERABLE_HOLD=%s\n' "${hold##*/}"
du -sh "$hold" | cut -f1
