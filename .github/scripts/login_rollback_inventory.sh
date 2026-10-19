#!/usr/bin/env bash
set -euo pipefail
target=${1:?Production target required}
if [[ ${TT_LOGIN_INVENTORY_FIXTURE:-0} != 1 ]]; then
  [[ $target =~ ^/home/[^/]+/domains/app\.transtradeinternational\.com/public_html$ ]] || exit 1
fi
[[ -d $target && ! -L $target && $(realpath "$target") == "$target" ]] || exit 1
directory=${target%/*}/.transtrade-deploy/backups
[[ -d $directory && ! -L $directory && $(realpath "$directory") == "$directory" ]] || exit 1
shopt -s nullglob
items=(); ignored=0; total=0
for path in "$directory"/*; do
  name=${path##*/}
  if [[ -f $path && ! -L $path && $name =~ ^before-[a-f0-9]{40}-[0-9]{8}T[0-9]{6}Z\.tgz$ ]]; then
    items+=("$(stat -c %Y "$path")|$name"); total=$((total+$(stat -c %s "$path")))
  else ignored=$((ignored+1)); fi
done
sorted=("${items[@]}")
# The shared host has no /dev/fd; sort in memory without process substitution or temporary files.
for ((i=1;i<${#sorted[@]};i++)); do
  value=${sorted[i]}; stamp=${value%%|*}; j=$((i-1))
  while ((j>=0)); do
    left=${sorted[j]}; left_stamp=${left%%|*}
    [[ $left_stamp -gt $stamp || ( $left_stamp -eq $stamp && ${left#*|} > ${value#*|} ) ]] || break
    sorted[j+1]=$left; j=$((j-1))
  done
  sorted[j+1]=$value
done
count=${#items[@]}; proposed=$((count>30?count-30:0)); ((proposed<=20)) || proposed=20
verified=true; ((proposed>0 && count>=3)) || verified=false
proposed_bytes=0; candidates=''; baselines=''
archive_row() {
  local item=$1 name path hash valid=true size
  name=${item#*|}; path=$directory/$name; size=$(stat -c %s "$path")
  hash=$(sha256sum "$path"); hash=${hash%% *}
  [[ $hash =~ ^[a-f0-9]{64}$ ]] || return 1
  if ! tar -tzf "$path" 2>/dev/null | awk '
    {n=$0;if(n ~ /^\//)bad=1;while(n ~ /^\.\//)sub(/^\.\//,"",n);if(n ~ /(^|\/)\.\.(\/|$)/ || n ~ /^(data|uploads|storage|cache|logs|transtrade_private)(\/|$)/)bad=1;seen[n]=1}
    END {if(bad || !seen[".htaccess"] || !seen["auth_store.php"] || !seen["login.php"] || !seen["module.php"] || !seen["exports/app.js"])exit 1}'; then valid=false; fi
  printf '{"name":"%s","bytes":%s,"sha256":"%s","completeCodeArchive":%s}' "$name" "$size" "$hash" "$valid"
}
for ((i=0;i<proposed;i++)); do
  row=$(archive_row "${sorted[i]}"); [[ $row != *'"completeCodeArchive":false'* ]] || verified=false
  [[ -z $candidates ]] || candidates+=','; candidates+=$row
  name=${sorted[i]#*|}; proposed_bytes=$((proposed_bytes+$(stat -c %s "$directory/$name")))
done
for ((i=count-1;i>=0 && i>=count-3;i--)); do
  row=$(archive_row "${sorted[i]}"); [[ $row != *'"completeCodeArchive":false'* ]] || verified=false
  [[ -z $baselines ]] || baselines+=','; baselines+=$row
done
digest=$(printf '[%s]' "$candidates" | sha256sum); digest=${digest%% *}
printf '{"readOnly":true,"knownCodeBackups":%s,"totalBytes":%s,"unknownEntriesRetained":%s,"proposedOldestCount":%s,"proposedBytes":%s,"retainedKnownCodeBackups":%s,"verifiedLatestBaselines":[%s],"candidates":[%s],"candidateSetSha256":"%s","proposalVerified":%s,"filesDeleted":0}\n' "$count" "$total" "$ignored" "$proposed" "$proposed_bytes" "$((count-proposed))" "$baselines" "$candidates" "$digest" "$verified"
