# Performance batch awaiting combined deployment

This branch is for review and testing. Do not deploy or merge it until the owner approves the combined release. It includes main's 50-posting ledger pagination, dashboard duplicate-read reductions and Pohanch output correction.

## Changes

- Supplier payment forms pass their category to the server. Pakistan payment reads skip unrelated bill capacities and unused settlement/advance history; TG payment reads skip unrelated customer invoices/contracts and bank transaction history. Relevant opening balances, bank choices, VAT settings and amendment validation remain available. The bag-bill entry already uses its dedicated bag endpoint.

- Accounts home summaries/recent postings defer reads while an inner area or form is open. They refresh when home is reopened. Report loads require an active report screen, and selected reports request only their needed detailed ledger/expense/party collections; financial statement totals and legacy API defaults remain compatible. Directors report links already load on navigation; displayed home alerts remain available.
- Compress software text (PHP, JavaScript, HTML, CSS and related text formats) before archive encryption, using the existing restore-compatible compressed chunk format. This reduces software archive bytes without dropping recovery files. It is not a changed-data-only backup protocol; the daily Data/Software split and existing retention remain in force.

- Share identical simultaneous GETs for selected report, ledger, master and dashboard endpoints. Each caller receives its own response body. No completed response cache; writes invalidate in-flight reuse. Company/query and request options remain part of the key.
- Background company/customer refreshes are limited to once per minute while visible. JV notices use a one-minute interval with error backoff; explicit checks remain immediate. Mill version checks run every 30 seconds while an operational screen is active. Session inactivity enforcement is unchanged.
- Shared operational refresh has one in-flight request and authenticated conditional reads. Unchanged sources return 304 before large JSON projections; permission changes invalidate the validator. Full reads and explicit refresh remain available. Existing tables are probed without CREATE TABLE on every request.
- Export receipt allocations and ARP closed-contract checks use one-pass lookup maps instead of rescanning the same collections. Existing rounding and totals are retained. JSON accounting history is still needed for correct opening balances; no artificial date cutoff or unrelated index migration is introduced.
- PC full/data/software and owner full backups prepare source snapshots once, then encrypt in resumable 1 MiB chunks with a 64 MiB / approximately two-second work budget per status call. Final verification remains mandatory before promotion. Owner full verification also advances in batches. Separate backup types share a nonblocking work lock. Failed builds retain previous verified archives.
- The Office Agent understands the server's shorter poll interval and permits continued progress up to a two-hour ceiling, while timing out stalled jobs. Deployment does not upgrade an installed PC: update the Windows agent from the release installer before relying on the new polling behavior for large archives.
- Source snapshot/report preparation and final archive hashing still require whole-source work. The owner Business Data native encrypted ZIP builder remains a background worker, serialized with the other archive work; it is not converted to the full-recovery archive format.

## Pohanch output included

Retain one combined Damage + Chalky KAT field per copy, remove the duplicate separate field, and show Filling Charges (Rs. 500 through 40,000 kg; Rs. 1,000 above). Existing broken percentages remain unchanged. The earlier issued-Pohanch money revision is already separate from this deployment and must not be rerun automatically.

## Hosting release blocker

The last main deployment passed both QA workflows but failed before uploading any source: Hostinger returned `/sbin/nologin: No such file or directory` when starting the authenticated SSH shell. The branch includes a read-only connection preflight. It cannot provision a Hostinger account login shell or change hosting credentials. Resolve that hosting-side shell access before the combined release; do not bypass host-key validation or deploy unverified files through another route.

## Acceptance

Run the Accounts and Milling/Exports CI suites, including actual MySQL and headless browser tests. New focused checks cover simultaneous-read sharing, mutation freshness, source/permission validator invalidation, resumed archive decryption/hash integrity, source-change refusal and cross-scope work exclusion. After approved deployment, verify the installed revision, page 50 behavior, complete exports, Pohanch print, and a fresh PC backup. Compare hosting resource use over the subsequent operating period before claiming a measured CPU improvement.
