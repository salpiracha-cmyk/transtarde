# Transtrade recovery runbook

Access: Super Admin only. Keep production data and uploaded documents on the server; do not copy QA fixtures into production.

## Routine checks

1. Open **Backup & Data Export** in the Super Admin Console. Confirm the last automatic backup is recent, the status is healthy, and server snapshots are listed. A failed snapshot records a timestamp and failure count; investigate server logs and available disk space before assuming protection is current.
2. Hosted data snapshots run once daily around 21:00 Pakistan time via the independent scheduler. Keep seven latest daily points, replacing the oldest only after verification. Each contains business records, settings and uploaded documents; see [BACKUP_POLICY.md](BACKUP_POLICY.md). Page activity does not start hourly snapshots. PC daily/weekly/monthly retention remains independent.
3. Download a **Complete Transtrade Backup** with a unique password of at least 12 characters, keep the ZIP and password in separate protected places, and verify the downloaded ZIP through the Console. The download is AES-256-GCM encrypted and includes raw private data, uploaded documents, readable business exports, and an application code copy. A Business Data download is readable but is not a complete system recovery copy.

## Restore a server snapshot

1. Confirm the intended snapshot date and take note of the current state. In **Restore a Server Snapshot**, select the snapshot and click **Verify Snapshot**. Verification checks the manifest, every listed file, CRC, and SHA-256 before enabling restore.
2. Type `RESTORE` and confirm. The server creates a verified **pre-restore** snapshot first, stages every recovery file, validates JSON files, and then replaces the stored files. Do not close the page or allow normal entry during the brief replacement phase.
3. Reload and sign in. Check the Super Admin Console, Accounts, Milling, Exports, recent records, uploaded documents, and the backup status. Record the restored snapshot name and the new safety snapshot name. If recovery is incomplete, keep the safety snapshot and investigate before trying another restore.

## Restore an off-server complete ZIP

1. Start the application from the correct GitHub application revision and secure server configuration. In the Super Admin Console choose the complete ZIP, enter its separately retained password, and click **Verify Backup**. This checks the password and every encrypted entry before enabling recovery.
2. Type `RESTORE` and confirm. A pre-restore snapshot is taken before stored data changes. Uploaded documents are processed in bounded chunks; application code inside the ZIP is for offline recovery, and the in-app restore does not overwrite the deployed application code.
3. Reload and repeat the checks above. If the primary server has been lost, restore the application code and private configuration on a new secure server using the established GitHub→Hostinger deployment path, then use the ZIP recovery workflow. Keep the original ZIP untouched until the new installation and data are verified.

Complete-archive verification and the filesystem restore helper accept ZIP32
archives up to 4 GiB minus one byte. They process encrypted document chunks
individually; the 512 MiB per-entry/decompression ceiling remains separate.
This also allows the Office background worker to verify larger completed
archives before transferring them to the PC. The browser multipart upload
still has a 512 MiB application limit and may have lower hosting limits; do
not describe a large-archive helper test as a browser upload acceptance test.
Larger off-server recovery needs a technician-controlled private staging path,
the matching password and an isolated-installation rehearsal before live use.

Restoring either backup type preserves the currently configured recovery code and office-backup credential, and independently enrolled Office password policy, including their absence after revocation. Historical hashes in an archive are verified but never activated. On a replacement installation, provision a fresh recovery code in **Recovery Security** and issue a fresh office credential in **Backup & Data Export**, then update the PC agent configuration. Do not manually copy `admin-recovery.hash`, `office-backup-token.sha256` or `office-backup-policy.json` from an old archive into the new installation.

## Office computer agent setup

Use Python 3.11+ on the chosen office/server PC. The agent is `scripts/office_backup_agent.py` in the deployed GitHub repository. In the Super Admin **Backup & Data Export** screen, generate a backup-only credential and copy it once. Generating a new credential revokes the old one; **Revoke Credential** stops all office pulls. This credential cannot sign in or restore. Before owner password protection is activated, the legacy credential authorizes full recovery downloads and shipment delivery, so protect it as a sensitive service credential. Choose a separate strong recovery ZIP password and store it separately from the credential. The server retains only credential and enrolled password verifiers, never the plaintext ZIP password.

