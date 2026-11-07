# Current owner-approved plan — 26 October 2026

This section supersedes the older scheduled hosting and PC tier replacement rules below. Those sections describe legacy installations and retained recovery points.

- PC data: a complete cumulative snapshot of every saved module, uploaded document, users, masters and settings at backup time. Retain one verified copy per latest seven successful Pakistan dates. On the eighth successful date, remove the oldest only after verifying its replacement. A monthly copy is independent, with twelve monthly points. No new weekly data copies; legacy FULL/Weekly archives remain held.
- PC software: a separate Monday 18:00 Pakistan-time archive of application code, design, users, master records and settings. Manual Backup Now and the Software Weekly task also refresh it. It excludes business journals and shipment documents. Keep two recent software dates plus compatible code versions required by retained data archives. Unknown/unreadable files are held. Each data archive retains its own matching users/masters so newly-created IDs remain recoverable.
- Code compatibility: archive manifests record the deployed software revision. Combining Data + Software for hosting requires equal revisions; a mismatch stops without publishing a partial recovery. Refresh Backup Now after a software deployment. Software versions referenced by retained data points are protected from pruning.
- Hosting: no routine snapshot schedule. Preserve existing hosted recovery points. Manual and pre-restore safety snapshots remain available under their verified retention rules. Private transfer caches are transient and removed after the PC acknowledges a verified download. A running website or its edit history is not an independent backup.
- Recovery: Accounts, Mill, Exports and Directors contain readable CSV reports/records and documents. Complete Website Restore contains public_html code and sibling transtrade_private canonical data. Data-only recovery explicitly requires compatible Software and Setup before hosting. Never place the private folder inside public_html. Preparing files is not a live website restore.
- Upgrade: hosting deployment does not change the existing PC. Use the current EXE → Update existing PC settings (keep connection), after any current backup finishes. Preserve credentials, old archives and shipment delivery. Verify first Software, Daily and Monthly copies before declaring the PC updated.
- Failed creation, transfer, verification or recovery must preserve prior valid copies. Do not delete live records or original documents. Prune only known regular single-link archives after identity and full survivor hash checks.

## Confirmed resumable website restore — 26 October 2026

- One owner-bound private upload, 1 MiB pieces, content fingerprints and per-piece SHA256; no saved plaintext password or encryption key. Data and legacy Full archives below 4 GiB are supported; Software-only archives are refused.
- Verification stages private files in the excluded backup workspace. Live business data changes only after the owner types RESTORE and clicks Confirm Restore. Authentication, CSRF and role checks remain mandatory on every request.
- A shared request lock drains existing requests; a persistent maintenance marker keeps ordinary requests blocked across restore steps and process loss. The owner can sign in and resume from restore.php. Recovery has a separate idempotent journal, not ordinary offline-entry acknowledgements.
- All affected originals are copied and checked before mutation. Replace in bounded batches, validate restored files, and use transactional InnoDB replacement for the three configured business tables. Incompatible MySQL snapshots/schemas are refused before replacement. Failures after mutation switch to a resumable rollback, with the site remaining paused until completed.
- Preserve current user identities, passwords, permissions, security credentials, device authorization and existing offline-entry receipts; invalidate old sessions after completion. Never execute recovered application code. Restored masters/settings and all four business modules come from the selected snapshot.
- Pre-restore safety folders remain private and excluded from ordinary archives. Permit at most seven recognized-name safety folders, then hold new restores for review. Never automatically age-delete these originals. Clearing an inactive upload removes only that upload and its staged files; retained safety copies survive.
- This is website data restore, separate from preparing offline folders or installing software on new hosting. See WEBSITE_DATA_RESTORE.txt for the owner procedure. Implementation tests use disposable data only; deployment must never trigger a real business restore.

## Earlier policies and technical history

# Automatic backup storage rules

Owner-approved on 20 October 2026: Hostinger keeps seven daily business-data recovery
points. At approximately 21:00 Asia/Karachi (16:00 UTC), the independent scheduler
creates one full data snapshot containing current private business records, settings
and uploaded documents. Scheduler delays are possible.
Application activity and status reads never create hourly snapshots.

