#!/usr/bin/env bash
set -euo pipefail
target=$(cd "$(dirname "$0")/.." && pwd)
case "$target" in
  /home/*/domains/app.transtradeinternational.com/public_html) ;;
  *) echo "Unexpected Telegram worker target." >&2; exit 1 ;;
esac
private="$(dirname "$target")/transtrade_private"
php_path=$(command -v php)
# The report source must load successfully before the periodic task is enabled.
"$php_path" "$target/scripts/telegram-dispatch.php" --initialize
if ! command -v crontab >/dev/null; then
  echo "Hostinger requires its Cron Jobs panel for the one-minute schedule."
  echo "TELEGRAM_CRON_PHP_SCRIPT=$target/scripts/telegram-dispatch.php"
  echo "GitHub's scheduled delivery check provides the automatic fallback."
  exit 0
fi
before=$(mktemp)
after=$(mktemp)
trap 'rm -f "$before" "$after"' EXIT
if ! crontab -l >"$before" 2>"$before.err"; then
  if ! grep -qi 'no crontab' "$before.err"; then echo "Hostinger Cron Jobs panel is required. GitHub scheduled checks remain active."; rm -f "$before.err"; exit 0; fi
fi
rm -f "$before.err"
awk '!/ # transtrade-telegram-dispatch$/' "$before" >"$after"
printf '* * * * * cd %q && %q %q > %q 2>&1 # transtrade-telegram-dispatch\n'   "$target" "$php_path" "$target/scripts/telegram-dispatch.php" "$private/telegram-cron-last.log" >>"$after"
crontab "$after"
crontab -l | grep -q ' # transtrade-telegram-dispatch$'
echo "Social media alerts are checked every minute."
