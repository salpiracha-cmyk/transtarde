# Transtrade recovery runbook

Access: Super Admin only. Keep production data and uploaded documents on the server; do not copy QA fixtures into production.

## Routine checks

1. Open **Backup & Data Export** in the Super Admin Console. Confirm the last automatic backup is recent, the status is healthy, and server snapshots are listed. A failed snapshot records a timestamp and failure count; investigate server logs and available disk space before assuming protection is current.
2. Create a manual snapshot before planned high impact maintenance. Automatic snapshots run when the backup service is called and an hour has elapsed since its last successful automatic snapshot. Retention is hourly for 48 hours, daily for 30 days, monthly for 12 months, plus the latest 12 manual and 12 pre-restore snapshots.
3. Download a **Complete Transtrade Backup** with a unique password of at least 12 characters, keep the ZIP and password in separate protected places, and verify the downloaded ZIP through the Console. The download is AES-256-GCM encrypted and includes raw private data, uploaded documents, readable business exports, and an application code copy. A Business Data download is readable but is not a complete system recovery copy.

## Restore a server snapshot

1. Confirm the intended snapshot date and take note of the current state. In **Restore a Server Snapshot**, select the snapshot and click **Verify Snapshot**. Verification checks the manifest, every listed file, CRC, and SHA-256 before enabling restore.
2. Type `RESTORE` and confirm. The server creates a verified **pre-restore** snapshot first, stages every recovery file, validates JSON files, and then replaces the stored files. Do not close the page or allow normal entry during the brief replacement phase.
3. Reload and sign in. Check the Super Admin Console, Accounts, Milling, Exports, recent records, uploaded documents, and the backup status. Record the restored snapshot name and the new safety snapshot name. If recovery is incomplete, keep the safety snapshot and investigate before trying another restore.

## Restore an off-server complete ZIP

1. Start the application from the correct GitHub application revision and secure server configuration. In the Super Admin Console choose the complete ZIP, enter its separately retained password, and click **Verify Backup**. This checks the password and every encrypted entry before enabling recovery.
2. Type `RESTORE` and confirm. A pre-restore snapshot is taken before stored data changes. Uploaded documents are processed in bounded chunks; application code inside the ZIP is for offline recovery, and the in-app restore does not overwrite the deployed application code.
3. Reload and repeat the checks above. If the primary server has been lost, restore the application code and private configuration on a new secure server using the established GitHub→Hostinger deployment path, then use the ZIP recovery workflow. Keep the original ZIP untouched until the new installation and data are verified.

## Office computer agent setup

Use Python 3.11+ on the chosen office/server PC. The agent is `scripts/office_backup_agent.py` in the deployed GitHub repository. In the Super Admin **Backup & Data Export** screen, generate a backup-only credential and copy it once. Generating a new credential revokes the old one; **Revoke Credential** stops all office pulls. This credential cannot sign in, view business data, or restore. Choose a separate strong recovery ZIP password and store it separately from the credential. The server keeps only the credential hash and does not retain the ZIP password.

1. Copy the agent to the PC. Run `python office_backup_agent.py --init` as the operating-system account that will own the backup task. Enter the credential, recovery ZIP password twice, and destination folder. The agent restricts the configuration file to that account. Do not put the credential or password on a command line, in Task Scheduler arguments, or in a shared folder.
2. Run `python office_backup_agent.py --force` once. Check the printed verified ZIP filename, confirm a weekly and monthly ZIP exist, and perform a test verify in the Super Admin Console with the separately retained ZIP password. No live restore is required for this check.
3. In Windows Task Scheduler, create a task under the same account with triggers **At startup**, **At log on**, and **Weekly**. Action: launch the full path to `python.exe` with the full path to `office_backup_agent.py` as argument. Configure the task to retry after failure (for example every hour, at least three times) and allow enough time for a large encrypted backup. On Linux, run the same command from a user systemd timer at boot, login, and weekly, with an hourly retry timer after a failed run.
4. On each invocation, a successful pull less than seven days old is skipped. An overdue pull makes three short retry attempts and exits nonzero if still unsuccessful so the scheduler can retry. The agent downloads over verified HTTPS without redirects, checks free disk space, verifies transfer length and SHA-256, checks the encrypted recovery manifest, then commits the ZIP atomically. It retains the latest **four weekly** ZIPs and **three monthly** ZIPs. Check its task history and last successful file weekly.

The office computer and destination folder are selected when installing the agent. If the PC is replaced, revoke the old credential, issue a new one, set up the replacement, and test a full pull. Keep at least one verified ZIP and its password off the production server.

## Test procedure

The disposable test `php tests/security/server_backup_hardening_test.php` creates and verifies a snapshot with a multi-megabyte document, changes it, restores the snapshot, checks a pre-restore safety copy, creates and restores a chunk-encrypted complete ZIP, checks retention and failure reporting, and verifies Master headings plus formula-safe exports. `php tests/security/office_backup_auth_test.php` checks credential rotation and revocation; `python3 tests/security/office_backup_agent_test.py` checks transfer integrity and retention. Run these through the repository QA workflow before merging. Never run a restore test against the live data store.
