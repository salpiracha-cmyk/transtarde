#!/usr/bin/env bash
set -euo pipefail
target=$(cd "$(dirname "$0")/.." && pwd)
case "$target" in
  /home/*/domains/app.transtradeinternational.com/public_html) ;;
  *) echo "Unexpected Pohanch worker target." >&2; exit 1 ;;
esac
php_path=$(command -v php)
"$php_path" "$target/scripts/reconcile-pohanch-receipts.php"
if ! command -v crontab >/dev/null; then
  echo "::warning::Hostinger Cron Jobs panel is required for scheduled Pohanch retries."
  exit 0
fi
before=$(mktemp)
after=$(mktemp)
trap 'rm -f "$before" "$before.err" "$after"' EXIT
if ! crontab -l >"$before" 2>"$before.err" && ! grep -qi 'no crontab' "$before.err"; then
  echo "::warning::Unable to configure scheduled Pohanch retries via crontab."
  exit 0
fi
awk '!/ # transtrade-pohanch-receipts$/' "$before" >"$after"
printf '*/5 * * * * cd %q && %q %q > %q 2>&1 # transtrade-pohanch-receipts\n' \
  "$target" "$php_path" "$target/scripts/reconcile-pohanch-receipts.php" "$(dirname "$target")/transtrade_private/pohanch-cron-last.log" >>"$after"
if ! crontab "$after" || ! crontab -l | grep -q ' # transtrade-pohanch-receipts$'; then
  echo "::warning::Scheduled Pohanch retries could not be installed."
  exit 0
fi
echo "Scheduled Pohanch receipt retry enabled."