1. Copy the agent to the PC. Run `python office_backup_agent.py --init` as the operating-system account that will own the backup task. Enter the credential, recovery ZIP password twice, and destination folder. The agent restricts the configuration file to that account. Do not put the credential or password on a command line, in Task Scheduler arguments, or in a shared folder.
2. Run `python office_backup_agent.py --force` once. Check the printed verified ZIP filename, confirm the Daily, Weekly and Monthly folders contain verified ZIPs, and perform a test verify in the Super Admin Console with the separately retained ZIP password. No live restore is required for this check.
3. In Windows Task Scheduler, create a task under the same account with triggers **At startup**, **At log on**, and **Daily**. Action: launch the full path to `python.exe` with the full path to `office_backup_agent.py` as argument. Configure the task to retry after failure (for example every hour, at least three times) and allow enough time for a large encrypted backup. On Linux, run the same command from a user systemd timer at boot, login, and daily, with an hourly retry timer after a failed run.
4. On each invocation, a successful pull on the same UTC calendar day is skipped only while its recorded Daily file still exists. The agent uses UTC for its Daily/Weekly/Monthly folder buckets; a prior-day pull never suppresses the next scheduled day, even when less than 24 hours apart. An overdue pull makes three short retry attempts and exits nonzero if still unsuccessful so the scheduler can retry. The agent downloads over verified HTTPS without redirects, checks free disk space, verifies transfer length and SHA-256, checks the encrypted recovery manifest, then commits the ZIP atomically. New configurations retain the latest **seven daily**, **four weekly** and **twelve monthly** ZIPs in separate folders; existing configuration limits remain in effect. Weekly and monthly copies are created once per calendar bucket from a verified daily download. Check its task history and last successful file daily. An active hosted credential alone does not prove that this task is installed or running. Updating the hosted software does not update a separately installed PC agent: replace that script and change any old Weekly trigger to Daily, then verify its next scheduled run.

The office computer and destination folder are selected when installing the agent. If the PC is replaced, revoke the old credential, issue a new one, set up the replacement, and test a full pull. Keep at least one verified ZIP and its password off the production server.

## Test procedure

The disposable test `php tests/security/server_backup_hardening_test.php` creates and verifies a snapshot with a multi-megabyte document, changes it, restores the snapshot, checks a pre-restore safety copy, creates and restores a chunk-encrypted complete ZIP, checks retention and failure reporting, and verifies Master headings plus formula-safe exports. `php tests/security/office_backup_auth_test.php` checks credential rotation and revocation; `python3 tests/security/office_backup_agent_test.py` checks transfer integrity and retention. Run these through the repository QA workflow before merging. Never run a restore test against the live data store.

## Office closing-time schedule (20 October 2026)

The updated agent accepts `--scheduled`: one verified download per 18:00 Pakistan-time slot. A successful daytime manual backup does not suppress the closing-time backup. At logon or after a missed trigger, the agent catches up the latest due slot and then skips repeats while its verified Daily file still exists. Windows must use Pakistan Standard Time.

Install the current `scripts/office_backup_agent.py` and `scripts/install_office_backup_schedule.ps1` together in the existing office-agent folder, then run the installer as the existing task owner. It preserves the prior task XML and principal, changes the hourly trigger to daily 18:00 plus logon catch-up, prevents overlapping task instances, and retries failed runs three times at 30-minute intervals. It prefers the original per-user Python installation when available and tests its required libraries first. The latest task output is written to `office-backup-latest.log`. Existing protected credentials and retention counts are preserved.

Weekly retention takes the first successful closing-time download in each Monday-starting week; a failed Monday run catches up on the next successful run. Monthly retention takes the first successful weekday closing-time download of each month, with catch-up. Weekdays mean Monday–Friday; no office holiday calendar is currently supplied. These folders reuse the same checksum-verified Daily download rather than requesting separate full downloads.

Complete encrypted downloads use schema 3: compress text and application source before AES-GCM encryption, and reference each uploaded document's encrypted chunks from both readable and technical recovery paths. Every original document remains present and byte-exact after decryption. The reader continues to accept schema 1/2 uncompressed encrypted backups. Schema-3 backups require the updated recovery reader. Streaming clears PHP output buffers and disables HTTP transformations; size and checksum validation remain mandatory. Failed builds remove only their own temporary archive.

