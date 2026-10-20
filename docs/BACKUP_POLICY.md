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
