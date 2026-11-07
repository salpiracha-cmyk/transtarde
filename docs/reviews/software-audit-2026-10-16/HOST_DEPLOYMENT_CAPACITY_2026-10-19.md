# Hosting capacity and deployment recovery — 19 October 2026

Status: generated-cache recovery, a complete archive transfer and source-matched functional checks passed. Durable account capacity, code-backup retention and full deployment peak capacity remain open.

The test-only PR364 revision `588745d` passed both complete feature and exact-main suites. Deployment `39258054778` failed twice during SCP, before replacing the application (jobs `118078240759` / `118079228738`). Application `39e505c` remained active. The Git revisions have identical runtime application source; their only difference is the read-only test. The failed deployment is retained as a failure.

## Proven diagnosis

Read-only PR365 diagnostic `39258849858` / job `118080938005` found shared disk space and inodes available, with incoming storage writable. It measured 499 historical code backups at 6,288,087,456 bytes, plus 53,378,761 bytes in generated incoming/staging caches. Shared-disk availability does not establish this hosting account's quota.

The PR366 host audit `39261052673` / job `118088551574` tested a unique disposable 4 KB file in generated deployment storage. It received **DISK_QUOTA_EXCEEDED**, wrote zero bytes and removed its own file. This confirms an account quota error. It does not identify the provider's exact block/inode limit or attribute every occupied byte to one family.

## Recovery and verification

Only four regular `release-<40hex>.tgz` incoming archives and three complete `<40hex>` staging directories were removed: 53,378,761 bytes. These are rebuildable deployment artifacts. Unknown, incomplete, runtime-bearing and symlink entries are retained. Canonical production paths and the deployment concurrency group protect this boundary. Live source, private stores, uploads, original documents and all 499 historical code backups are outside the cleanup operation.

Disposable local and hosted tests cover retained live/private/backup/unknown/runtime/incomplete/symlink sentinels, removed probes, an unchanged retry, failed-open handling and refusal of unsafe roots. Focused feature `39260839346` / job `118087728863` and main `39261052673` / job `118088472582` passed.

After cleanup, the 4 KB probe wrote, flushed/synced and removed 4,096 bytes successfully. A complete release archive uploaded via SCP and its server SHA-256 matched; that audit upload was removed. No code was installed and no rollback archive was pruned.

The workflow read the host's recorded deployed revision `39e505c`, then verified that runtime application source in Git was unchanged between that revision and the test checkout. Tests, docs and .github changes are excluded; an application-source difference fails this gate. All 23 protected application flows then passed in 2.2 minutes, including original WHT journals/output and the current salary draft-payment controls. Fifty-three ledgers across three companies reconcile, with zero revision skips and zero financial/stock/assessment/source-link test writes. This does not label a later test-only Git revision as deployed.

## Remaining decision

The deployment workflow creates `before-<sha>-<timestamp>.tgz` rollback copies without a rotation budget. All 499 copies, occupying 6.29 GB, were preserved. Private business recovery snapshots are separate and must not be treated as deployment caches.

A durable hosting-capacity or explicit code-backup-retention decision is still needed. Before removing a historical rollback copy, prepare the exact candidate inventory, retain required verified rollback/configuration baselines and obtain the owner's retention decision. No historical copy was deleted or unapproved retention policy applied here. A successful write and archive transfer do not certify peak quota for a complete stage plus rollback backup, or unlimited future growth.

The capacity action remains partial in CURRENT_COVERAGE.csv. This recovery does not replace the independent migration/restore and formal security gates.
