# Owner-authorised deployment backup retirement and login recovery

At 21:37 PKT Salman asked to clear all 499 historical software deployment
backups, since source versions are retained in GitHub. This supersedes the
earlier pending oldest-20 proposal: no further retention confirmation is needed.
Private business recovery snapshots, financial/stock stores and original shipment
documents are outside this authorisation and outside the cleanup paths.

Protected host probe39284261067/job118165705378 proved account quota exhaustion:
DISK_QUOTA_EXCEEDED, zero bytes written; it stopped before installing PR371.
Read-only inventory39286325965/job118173795088 verified499 archives totalling
6,288,087,456bytes, exact oldest20 at147,795,550bytes and three complete recent
code/config baselines. Exact bootstrap receipts are embedded in the new CLI tool.

The recovery removes the verified bootstrap set first, preserving the verified
recent baselines, then creates a complete live code/config archive, verifies its
gzip/tar integrity and compares its contents with the live files. It delivers the
already-tested two-file session repair with all447 application hashes checked,
then checks credential submission with the existing read-only QA identity.
Only after that succeeds does it consolidate one verified current live archive
and retire the remaining verified historical code copies. Unknown/symlink or
runtime-bearing archives are held. No application migration or business writer
is called. A failed fresh archive cannot authorise removal of the remaining479.

Disposable499-archive lifecycle tests cover exact bootstrap receipts, failure
before the first deletion, failure after bootstrap preserving latest baselines,
complete live snapshot verification, all499 retirements, retry safety, future
one-copy rotation, invalid-new-backup refusal and unchanged private/runtime data.
Hostinger's shell lacks /dev/fd; scripts avoid process substitution/temp sorting.

## Confirmed production result

Protected recovery run 39287991668/job118180977558 completed successfully on
19 October 2026. It removed all499 historical software deployment archives,
6,288,087,456bytes in total: bootstrap20 at147,795,550bytes and remaining479
at6,140,291,906bytes, with zero held historical archives. A7,969,746byte flushed
write probe passed, all447 application hashes matched release3037ae2, and the
existing read-only QA identity submitted real credentials and opened Accounts.
No business data, private recovery snapshot or financial/stock test write was
part of this recovery. Normal authentication session/audit writes occurred.

The retained complete current live code/config archive is9,450,858bytes:
`verified-live-3037ae272d03e9d050103c8226b3ef7d043176b5.tgz`, SHA256
`b40fb0c32b358414c808d882b2f0ad3d288cb88979748e0da623786df497adaf`.
Its gzip/tar integrity and source comparison passed before consolidation.

PR379 merged as18634f52b38518cc50891b502f1851451f0b9e2b, adding automatic
one-copy rotation after normal production health succeeds. It verifies the new
code/config rollback archive before retiring older valid managed copies and
holds unknown, corrupt, runtime-bearing or symlink entries. Business recovery
snapshot retention is unchanged. All feature Accounts, inventory-runtime,
Milling/Exports and499-archive retention checks passed for the exact feature
merge. Main/live deployment acceptance is recorded separately below.

## Read-only remaining usage

Protected storage run39289250578/job118185327901 measured at16:52:48UTC.
All reported traversals were complete. Values are allocatedKiB from `du -skx`;
parent/child rows overlap and must not be added together.

| Category | Allocated KiB |
| --- | ---: |
| Account home total |223465000|
| All websites |41771056|
| Application public files |30236|
| Application private files |41302088|
| Private business recovery snapshots (inside private files) |39838076|
| Application sessions (inside private files) |28968|
| Software deployment area |9324|
| Retained software archives (inside deployment area) |9312|

The account traversal counted11150 regular files,694 directories and26symlinks.
The home traversal is not the provider plan/quota limit or physical server free
space. The account total is unexpectedly much larger than the website subtree;
the additional181693944KiB outside it needs separate classification before
attributing it to a cause or deleting anything. Private business recovery
snapshots use approximately40.79GB decimal, but are not software rollback
archives and were not included in the owner's499-copy deletion request.
No useful account quota limit was returned by the optional quota command.

Whole-software audit and migration sign-off remain open. This incident's
successful cleanup and login acceptance do not clear unrelated audit gates.