Each daily recovery point stands alone; it does not depend on older backup files.
After a new snapshot passes all private-data size/SHA256/CRC
checks, keep the latest snapshot on each of seven distinct Pakistan dates. Day
eight retires the oldest point. Repeated manual/pre-restore snapshots on the same
date share this bounded pool; keep any point newer than the verified survivor.
Missing/failed days do not prematurely erase the last successful older dates.

Compress text and business data before storage. Never include the backup
folder, runtime sessions, locks, caches or temporary working files recursively.
The previous valid backup survives any creation or verification failure. Unknown
filenames, unreadable manifests and symlinks remain held for review. Recognised
abandoned snapshot temporary files expire after 24 hours under the exclusive job
lock, following complete verification. Never delete live business records or
original uploaded documents as part of backup rotation.

PC daily/weekly/monthly retention is independent and remains in place. Updating
hosting does not update the installed Windows task or agent. Software code remains in GitHub and in complete PC recovery archives. Hosted
data-only snapshots do not contain the application source.

The maintenance workflow runs only on main using protected SSH credentials. It
checks exact installed library hashes before maintenance. Pull requests run
isolated fixtures only. This policy supersedes the previous hourly/15-day policy.

Encrypted owner/office downloads (20 October 2026): schema 3 compresses text and
application source before encryption, and stores uploaded document bytes once
while retaining both logical recovery paths. Older schema 1/2 encrypted copies
remain readable by the new reader. Each build removes only its own temporary
archive on failure/shutdown; unknown or other requests' temporary files are not
pruned. Office download streaming disables output buffering and HTTP content
transformations; transfer length and SHA256 must still match before acceptance.
The independent Windows agent needs an explicit update and the closing-time
installer described in BACKUP_RECOVERY.md; hosted deployment alone does not
change its schedule, local credentials or existing retention counts.

Owner-authorised abandoned office download cleanup (20 October 2026): the
main-only office-temp-cleanup workflow may remove same-user, single-link,
regular `tt-backup-6alnum` temporary archives older than 24 hours, only when
their readable manifest identifies a Transtrade schema 1/2/3 full recovery.
A new persistent server snapshot must first pass every payload check; its
identity and SHA256 are bound to cleanup under the exclusive backup job lock.
Candidate identity, manifest and age are rechecked immediately before unlink;
locked files and files observed open by other processes stay. Recent, unknown,
unreadable, foreign-owned and symlink files remain held. No restore or live
record/document deletion is permitted. This is separate from ordinary retention.

For the one-time old-code backlog only, completed schema 1/2 archives whose
manifest, mtime and ctime all predate 20 October 2026 10:17:16 UTC (the repair
validation start) may also be removed after a 30-minute grace period. This
exception additionally requires readable descriptor directories for every
observable same-user process and no open reference to the candidate. Newer
schema 3 copies and incomplete/unknown files receive no exception. The same
persistent newer survivor, lock and identity checks apply. The cutoff is fixed
and cannot admit future archives.

Office HTTP transfers use protocol 2: authenticated preparation responds before
archive generation; the observed LiteSpeed finish-request facility lets the
worker continue while the agent polls short status requests. The server
fully verifies the encrypted archive before exposing its size/SHA256. Authenticated
4 MiB range requests check the bound archive identity; the PC checks each piece
and the entire ZIP before replacing a completed local copy. A verified local
acknowledgement removes the transfer archive. One private transfer cache under
backups/.office-transfer is excluded from business recovery inventories; an
unsuccessful new generation cannot displace its previous archive. Its staging
path and state are fixed and bounded. This cache is separate from retained
server recovery points. The office credential and recovery password never
appear in public status responses or logs.

