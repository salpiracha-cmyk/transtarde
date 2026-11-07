#!/usr/bin/env bash
set -euo pipefail
target=$(cd "$(dirname "$0")/.." && pwd)
case "$target" in
  /home/*/domains/app.transtradeinternational.com/public_html) ;;
  *) echo "Unexpected Telegram worker target." >&2; exit 1 ;;
esac
private="$(dirname "$target")/transtrade_private"
test -f "$private/telegram-alerts.json" || { echo "Telegram connection is not configured."; exit 1; }
command -v crontab >/dev/null || { echo "Hostinger crontab access is unavailable."; exit 1; }
php_path=$(command -v php)
# The report source must load successfully before the periodic task is enabled.
"$php_path" "$target/scripts/telegram-dispatch.php" --initialize
before=$(mktemp)
after=$(mktemp)
trap 'rm -f "$before" "$after"' EXIT
if ! crontab -l >"$before" 2>"$before.err"; then
  if ! grep -qi 'no crontab' "$before.err"; then cat "$before.err" >&2; rm -f "$before.err"; exit 1; fi
fi
rm -f "$before.err"
awk '!/ # transtrade-telegram-dispatch$/' "$before" >"$after"
printf '* * * * * cd %q && %q %q > %q 2>&1 # transtrade-telegram-dispatch\n'   "$target" "$php_path" "$target/scripts/telegram-dispatch.php" "$private/telegram-cron-last.log" >>"$after"
crontab "$after"
crontab -l | grep -q ' # transtrade-telegram-dispatch$'
echo "Telegram automatic delivery is installed every minute."