Office Agent protocol 2 (20 October 2026): update the Windows agent together
with the hosted background preparation endpoint. Old synchronous agents get
HTTP 426 rather than generating another oversized timed-out request. The PC
prints preparation/verification status, then downloads checked 4 MiB pieces.
The schedule updater requests the existing Windows account password locally
when the task uses Password logon. A Windows Hello PIN is insufficient. It
checks the credential SID and preserves the existing task principal/logon
type; registration failure stops installation and must never print success.
Do not enter Windows passwords into chat.

## Protect an existing Office PC connection

The updated Office agent takes an operating-system lock per configuration before
opening its shared backup log. A manual run and the scheduled backup cannot
write success state or transfer receipts concurrently; shipment-only delivery
remains independent. Keep the persistent `office-backup-run.lock` file in place:
its presence alone does not mean a run is active, and Windows releases the actual
lock when the process exits.

Before requesting a due backup, the agent checks that it can atomically save the
existing success record. It retries a brief Windows sharing/access conflict for
at most five local replacement attempts without changing permissions. If saving
the success record fails after a verified download, it preserves the ZIP, reports
its filename and exits unsuccessfully without downloading the whole backup again.
Resolve the local lock/access cause before retrying. Updating hosting does not
install this Python change on the PC; replace only the agent program while all
backup runs are stopped, preserving the protected configuration and task.

1. In Super Admin → Backup & Data Export → Office computer backup, enter and repeat the **existing recovery ZIP password already configured on the PC**. Save Existing Password Protection. Do not create a different password, rotate the token, rerun `--init` or change the Windows task. The server stores a slow verifier of the full password and requires it on every protocol-2 backup action; existing backups continue using their original configured password.
2. Replace only `office_backup_agent.py` on the PC with the current repository version. Preserve its protected `office-backup.json`, destinations, retention and scheduled task. Run the existing shipment delivery command once. It now sends independently checked password proof as an ASCII-safe header over verified HTTPS.
3. Refresh Connection Status in Super Admin. A password-verified delivery connection within 15 minutes enables Activate Shipment Delivery Protection. Activate it. Token-only poll, file downloads and delivery acknowledgements are then rejected. A client version flag cannot disable this policy.
4. Verify the next scheduled encrypted backup and a shipment delivery receipt on the actual PC. The hosted status does not prove Windows scheduling or local retention. Until the owner completes these steps, the console explicitly displays the remaining legacy/migration state.

Do not enter passwords in chat, task arguments, logs or source control. Rotating the token after enrollment requires the owner to bind the existing PC recovery password to the replacement credential before Office pulls resume. Password policy remains enforced through restore and cannot be downgraded through an agent request. A compromised protected PC configuration contains both secrets; password proof protects against theft of the service token alone.

## Simplified owner-managed backup password (26 October 2026)

The owner may opt into one backup protection password in Backup & Recovery → Passwords & Security. This is separate from all user/Super Admin logins and the emergency login recovery code. The server stores it encrypted with a private installation key; neither vault nor key enters a backup or is replaced by an old restore. Preserve the owner’s separately saved password: the installation key is not a disaster recovery substitute.

Once configured, automatic PC downloads and manual backup downloads use this password without requiring the PC to supply an independent connection password. The PC must still have its current service connection key. Stop runs, update the Office Agent and use --connect to replace that key locally when needed; this retains destinations and schedules. Revoked or wrong keys remain denied. The legacy password-proof policy remains unchanged until this owner opt-in.

Download Business Data produces a standard AES-encrypted ZIP readable by an AES-capable ZIP tool, including the combined workbook, per-module CSVs and original saved documents. Complete Backup retains the supported Transtrade encrypted recovery format and includes the same logical business folders plus technical data/code. Original documents with unknown module attribution stay under Shared; shipment delivery folders retain their source paths. CSV exports include saved drafts/reversals with status and raw input fields; they are not a new financial-statement certification.

Changing the password affects future ZIPs only. Create and verify fresh daily, weekly and monthly recovery copies before retiring older ones. The central setting does not itself prove Windows scheduling, local retention, server/PC connectivity or a successful recovery rehearsal.

After setting a new backup protection password and reconnecting the current PC key, run the updated agent once with --backup-only --refresh-tiers. This forces a fresh verified download and verified current-week/current-month copies even if those buckets already exist. It preserves the closing-time schedule, reuses the one checked download, and applies existing retention only after verification. Older retained ZIPs still need their original passwords.
