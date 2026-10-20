# Remaining audit acceptance — 20 October 2026, connected Chrome continuation

Latest consolidated evidence: [Full audit continuation and exact release closeout](FINAL_REVIEW_2026-10-20.md). The156-scope business-acceptance register is125passed,24partial,1not tested,3source/document failures,2blocked and1intermittent. These statuses are retained rather than inflated by repeated test runs.

Latest afternoon evidence: [Authorised party correction, deployed protections and partial-payment plan](PARTY_CORRECTION_2026-10-20.md). Scope37 advances from not tested to partial;125passed and31open remain unchanged.

This continues the PR401 checkpoint and incorporates subsequent main changes
through the concurrent PR406 backup release a3b14b962188caa1980d7bf237d6c10903d430d6.
Local Chrome was actually connected,
not the cloud session: Salman / Super Admin was visibly signed in. The user explicitly requested the connected local Chrome session, and that
working signed-in session was used. No credentials were read.

## Scope closed in this pass

The existing action **Sales Tax: Search actual AMT contract / source document
references** is now accepted, rather than counting another repeated smoke suite
as new scope completion. In connected Chrome, invoice TG/AMT/13, GD KPPE-SB-78089,
B/L MAEU276778753, customer AMT ENTERPRISE and lot TG/AMT/13/L01 each independently
returned one canonical row dated2October. An unknown reference returned the
explicit no-match state; a3October lower cutoff excluded the row and an inclusive
2October–2October range included it. These are report reads, not production saves.
Full current issued-output/indexing acceptance remains in its existing separate
Sales Tax/output rows, which are not promoted to passed.

A further read-side defect was reproduced: GD references stored only in the B/L
were omitted by Sales Tax, despite the fallback already used in Exports. PR409
adds that fallback. Explicit or partially entered Customs references continue to
win; old B/L numbers are not resurrected. Existing commercial GD compatibility
is retained. The original implementation fails the new regression. Core and
actual endpoint tests cover multiple B/L GD numbers, reference searches, date/
company boundaries, obsolete-reference exclusion and unchanged Accounts,
operations, document-index and archive bytes. No historical source is rewritten.
Feature658a8a5212ce772e293ca27c1058f20f9ac60457 has exact tree
560e06765654da492a9e814f02d5ad9fb9a003c3, matching the local staged tree.
Feature Accounts39405448834 (both core and native inventory runtime) and
Milling/Exports39405448828 passed. PR409 merged as
41ae01111d287770402387a78bd34a98b5f753f0. Exact-main Accounts39406036621 and Milling/Exports39406036511 passed;
deployment39406518725 succeeded and startup39406611651 passed. The final combined
release and live acceptance are recorded below when complete.

## Current-day stamps on historical Customs reprints — PR410

Connected Chrome showed Generated20-10-2026 below the historical25September
Customs Invoice. The same renderer also stamped Customs Packing and Phytosanitary
invoices. PR410 suppresses that current generation-date stamp on those three
outputs. Saved invoice dates, GD references, quantities, money, signatures and
historical uploads remain unchanged. The shared template retains its existing
default behavior for other callers and ordinary packing PAGE counters remain.

The actual-renderer regression fails the previous source. The expanded42-case
TTI/BRM/TG/payment/packing matrix now generates294outputs, including Phytosanitary,
multi-GD and30-container pagination, and confirms original sources are unchanged.
Local output-handover and Exports acceptance tests pass. The branch explicitly
merges PR406 without changing any backup file. Final feature
368545911c9c7265633a417fa810b742045c4279 has local/connector tree
16ec84c30a4c8498766ffeba913e8cdbdcf62178. Full feature Accounts39406976597 (core and native inventory) and
Milling/Exports39406976628 passed. Concurrent PR411 adds only a pinned Windows
updater and its QA workflow; the merge preserves them. Final release evidence
follows.

## Additional checks completed without changing live records

- Current AMT source exceptions were rechecked: Customs still shows1006.3090
  beside100%broken; the relationship form names AMT ENTERPRISE but the saved
  approved wording still identifies the Saudi buyer. The final commercial
  invoice shows AMT in Banjul, Gambia. No original wording/HS was rewritten.
- Actual AMT search has8uploaded-file links and18dated historical archive links,
  all26URLs distinct. The two identically named Fumigation originals have distinct
  stored identities. No original, historical archive or financial entry was deleted.
- All four FI types were rechecked in local Chrome: Advance/CAD hide L/C inputs,
  L/C shows its required reference, TG Unallocated hides customer selection.
  Cancel returns to the active shipment list. No FI Save, upload or allocation.
