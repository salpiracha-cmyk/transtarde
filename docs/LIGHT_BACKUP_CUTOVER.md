# Lighter office backup cutover

Implementation is pending live activation and resource measurement. A files-only
Hostinger download is not evidence of a complete matching database recovery.

1. Deploy the tested code and build the Windows app. Deployment alone leaves the
   existing protocol active. Retain existing Hostinger and TTI Server backups.
2. In hPanel configure a once-per-minute PHP CLI cron calling the deployed
   `scripts/office_backup_worker.php --run`. Use the hosting account's actual PHP
   CLI binary and absolute application path, confirmed through hPanel/SSH. Prefer
   low CPU/I/O priority if supported. Do not put passwords or tokens in the command.
3. Observe the worker heartbeat in the private backup control file. Confirm the
   website managed recovery password is configured. Stop only the old Windows
   backup schedule after its active backup finishes; preserve the shipment task.
4. Run `php scripts/office_backup_worker.php --enable` through the same account.
   This refuses activation if there is an active/preparing old backup. Do not
   manually delete active job locks to force the change. Old full-backup clients
   then receive an update-required error instead of starting another full archive.
5. Install the newly built Windows EXE using Update existing PC settings (keep
   connection). It keeps credentials, archive destinations, old backups and
   shipment delivery. Its daily trigger is 13:00 UTC / 18:00 Pakistan, with catch-up.
6. Run Backup Now once. Check matching software revision, one verified data ZIP,
   and successful separate-folder recovery of saved records and uploaded files.
   Do not restore over production. The old recovery ZIP reader is unchanged.
7. Measure before/during/after hosting CPU and I/O, request durations and counts.
   Status requests must finish quickly without advancing archive progress. Check
   cron failure/no-overlap behavior and the next scheduled 18:00 PC collection.
8. Only after those checks mark replacement complete. Retain verified recovery
   points; this release authorizes no deletion of historical FULL archives.

If the worker stops, the PC must report failure and preserve existing copies.
Do not switch back to heavy HTTP builds automatically. A failed job must be
investigated, not reported as a successful backup.

Remaining limitation: daily data archives still copy unchanged uploads. This is
smaller than a full software archive but is not incremental file storage. Measure
actual data size; if uploads dominate, a separate incremental object/file backup
needs its own recovery manifest and retention design before further reduction.