Office verification reliability (26 October 2026): the background worker stages
one encrypted candidate and releases its lock. Authenticated status requests
verify bounded batches, recording a cursor only after complete AES authentication,
ZIP CRC, size and SHA256 checks for each logical file. Candidate identity is bound
across batches. Only after every logical entry verifies may an atomic rename
replace the previous verified transfer. Verification hashes plaintext in memory
without writing temporary plaintext copies. Generated workbook/CSV payloads use
1 MiB encryption chunks, just like source files. Fatal errors and a stopped build
worker produce a failed status instead of indefinite "verifying" messages. This
keeps protocol 2 and the installed Windows EXE compatible; retained backups,
original records/documents, device authorization and passwords do not change.

TTI-SERVER Windows tier replacement (owner request, 20 October 2026): after a
weekly copy is verified against the full server SHA256, remove identifiable
daily copies with strictly earlier manifest creation times. After a monthly
copy verifies, apply the same rule to older weekly and daily copies. Preserve
the current recovery point, newer daily copies, and existing monthly retention.
Use a bounded two-entry receipt catalog for the latest weekly/monthly proof;
recheck the survivor identity and full SHA256 before each deletion. Unreadable,
unknown, linked or changed files remain held. Existing copies may seed a proof
only when their full SHA256 matches the saved successful-download state. The
--retention-only command installs/applies this policy without downloading or
changing task credentials, triggers or backup destinations.

One-time owner approval, 20 October 2026 22:45:20 Pakistan time: remove the
six measured generated temporary archives totalling 6,856,173,595 bytes after
checking their recognised complete-recovery manifests, exclusive locks and
same-user process descriptor visibility. This exact cohort may use a 30-minute
minimum grace instead of 24 hours only when manifest, mtime and ctime all predate
17:45:20 UTC that day. A changed file count/byte total aborts before any removal.
A new complete persistent data snapshot must verify first; each candidate is
rechecked immediately before deletion. Future, changed, unknown, incomplete,
linked, foreign-owned, open or locked files stay. This approval does not remove
any retained recovery point or original live record/document.

The same approval covers the exact four-file remainder of 3,841,208,033 bytes
following removal of two complete archives. An interrupted export without its
final manifest is identifiable only when its first eight local ZIP entries are
sequential Encrypted/000001.bin through Encrypted/000008.bin with expected
version, flags and storage method, bounded payload sizes and valid per-entry
CRC. A prefix SHA256 is bound to the candidate and rechecked before unlink.
The original fixed approval cutoff, minimum age, process visibility, exclusive
lock, file identity and fully verified newer persistent survivor still apply.
Unknown headers or damaged prefix payloads remain held; no live data is restored.


### Private audit restore verification

The file-only audit workflow copies current originals into a private temporary folder on the same host, excludes backup/session/historical-recovery folders, verifies source consistency and restores only the copy's three canonical stores through the actual restore helper. It must verify all original-file hashes, unchanged source business files and cleanup. This is file-recovery evidence and explicitly records `browserAcceptance: false`; it cannot be described as full browser migration, a production restore, a MySQL conversion or office-PC backup acceptance.

The separate browser rehearsal must first prove its gated loopback listener is reachable before copying any application or private data. A failed transport check stays a failed prerequisite. Never reduce hosting protection or claim a browser pass from the file-only result. These implementation-triggered temporary copies are removed on exit; they do not alter retained business backups or their retention policy.

Office shipment delivery admission (security hardening, 21 October 2026): retain
all existing archive jobs and source documents. New jobs are limited to 80 files,
256 MiB total, 1 GiB retained storage, 1,000 job directories and 100,000 filesystem
entries. Count all statuses and orphan files under the exclusive admission lock;
reserve bounded metadata growth for delivery receipts. Reaching capacity holds
new jobs for Super Admin review; it never triggers age deletion. The PC daily,
weekly and monthly backup retention is independent of this delivery queue.

Only verified PDF/JPEG/PNG/WebP, application-generated macro-free Word packages,
and flat stored BAGS document ZIPs may be delivered. Hold unsafe older packages
without deleting their bytes. Poll in bounded batches and validate each outbound
file. Original archived files remain available for investigation; requeue a
corrected package from its original saved shipment when needed. Existing backup
protocol 2, transfer credentials and Windows task settings are unchanged.
