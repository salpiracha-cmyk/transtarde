# Resumed audit — 20 October 2026

Salman authorised continuation of the entire audit. This pass starts from
`b32c621664ab6dedeb989edd0157615f3d03f308`, following the deployed PR393 release.
Local Chrome reconnected and Salman signed in as Super Admin. Earlier reports
that Chrome was unavailable describe the earlier session only.

## Confirmed defects repaired in PR395

- The owner backup page contained contradictory retention instructions: its
  paragraph described the approved 15-day server policy while its protection
  card still advertised 48 hours / 30 days / 12 months / 12 manual copies.
  The card now describes four recent points, daily seven-day points, up to two
  weekly points through day 15, and three manual/pre-restore points each.
- The separately installed office agent skipped backup downloads for seven
  days despite creating Daily/Weekly/Monthly directories. It now downloads
  on the next UTC calendar day, retries if its recorded local Daily file is missing, and
  does not suppress a pull after a backward clock change. Repeated Sunday
  pulls no longer create multiple weekly retention points.
- Setup instructions now require a daily task. The owner screen explicitly
  distinguishes an active credential from a verified installed/running PC task.
  Existing configured retention counts remain unchanged. Office copies are
  separate from the hosted 15-day policy.

Feature `6ffae107200f9d21db7b754762a32bad21030ee5`, exact tree
`d5d52cbb6a238909f8de82d12a60cd0a724ebc76`, passed Milling/Exports39321830614,
Accounts39321830460 (including inventory runtime) and backup-policy39321830281.
The local tree matched the connector-created tree. Local office transfer,
cadence, retention, checksum and shipment archive tests passed, as did the
disposable server snapshot/restore hardening test. No production restore ran.
PR395 merged as `4f288d72a96d367039bb49244989312e5752abdc`.
Exact-main Accounts39322449405 and Milling/Exports39322449105 passed.
Protected policy39322449381/job118299737278 installed the corrected page and
verified a739-file487241325-byte backup, SHA256
`f7cd634757cecc2e41c4e5c21c32a609f92ef6f4364b2ce9ba2e27ed5da753fb`.
Protected total was15859320384bytes across52 files; zero managed snapshots were
over15days. The independent maintenance path repeated verification without a
further copy or size change. Live records/documents deleted:0; login health200.
This manual deployment/maintenance acceptance is not the first scheduled cron run.
Full deployment39322926158/job118301308718 installed exact4f288d72 and passed
provider/login health. User changes0; existing418journals preserved. Actual Chrome
then showed the corrected Recent/Daily/Weekly/Manual card and office-task caveat;
its full-page rendering was inspected. No credential was rotated.
Startup39323042108/job118301707737 passed: sign-in redirect661ms and usable
Accounts687ms on this runner. This successful observation does not explain all
historical intermittent failures.
Protected live39323042002/job118301707573 passed23/23 in2.3minutes:53active
account ledgers across3companies;29posted JVs inspected; September Brokerage WHT
source/screen/CSV/print credit2414.06 agreed; zero financial, stock, reference-link
or annual-assessment writes. This acceptance belongs to4f288d72, before the
calendar-day agent follow-up.

## Calendar-day agent follow-up (PR396)

A strict24-hour interval can skip the next fixed-time Daily trigger because the
previous transfer completed a few minutes after the scheduled start. PR396 uses
UTC calendar days, matching the existing archive naming/buckets; a prior-day
success only23hours old must not skip. The regression covers this case together
with recent same-day, overdue, missing-file, clock rollback and forced pulls.
The remaining stale server-retention paragraph in BACKUP_RECOVERY.md now points
to the canonical15-day policy. No installed PC task was changed by this repair.
Feature066b8825ea01bd808331a1f78d432b5914fb8b5b/tree7c181014942a587a91e991fcde7e36e26c78dad7
passed Accounts39323715157 and Milling/Exports39323715286. The local tree matched.
PR396 merged as198a8267bb8e0706c6c3c464cab4420e50e4df85. This follow-up changes
only the PC agent, its regression and recovery guidance; deployed web application
PHP/JavaScript/HTML/CSS remain byte-identical to the23/23accepted PR395 release.
Exact-main198a8267 Accounts39324282932 and Milling/Exports39324282837 both passed.
Deployment39324771945/job118307621122 installed that exact SHA, preserved all
418journals with0user changes and passed provider/login health. The new agent is
available in the deployed/repository code; this does not update an installed PC.
Office device-specific acceptance still requires the installed office agent/task
and actual destination/recovery check; repository fixtures do not certify that PC.
Physical printing and every original issued output remain separate acceptance.

