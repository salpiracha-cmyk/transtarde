# Business backup optimisation

Owner instruction at22:03:54PKT on19October2026: "please do what u feel is best",
following the verified40.8GB inventory. Authorises improving the backup process
and retiring unnecessary business backup copies while preserving recoverability.
This does not authorise deletion or alteration of live business records/documents.

Inventory run39290673964/job118190251933:86files40,793,344,799bytes.47automatic
snapshots39,636,826,141bytes;2unfinished temp files1,074,308,676bytes. A recent
1.464GB full snapshot contains988MB historical/recovery-named files,415MB
images/documents,13MB operations and supporting state. All76ZIP directories and
manifests are readable, but those are not full payload integrity checks. No two
manifest inventories are identical; overlapping contents do not make whole
snapshots interchangeable.

The change streams DEFLATE for JSON/text/CSV/log/SQL recovery entries, retaining
bounded memory. Documents/images and other binary entries remain stored. Both
legacy stored and compressed entries stream through size, CRC and SHA256
verification during server recovery. No source files are omitted from snapshots:
old recovery copies are preserved alongside all current documents and records.

Automatic retention keeps four latest recovery points plus daily points over the
last seven days, up to four older weekly points through35days, and up to three
monthly points through100days. Manual/pre-restore limits remain12each. Only
regular files with managed generated names and a readable Transtrade server
manifest qualify for rotation; unknown files, symlinks and unreadable archives
are held. Existing other recovery copies in the private tree remain untouched.

The protected production maintenance installs only backup_lib.php and the completed-migration no-op fix, guarded by
exact old/new hashes, preserving the previous exact library. It uses the same
backup/auth/operations locks, creates a full manual compressed snapshot and
checks every payload against the manifest before committing and rotating older
automatic copies. It verifies that new snapshot again through the restore-reader
path. No production restore is executed. Under the exclusive backup-job lock it
may remove only generated .snapshot-16hex.tmp files whose mtime and ctime are both
more than24hours old. Other temporary files are held. The same stale-temp rule runs under the exclusive
job lock during future snapshot creation, preventing abandoned partial copies
from accumulating after process termination.

The master-permission deployment migration previously copied the full auth store
before checking that schema2 was already applied. The new early no-op path avoids
both another full migration copy and an unnecessary store rewrite, preserving
existing effective permissions. A real disposable-store CLI repeat test verifies
zero backup creation and byte-identical security data across two runs.

Validation: existing complete/encrypted/server backup restore tests and new
compressed/legacy byte-exact restore, corruption refusal and retention-bucket
checks. Protected main execution results will be recorded on the PR after the
live run. Whole-software audit acceptance remains separate.
