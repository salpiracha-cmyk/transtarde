# Automatic backup storage rules

Owner-approved on19October2026: replace month-old recovery copies when newer
verified data exists; maintain automatic compression and expiry rules.

- Compress JSON, text, CSV, log and SQL entries when each snapshot is created.
  PDF, images, Office files and existing binary archives remain byte-exact;
  their bytes are already typically compressed. Backups include every eligible
  current/private recovery source file, with CRC/size/SHA256 verification.
- Create automatic recovery snapshots at most hourly during committed activity.
  Also run daily maintenance at03:20Pakistan time (22:20UTC), subject to scheduler
  delay. This creates a new automatic snapshot if the last one is over an hour
  old, verifies it and applies the same expiry policy even without app activity.
- Retain four latest automatic points within15days, one daily point over the
  last seven days, and up to two weekly points for days8–15. Keep at most three
  manual and three pre-restore copies within15days. There is no monthly bucket.
- Calculate age from the snapshot manifest creation date; copying/touching an
  old file must not restart its retention clock.
- Retire managed copies older than15days only after a complete surviving backup
  has passed all payload checks. Bind that verification to the file identity and
  SHA256, and stop pruning if it changes. Keep a sole old backup until a newer
  verified survivor is available. Never remove a point newer than the survivor.
- Unknown filenames, unreadable manifests, symlinks and unsupported copies stay
  held for review. Abandoned generated .snapshot-16hex.tmp files expire after
  24hours under the exclusive backup job lock after a complete snapshot verifies.
- Never age-delete live accounting/stock records, original documents, or private
  historical recovery-source files. Owner downloads on another computer are
  outside this server policy. Software code rollback retention stays one verified
  copy and is separate from business recovery snapshots.

The daily workflow operates only on main with protected SSH credentials. Pull
requests get isolated fixture/lint checks only. Scheduled runs validate the
installed backup library hash and do not deploy code or run app migrations.
Policy deployment may atomically replace only backup_lib.php and the owner
backup-page guidance, guarded by exact old/new hashes and previous-code copies.

This supersedes the seven-day/daily plus100-day/monthly policy from PR385.