- September Monthly Bag Supplier Files still explicitly reports no attached scans.
- Current actual-auth salary fixture passes the330000plan, company Home expense,
  remuneration deductions/unpaid receivable, credit-card debit, direct BMA funding,
  property instalment, one funding credit, stale-asset rollback, retries and full
  reversal. Bank/finance/subaccount, supplier plan/split/correction, Director
  approval endpoint, native outside-Corn/shared loading/combined bill, and Sales
  Tax endpoint/selection tests also pass locally on the reviewed source. These
  remain disposable cases, not newly created production business transactions.
- Hostinger Home was signed in but timed out; its PHP/HTML website list remained
  a loading screen after direct navigation of its observed link and one reload.
  No account quota was inferred from a spinner. No hosting setting was changed.
- The separate office-backup investigation is already active in another chat;
  PR407 adds metadata-only diagnostics. PR406 subsequently adds compression/
  streaming hardening and a daily18:00Pakistan-time installer with missed-run
  catch-up, Monday weekly and first-weekday monthly copies. This branch preserves
  that release. Its current Windows attempt, installed agent and actual schedule
  are not certified by this audit; deploying code does not update that PC.
- Formal Security Scan was rechecked while its cloud skill was available. The
  required referenced scan-prologue resource still failed to read and no preflight
  helper was exposed. The skill requires source review/workers only after ready.
  The cloud skill later became unavailable. No formal scan/clearance is claimed;
  the functional linkage checks above are separate from a formal security scan.

## Exact remaining work

After accepting only the completed reference-search action, coverage is156scopes:
125passed,23partial,3failed,2not tested,2blocked,1intermittent. There are31remaining
scopes. This is one newly closed scope, not a claim that every other scope is
blocked or complete. The table preserves the outstanding test matrices as well
as requirements that need original facts, actual business cases or another device.
No existing action was split or renamed to increase the passed count.

| Existing scope no. | Module / screen | Current status | Evidence still required |
|---|---|---|---|
| 8 | Console / Approvals | not tested | An actual pending request and its owner decision; exercise the shared Director/Super Admin action without creating a fake approval. |
| 9 | Console / Backup | partial | An identified original snapshot, isolated restore destination and independently reconciled balances/counts/attachments; never restore over production for a test. |
| 22 | Masters / All master families | partial | The remaining create/amend-to-consumer matrix for every dependent document, plus an owner decision on the six advanced customer fields without a demonstrated canonical-reader consumer; existing editor and linkage fixtures cover only part. |
| 30 | Accounts / Profit & Loss / Zakat | partial | Approved annual monetary counterpart and the original account7210 opening allocation. |
| 37 | Accounts / All production payments / cancellations | partial | One owner-authorised real creditor/B/F correction is now verified in connected Chrome; remaining actual business payment/cancellation variants are still open. See the afternoon checkpoint. |
| 50 | Milling / Rent / Salary advance | partial | An actual Mill reference with an exact canonical Accounts payment/person/month match; current reference data is empty. |
| 51 | Milling / Rice Ledger | partial | Actual physical Mill/bag opening quantities and positive production/reversal control totals. |
| 62 | Exports / AMT13 relationship letter | failed | Approved AMT13 relationship-letter buyer/address/relationship wording from the commercial originals. |
| 63 | Exports / AMT13 Customs HS | failed | Approved AMT13 Customs HS classification reconciled with the issued goods description. |
| 71 | All / Every control / popup / output variant | not tested | The full cross-module button/popup/output matrix, including the dependent rows below; this umbrella is broader than a single defect. |
| 72 | Migration / Full source-to-target movement | blocked | Named migration source/target, control totals, oversized/offline/browser rehearsal and independently checked rollback. |
| 73 | Accounts / Sales Tax | partial | Current issued CI/PL linkage and positive multi-file selection/download/print acceptance; reference search is accepted separately. |
| 75 | Accounts / Monthly Bag Supplier Files | partial | An actual attached monthly supplier scan; September currently returns the correct empty state. |
| 76 | Accounts / Assets / Stockbroker / Property | partial | Positive approved finance/property/share business cases and complete amendment/voucher-output review; no real trade is created for a test. |
| 77 | Accounts / Bank Entry / Finance / Reconciliation | partial | Positive actual finance facility/amendment and saved reconciliation cases; isolated finance/correction tests pass. |
| 78 | Accounts / Prepared supplier plans / partial settlements | partial | Outstanding actual commodity/broker bills for positive allocation and plan output review; do not manufacture live payables. |
| 79 | Exports / Sales Contract creation/amendment | partial | The remaining customer/packing/approval/amendment branch matrix and original commercial acceptance. |
| 80 | Exports / New FI / L/C upload | partial | The remaining failed-upload/amendment/cancellation matrix; all four visible FI type conditionals and Cancel were rechecked. |
| 81 | Exports / All document output variants | partial | Original issued-output matrix, physical print acceptance and verified actual office-folder delivery. |
| 82 | Milling / Local Sales / used-bag outward / reprocessing | partial | Positive actual local-sale/used-bag/reprocessing sources and edit/cancel outcomes; zero used-bag stock is not a positive case. |
| 83 | Milling / Export Specifications / instructions | partial | Actual changed-master instruction/amendment acknowledgement cases and corresponding production sources. |
| 84 | Directors / All approval/report families | partial | Remaining real decision/rejection/reversal and report-completion cases with independent Director/Console parity. |
| 88 | Accounts / Sales Tax attachment results | partial | Complete current issued CI/PL indexing/output acceptance; the actual AMT row has26 distinct file URLs, including18 dated archive versions. |
| 95 | Milling / Rent / Salary references | partial | An actual Mill rent/salary reference to link exactly once to the existing canonical Accounts payment; current lists are empty. |
| 100 | Milling / Corn loading | partial | Current positive Corn sources, mixed allocation and stock reconciliation; billed corrections remain guarded and require the original business case. |
| 110 | Security / recovery / Full review sign-off | blocked | Available formal Security Scan preflight plus independent original-data recovery acceptance. |
| 119 | Accounts / Exports / Receipt startup | intermittent | Evidence explaining the intermittent runner-to-login failures; later successful retries do not establish the network/server root cause. |
| 123 | Exports / Accounts / Stored ASAS62 packing-list PDF | failed | A decision on retaining the old issued ASAS62 packing PDF or publishing a separately identified corrected revision; never overwrite the original. |
| 133 | Milling / Accounts / Native outside-mill loose Corn route | partial | Original billed replacement/cancellation and mixed-source/current-stock reconciliation; native loose/packed and immutable correction fixtures pass. |
| 143 | Accounts / Directors / Annual Zakat form and control | partial | Approved annual debit/counterpart and prior-year/opening allocation; the non-monetary assessment is not a financial posting. |
| 155 | Hosting / deployment / Account quota and code-backup capacity | partial | Hostinger billable quota/file-count breakdown and office PC installed-agent/task/archive verification; hPanel website list remained loading after one reload. |

