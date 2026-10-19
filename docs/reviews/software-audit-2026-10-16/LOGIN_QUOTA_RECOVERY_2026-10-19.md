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

Production execution and authenticated sign-in acceptance are still pending at
this commit. Whole-software audit/migration sign-off remains open. Configure the
normal deployment workflow to rotate only after the new rollback archive and
production health are verified; do not apply this policy to business snapshots.
