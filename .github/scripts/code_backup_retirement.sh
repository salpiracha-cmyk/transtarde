#!/usr/bin/env bash
# Owner authorised retirement of the historical 499 code rollbacks on 19 October.
# No application/private-data directory is ever a deletion target.
set -euo pipefail
BR_BOOTSTRAP=(
  'before-24544bbbb072db611fc8e34d9ade358337744f86-20260914T081336Z.tgz|91a83b5a427277124ff11b5f0cb6d81225c42c188743f1832b590869aa573fd6|7362173'
  'before-55145d2aa26b7a18a8f494ceca5e5e3ad9557f90-20260914T083216Z.tgz|dc38525b2505e210403a30ce16bd01882f733a91b96c3d4a017050bb0b0674b0|7364428'
  'before-4147dfbcded91bcf6be3d958b7bef2caf1b2baf4-20260914T120427Z.tgz|d89a321462d3090cbf9d011194f5636d0bd3155c95caec77f99fd550ad7fbd50|7375396'
  'before-2011c073572d14b5bea4601fd464fd825ef9d201-20260914T122004Z.tgz|6481b08041e28799e0d605c429f24b48d2eb7aecbc786a922ef10e92e2b100f4|7374245'
  'before-2762866c57844c3468695705285c54070f9af373-20260914T122637Z.tgz|22eb30a07b92fbd4e7abb0127a1cf5badf92757f361ed7ea56f42b4158b7e348|7374223'
  'before-19f2ca04c056661cc6eebd0388b35819f53e3388-20260914T150804Z.tgz|a1504f1a91fc8da2b4b82a051a1f143c8c1ac9d631b31c30b07dcfc9b104ff75|7383421'
  'before-92c74d78553edef5b730110fbea1d955fd199e3e-20260914T151727Z.tgz|cc4810305402439ac5b15b7dd93886f135dc387a1d8b95cd095bb5a32ca9d998|7385089'
  'before-71a0ac5c06a8868260b797b2457e00187c6aa0d0-20260914T153630Z.tgz|54db4f2780a1ffec8f7ba33e4cb5c311c5e51eddb1011274a8c8ff66452c6425|7383802'
  'before-f153f5df524ea835c873dee4d1bd2eb3b2a13936-20260914T154623Z.tgz|ba00fcd5433afb829ba34c72aff30a2dc0b7b713ab1577948d0e469e922570ac|7385059'
  'before-81512656b32b4aca1e99183f25f7f445efa488be-20260914T161850Z.tgz|c078398ac899109e95b5e0ec053c2cc702ccca1882695a50edae5d521067c44b|7384130'
  'before-30bc069a4e3e3fc01f53d950baf0b0d37c698f51-20260914T171526Z.tgz|0916d0ef665c30b40ccedb944e2482d96336d44716480bedd2e4d2c5334b6ca5|7396280'
  'before-3a908aad27d1753a3073ad905e118507d40df684-20260914T172352Z.tgz|2cec50f68d8a4e2646c973a5c3e21bd8ed2697c0dac238b7ca229df8b211977c|7396718'
  'before-3acb2c871a4f7dc22338dc6890734c359fb152d0-20260914T173003Z.tgz|07c1d72d4eb9c323d42657a33f1f47830f38553302eeb9688142997f8f076a55|7398998'
  'before-a58a38a216090eb9211ab8dd933874f83a085f17-20260914T175202Z.tgz|0931d261ef9c8abc845dcd9b73be6526072f0c30013b06e2b3b529a5897be700|7399086'
  'before-cf6e220b2c2d367267b06fa8a5739f3fa5e2c484-20260914T183036Z.tgz|749e6ebf94ce7216ac75950382cfb99578d6c64b42cf0ac998a94d7dc130b1ba|7402096'
  'before-34a915d834b1e553576994756502eb197ed8103e-20260914T183313Z.tgz|0c9b046366cf7361b65ecf58abfaea830ac5325dc7f33e83f26ea735e610b940|7402451'
  'before-a012e4cc73f96dcaeecc945893ef4177727596d6-20260914T190357Z.tgz|e455329135e875da54cebc0fb142d0e06adbc9d3e6e8ee20d07db19f5dd6d05e|7405919'
  'before-ee11256e58b30200f3c7b2d68541204e8430205b-20260914T193148Z.tgz|ecf25c73940067e5d90556106d3b4c329b78b4128d6051077f13068fe0a601d7|7405828'
  'before-c6d9e8d129945d25e323598a2ca0e39ab24a67da-20260914T201325Z.tgz|33c8b0057cd2b99c1d8df38335fb2fe896dd92fd949416cac85e74f53788de85|7407521'
  'before-52a05b1778f12559af3d7029aad02bf32a8a70a8-20260914T203312Z.tgz|acbb8fe9c8a079e490133805f98a01563cb2ce8948e9da0f7672811a0b3200fd|7408687'
)
BR_BASELINES=(
  'before-39e505c3152572feae96350124b00b372ff07138-20261019T125650Z.tgz|13817324866757bf35d19cc55357194410f2f5c4fe3b873652217ab7a6f759b9|16602153'
  'before-6f44e5651b0c564e9016ff0db6e57a2533a962a2-20261019T124037Z.tgz|d11594ef7e93afed4f3a090ca0d9525efd2c8406cc9942c07260e88a10a833bd|16560838'
  'before-4b7834a6f62c7cf12aa67c38d6fdf630c8261864-20261019T114834Z.tgz|6be179ab4239052b4fd767a0a90dfd7871ba0962d0af99df223c33098c91b927|16544530'
)
br_archive() {
  [[ -f $1 && ! -L $1 ]] || return 1
  tar -tzf "$1" 2>/dev/null | awk '
    {n=$0;if(n ~ /^\//)bad=1;while(n ~ /^\.\//)sub(/^\.\//,"",n);if(n ~ /(^|\/)\.\.(\/|$)/ || n ~ /^(data|uploads|storage|cache|logs|backups|transtrade_private)(\/|$)/ || n ~ /^(auth|accounts|operations)\.json$/)bad=1;seen[n]=1}
    END {if(bad || !seen[".htaccess"] || !seen["auth_store.php"] || !seen["login.php"] || !seen["module.php"] || !seen["exports/app.js"])exit 1}'
}
br_hash() { local value;value=$(sha256sum "$1");printf '%s' "${value%% *}"; }
br_init() {
  BR_TARGET=$1;BR_SHA=$2
  [[ $BR_SHA =~ ^[a-f0-9]{40}$ ]] || return 1
  if [[ ${TT_CODE_BACKUP_FIXTURE:-0} != 1 ]]; then
    [[ $BR_TARGET =~ ^/home/[^/]+/domains/app\.transtradeinternational\.com/public_html$ ]] || return 1
  fi
  [[ -d $BR_TARGET && ! -L $BR_TARGET && $(realpath "$BR_TARGET") == "$BR_TARGET" ]] || return 1
  BR_ROOT=${BR_TARGET%/*}/.transtrade-deploy;BR_BACKUPS=$BR_ROOT/backups
  for path in "$BR_ROOT" "$BR_BACKUPS"; do [[ -d $path && ! -L $path && $(realpath "$path") == "$path" ]] || return 1; done
  shopt -s nullglob;umask 077
}
br_receipt() {
  local row=$1 name rest hash bytes
  name=${row%%|*};rest=${row#*|};hash=${rest%%|*};bytes=${rest#*|}
  [[ $name =~ ^before-[a-f0-9]{40}-[0-9]{8}T[0-9]{6}Z\.tgz$ && $hash =~ ^[a-f0-9]{64}$ && $bytes =~ ^[0-9]+$ ]] || return 1
  [[ -f $BR_BACKUPS/$name && ! -L $BR_BACKUPS/$name && $(stat -c %s "$BR_BACKUPS/$name") == "$bytes" && $(br_hash "$BR_BACKUPS/$name") == "$hash" ]] || return 1
}
br_snapshot() {
  local destination=$1 partial=$1.partial-$BASHPID
  [[ ${destination%/*} == "$BR_BACKUPS" && ! -e $partial && ! -L $destination ]] || return 1
  if [[ -f $destination ]]; then br_archive "$destination";return;fi
  if ! tar --exclude='./.git' --exclude='./node_modules' --exclude='./data' --exclude='./uploads' --exclude='./storage' --exclude='./cache' --exclude='./logs' --exclude='./backups' --exclude='./tmp' --exclude='*/error_log' -czf "$partial" -C "$BR_TARGET" .; then rm -f -- "$partial";return 1;fi
  if ! br_archive "$partial" || ! gzip -dc "$partial" | tar -d -f - -C "$BR_TARGET" >/dev/null; then rm -f -- "$partial";return 1;fi
  mv -- "$partial" "$destination"
  printf '%s\n' "$(br_hash "$destination")" > "$destination.sha256"
  echo "VERIFIED_LIVE_CODE_BASELINE bytes=$(stat -c %s "$destination") sha256=$(br_hash "$destination")"
}
br_bootstrap() {
  local row path name count=0 present=0 valid_current=0
  for path in "$BR_BACKUPS"/before-*.tgz; do
    name=${path##*/};[[ -f $path && ! -L $path && $name =~ ^before-[a-f0-9]{40}-[0-9]{8}T[0-9]{6}Z\.tgz$ ]] || continue
    count=$((count+1))
  done
  for row in "${BR_BOOTSTRAP[@]}"; do name=${row%%|*};[[ ! -e $BR_BACKUPS/$name && ! -L $BR_BACKUPS/$name ]] || present=$((present+1));done
  if ((count==499 && present==20)); then
    for row in "${BR_BASELINES[@]}"; do br_receipt "$row";br_archive "$BR_BACKUPS/${row%%|*}";done
    # Validate the entire exact approved bootstrap set before removing its first member.
    for row in "${BR_BOOTSTRAP[@]}"; do br_receipt "$row";done
    for row in "${BR_BOOTSTRAP[@]}"; do br_receipt "$row";rm -- "$BR_BACKUPS/${row%%|*}";done
    echo 'APPROVED_BOOTSTRAP_REMOVED count=20 bytes=147795550'
  elif ((count==479 && present==0)); then
    for row in "${BR_BASELINES[@]}"; do br_receipt "$row";br_archive "$BR_BACKUPS/${row%%|*}";done
  elif ((count<479 && present==0)); then
    for path in "$BR_BACKUPS"/verified-live-*.tgz; do if br_archive "$path"; then valid_current=1;break;fi;done
    ((valid_current==1)) || { echo 'STOP_NO_VERIFIED_RESUME_BASELINE' >&2;return 1; }
  else echo "STOP_CHANGED_ROLLBACK_INVENTORY count=$count bootstrapPresent=$present" >&2;return 1;fi
  br_snapshot "$BR_BACKUPS/verified-live-$BR_SHA-before.tgz"
}
br_finalize() {
  local keep=$BR_BACKUPS/verified-live-$BR_SHA.tgz path name stamp removed=0 bytes=0 held=0 size
  br_snapshot "$keep";br_archive "$keep"
  for path in "$BR_BACKUPS"/before-*.tgz; do
    name=${path##*/};[[ -f $path && ! -L $path && $name =~ ^before-[a-f0-9]{40}-[0-9]{8}T[0-9]{6}Z\.tgz$ ]] || continue
    stamp=${name##*-};stamp=${stamp%.tgz}
    [[ $stamp < 20261019T125651Z ]] || continue
    if ! br_archive "$path"; then held=$((held+1));echo "RETAINED_UNVERIFIED_CODE_ARCHIVE $name";continue;fi
    size=$(stat -c %s "$path");rm -- "$path";removed=$((removed+1));bytes=$((bytes+size))
  done
  # Consolidate only managed code copies after the fresh complete live copy verifies.
  br_rotate "$keep"
  for path in "$BR_BACKUPS"/login-recovery-"$BR_SHA"-*.json.gz; do
    [[ -f $path && ! -L $path && $(stat -c %s "$path") -lt 262144 ]] || continue
    gzip -t "$path";rm -- "$path"
  done
  echo "HISTORICAL_CODE_RETIREMENT remainingBatchRemoved=$removed bytes=$bytes held=$held businessFilesTouched=0"
  ((held==0))
}
br_rotate() {
  local keep=$1 path name removed=0
  [[ ${keep%/*} == "$BR_BACKUPS" && -f $keep && ! -L $keep ]] || return 1
  br_archive "$keep"
  for path in "$BR_BACKUPS"/before-*.tgz "$BR_BACKUPS"/verified-live-*.tgz; do
    [[ $path != "$keep" && -f $path && ! -L $path ]] || continue
    name=${path##*/}
    [[ $name =~ ^before-[a-f0-9]{40}-[0-9]{8}T[0-9]{6}Z\.tgz$ || $name =~ ^verified-live-[a-f0-9]{40}(-before)?\.tgz$ ]] || continue
    if ! br_archive "$path"; then echo "RETAINED_UNVERIFIED_CODE_ARCHIVE $name";continue;fi
    rm -- "$path";[[ ! -f $path.sha256 || -L $path.sha256 ]] || rm -- "$path.sha256";removed=$((removed+1))
  done
  echo "CODE_BACKUP_ROTATION kept=1 olderManagedCopiesRemoved=$removed privateBackupsTouched=0"
}
br_main() {
  local mode=$1; br_init "$2" "$3"
  case $mode in
    bootstrap) br_bootstrap ;;
    finalize) br_finalize ;;
    rotate) local keep='' path;for path in "$BR_BACKUPS"/before-"$BR_SHA"-*.tgz;do [[ -z $keep || $path > $keep ]] && keep=$path;done;[[ -n $keep ]] || return 1;br_rotate "$keep" ;;
    *) return 1 ;;
  esac
}