## Actual Chrome read-only acceptance

- Backup status eventually loaded: fresh automatic point20October01:40PKT,
  44 server ZIPs, UI-rounded16GB, last owner download14October18:29PKT,
  backup-only credential active. The snapshot selector populated. Initial
  Checking placeholders were not a persistent failure. This rounded UI total
  does not replace the earlier exact protected storage inventory.
- Directors home had no waiting bank-deletion or bag-discrepancy decisions.
  The refreshed Super Admin Console also verified0pending approvals.
  No approval was fabricated to exercise a button.
- Daily Position used20October and showed52 book-balance sources, four pending
  TG confirmations, zero physical stock sources, three purchase and three sales
  commitments. No reviewed Daily snapshot was saved.
- All six Annual Zakat sections opened: date/rates, group financials, trading
  assets, gold, shares, and adjustments. The incomplete assessment remains
  explicitly partial and cannot carry forward as final. No annual entry,
  completion, payment or monetary counterpart was inserted.
- Assets showed four existing vehicles with acquisition not entered. Broker
  funds showed BMA17000 and original POST-2026-00092 dated8October; no holdings
  were recorded. These are read observations, not transaction acceptance.
- Freight, Brokerage/WHT, TG overview/banks/effective FX/compliance opened.
  Saved source facts and rates were preserved. No new rate or document uploaded.
- TTI and BRM Ghati reports both explicitly showed no stock movements or physical
  confirmations, no weighted Raw Rice rate and no reconciled event. These remain
  unavailable business acceptance cases, not a reason to infer stock from B/F.
- The Directors opening-balance route loaded the fixed1July2026 date, temporary
  opening mode and24 existing opening entries. No opening switch or posting changed.
- Console master lists and Salary/Staff31 records rendered; no master saved.
  Live recorded advances confirm Yar Azam B/F79000, remaining79000, monthly1000,
  POST-2026-00487, and Jazib B/F27000, remaining27000, monthly1000,
  POST-2026-00489. Both original entries are dated1July2026. No duplicate entry
  was inserted. Accounts home bank balance remained9313735.81.
  Visible Directors pages produced no warning/error console entries when checked.

The broad Directors approval/report row moves from not tested to partial because
of this concrete report/section coverage. It does not certify all decisions or
role parity. Coverage remains156 actions:124passed,22partial,3failed,
4not tested,2blocked,1intermittent;32 actions still need some acceptance.

## Remaining evidence and environment requirements

The original-source decisions in CLOSEOUT_2026-10-20.md remain open: approved
annual Zakat counterpart/opening allocation, inactive FTR mapping, AMT13 legal
wording/HS, a separately authorised revision of the old ASAS62 uploaded packing
PDF, actual Mill/bag stock openings and agreed Maize labour rates. Current
generated packing layout is repaired; the historical issued original is preserved.

Original positive production, mixed/billed Corn replacement, finance, property,
share trades, supplier scans and all actual approval/cancellation variants require
approved records or an independently identified staging dataset. Fixture passes
do not certify missing real transactions. Full migration/recovery acceptance still
requires an identified source/target, independent control totals, oversized
offline/browser rehearsal and rollback. No production test posting is authorised
by this audit.

The office PC task history, installed agent version, destination, most recent
Daily/Weekly/Monthly files and independently verified encrypted ZIP are still
unseen. Hosted deployment alone does not install/update that PC. Its schedule
must not be reported as running without those checks.

The formal security scan remains blocked before source review: the security-scan
skill requires preflight ready, but its supporting scan-prologue resource could
not be read and no preflight helper was exposed. No formal clearance or worker
scan is claimed. Functional fixtures remain distinct from this blocked scan.

Final startup39324907337 also passed on198a8267. Its automatically triggered
repeat live smoke39324907243 was still running when this checkpoint was prepared;
the prior23/23acceptance above applies to the byte-identical web application.