Formal-security requirement source: [Security Scan SKILL.md](skill://Plugin_1e648473be9c8191a91ac3947151af55/security-scan/SKILL.md): “Start source review and launch scan workers only after preflight returns `ready`.” The required resource could not be read; this is a tooling blocker, not a completed security review.

## Final released acceptance

PR410 merged as d081b709d9c693a68ac728e3bdb3309fae634b97; exact-main
Accounts39407526405 and Milling/Exports39407526354 both passed. Concurrent PR412
then merged only the Accounts PKR-freight change as
5f6c3554987c1221d04a0896738e0a8e2beac31c, whose first parent is d081b709.
Its full Accounts39407631652 and Milling/Exports39407631916 suites passed.
Deployment39407971706/job118578449840 installed exact5f6c3554 and passed
provider/login health: changed users0, post-ID migration changeCount0,
419journals retained. No unrelated release or backup change was overwritten.

The subsequent2eb79739 commit adds only the separate cleanup scripts/workflow
and backup-policy documentation, so the application runtime remains5f6c3554.
Protected startup39408166109 passed. Complete protected live39408165848/
job118579088921 passed23/23 in2.5minutes:53active ledgers across3companies,
30posted JVs reviewed,365register rows, Brokerage WHT source/screen/CSV/print
credit2414.06 and0financial writes. Positive production sources were0;
that missing business case remains partial. Skipped intermediate workflow runs
are not treated as successful acceptance; the completed results above are used.

Actual refreshed local Chrome verified the deployed Customs Invoice has no
Generated20-10-2026 stamp while retaining25-09-2026, GD KPPE-SB-78089 and the
original invoice value. The other affected renderer variants are covered by the
294-output matrix, not claimed as separate live physical-print acceptances.
The Customs Packing List output control opens the native print workflow; its
print dialog stalled browser evaluation. Escape did not restore observation,
so only the agent-created output-test tab was closed to cancel it. The original
user tabs were retained. No final print, original-file replacement or
financial Post was performed. The output control calls normal saveNow() before
printing; that is a no-op when nothing is queued, but can flush queued operational
changes. No form fields were edited, and this manual click was not instrumented
for network writes. Therefore this report does not claim zero operational-save
requests from that action. The protected suite's0financial-write result is its
own measured evidence. No browser-control timeout is misreported as an app login
failure or a failed accounting transaction.

PR414/ebbcdffc later adjusts only the separate legacy-temporary-backup cleanup scripts and BACKUP_POLICY.md. A direct diff from deployed5f6c3554 to ebbcdffc contains no application runtime files; the completed live result above remains the runtime acceptance used here. The office-PC task/ZIP acceptance is still separate.
