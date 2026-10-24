# Remaining software review — 25 October 2026

This continues the existing review; it does not replace its original observations
or turn missing business facts into passed production transactions. Backup/restore
is excluded by the owner. No production test payment, stock, approval, master,
assessment or uploaded original is created by this continuation.

Release and final validation are tracked in [PR577](https://github.com/salpiracha-cmyk/transtarde/pull/577).

## Reproduced software defects and repairs

| ID | Trigger and original result | Repair and evidence |
| --- | --- | --- |
| S01 | Assets treated any Create/Edit/Approve grant as authority for other write actions. A Create-only disposable user deleted a country. | Match the exact icon and company action: Create for new records/payments, Edit for amendments/reopening, Delete for location removal. UI uses the returned capabilities. Permission denials preserve source bytes. |
| S02 | Asset acquisition/instalment routes did not implement the shared green pre-post review. | The original asset engine now previews before persistence; the shared UI reviews acquisitions/payments and returns their real journal/Print result. Generic financial editing is disabled for source-owned asset journals. Native Chromium verifies review/cancel with byte-exact books, retained split amounts, one confirmed posting, final-payment privacy and mobile fit. |
| S03 | Closing Assets while a POST response was pending allowed its acknowledgement to reopen the private window. | Bind write responses to the company/view generation. The negative-control regression fails on the original UI and passes after the change. Closing leaves no stale confirmation/data. |
| S04 | Missing or empty Assets/Investments books appeared empty and could be recreated through a later write. | Open only existing books; reject empty, malformed, non-object or invalid-journal storage. Actual endpoints test reads and writes without recreating/truncating the source. |
| S05 | Accounting previews consumed real universal Post IDs and changed offline resource/retry state before confirmation. | Preview-capable controllers use request-local tentative IDs and do not admit a resource mutation. Confirm and Post still passes the normal authentication, CSRF, workflow, revision and idempotency checks. Actual-auth tests compare books, the real sequence and retry-store bytes, then confirm/retry one real disposable posting. Sequence allocation does not start a secondary Accounts reader under the financial lock. |
| S06 | Two concurrent fallback document uploads read the same index then overwrote one another. Damaged metadata was treated as empty; encoding followed truncation. | Serialize read/change/commit with one stable sidecar lock and atomically replace an encoded/flushed temporary index. Fail on invalid source instead of replacing it. Preserve document identities; commit scoped deletion metadata before removing files, and allow cleanup retries. Two actual PHP workers reproduce the old lost-reference race. Invalid/overflow/encoding cases preserve original metadata/files. |

## Review matrix

The old `CURRENT_COVERAGE.csv` combines software acceptance, real production
transactions, physical observations and issued-original decisions. The following
matrix keeps those meanings separate. A disposable positive case is evidence of
the software flow; it is not a claim that an actual business payment was made.

| Area | Software review and evidence used | External/source qualification |
| --- | --- | --- |
| Console / Directors approvals | Shared original endpoints, actual approver, stale decisions, duplicate/concurrent decisions, permission boundaries, queue/UI parity | Actual pending decisions remain with their business approvers. |
| Assets / investments / finance | Source review, actual controller and native browser positive/amendment/reversal/schedule/privacy cases; S01–S04 | Do not invent holdings, facilities, legal ownership or original instalment proof. |
| Supplier plans and partial payments | Actual HTTP source allocations, partial/final settlement, retries, dates, WHT/discount and source-linked vouchers | Independent original bank/supplier completeness remains a bookkeeping question. |
| Accounting previews / persistence | S04–S05 plus actual-auth integrity, deterministic concurrent settlement/receipt and source-byte tests | No power-loss or full migration/recovery certification is inferred. |
| Reports / ledgers / Zakat | Period/company/party filters, source drill-down, screen/CSV/print and the paid-only statement release PR576 | Approved annual control and source figures are preserved. |
| Milling / Rice / Corn | Original receipt–GRNI–supplier linkage, immutable correction, billed-source guards, explicit ownership, recovery/inventory and local-sale tests | Physical opening quantities and absent actual production/cost evidence remain required. |
| Loading instructions / changed masters | Explicit server acknowledgement, current-source versions, completed references, source-owned amendments and preserved master snapshots | Issued original facts are not silently rewritten. |
| Exports / contracts / FI / uploads | Native contract/document workflows, real multipart upload/download permission cases, current customer references, failure/cancel and source preservation; S06 | Missing originals and approved buyer/HS descriptions need the actual business source. |
| Document outputs | Existing exporter/payment/packing/multi-container/multi-GD matrix and real rendered-output regressions, protected original handling | Generated-file parity does not certify physical paper or an unseen office destination. |
| Office delivery | Authenticated document queue, policy, generated PDF and acknowledgement endpoint tests | Physical office-agent receipt/printing requires that device's actual evidence. |
| Authentication / session / permissions | Source review and actual-auth tests for company/icon/action, login/session/CSRF, upload types, read scope, staff/Director/owner boundaries and retry limits | This is not an independent penetration-test certificate. The formal scan capability/preflight is not exposed in this session. |
| Startup / request failures | Existing startup delivery, latency, cancelled-request and error-recovery checks; read-only current UI acceptance | A successful retry does not establish the root cause of every historical network interruption. |

## Validation checkpoint

- Sixty focused existing checks passed with no failure, including actual-auth
  boundaries, approvals, file/HTTP source flows and native payment-plan UI.
- New negative controls reproduced S01, S03, S05 and S06 before their repairs.
- Native Chromium asset registration, green preview/cancel, split/final payment,
  private-property restriction and mobile geometry passed on disposable books.
- Remaining export/report regression run and exact-release CI/deployment/live
  verification are recorded at closeout; this checkpoint alone is not release
  or whole-audit completion.
- The export/report run passed 68 checks; its one local database guard correctly
  refused to run without a disposable database. The five concurrently updated
  cash-flow checks also pass. Real multipart upload/download and native asset
  preview/cancel/confirmation pass locally.
- First hosted release candidate: core Accounts and Milling/Exports passed.
  The native job passed its database checks, then failed because the asset
  browser child process lacked the Node package path already installed in an
  earlier step. The asset step now receives that same isolated runtime path;
  the test and its assertions remain enabled. The failed run is retained.

## Owner/source decisions retained

- Physical rice/bag openings and missing original costs/scans.
- Approved wording/classification for the preserved historical commercial
  documents; corrected generated reprints do not replace issued uploads.
- Actual office delivery/printing evidence when the office device is available.
- The owner has confirmed the salary-balance settlement raised previously; its
  original payment link must be identified rather than creating another payment.

The Zakat business rule is resolved: unpaid/opening control stays outside the
financial Balance Sheet; paid Zakat is donations expense in the payment year.
No further annual monetary counterpart is requested for this presentation.
