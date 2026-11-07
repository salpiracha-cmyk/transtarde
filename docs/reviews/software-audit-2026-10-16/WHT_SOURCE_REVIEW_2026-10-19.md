# Withholding source and reversal audit — 19 October 2026

Status: WHT source/output acceptance passed; final source-matched protected run passed23/23. Earlier22/23 expectation failures and the separate host-capacity incident are retained below.

Salman asked the wider review to continue while his MacBook is closed. This work uses immutable native-format journals, disposable acceptance and hosted checks; it does not require or imply an active local Chrome session.

## Reproduced defect and change

The original report classified untagged commodity-bill Buying Brokery withholding, supplier-payment/cheque withholding and Brokerage WHT deposits as OTHER. Generic cancellation and receipt-amendment journals also lost original Export, Savings or Services classification when the reversal omitted that metadata. The preserved baseline probe shows BILL, CHEQUE, CHEQUE-REV, DEPOSIT, PAYMENT, EXPORT-REV, SAVING-REV and SERVICE-REV under OTHER. This was an actual source-projection defect, not evidence that an original monetary entry was wrong.

The read-only report now retains explicitly saved line/journal categories, derives Brokerage only from its exact native source and saved withholding amount/allocation evidence, and follows an explicit reversal link to exact opposite tax lines in the same company's posted original. Missing, mismatched, ambiguous, foreign-company or cyclic originals remain OTHER rather than borrowing a category. Today's subaccount master cannot alter historic treatment. Original dates, amounts, public/audit IDs, currencies and journal bytes are preserved. No posting, master, rate, payment or physical stock writer changes.

## Focused acceptance

A balanced native-format source fixture independently expects the following totals for 1–31 October. The Export original is dated 30 September; only its October reversal belongs in this period.

| Category | Debit | Credit |
|---|---:|---:|
| Brokerage | 16 | 29 |
| Export | 0 | 20 |
| Savings | 11 | 11 |
| Services | 9 | 9 |
| Other | 7 | 2 |

PHP source totals and identities pass for TTI, BRM and TG with PKR/PKR/AED respectively. The actual PHP projection feeds the existing UI test, which independently compares exact source rows and debit/credit totals on screen, print HTML and downloaded CSV for every category. Filters/outputs make no write or additional fetch. Existing inclusive-cutoff, invalid-date, draft exclusion, formula-safe text, stale-response and truthful-load-failure checks remain.

Fresh adjacent disposable checks pass: bank reconciliation, financial-report classification, supplier payment-plan posting/correction, native outside Corn loading/billing and verified server/encrypted backup restore. These do not certify independent full migration, every original tax statement, physical openings or the live user-session output variants. Production test financial/stock/assessment/approval/source-link writes: zero.

## Acceptance limits

The current-source WHT filter/original-journal/screen/print/CSV software action is now passed: the hosted production run traces both actual native Brokerage vouchers and every populated September category, while disposable fixtures cover all five categories and reversals. This is bounded software acceptance. Independent bank/tax statement completeness, filing, positive production examples absent from the real books and every physical-print/Mac variant are separate data/wider-UI gates. Recovery's existing full MySQL/source-target and oversized offline acceptance gates remain separate; its passing file fixture is not new overall recovery clearance. Original C24 warehouse ownership, AMT13/older issued documents, annual Zakat monetary/opening classification and positive real production/cost are unchanged gates. No whole-audit completion is claimed.

## Release evidence

Feature head `153be1afad23737c3c8f612cd80b4e0aa342716e`, tree `a67dd499cd5a0769cafc252aecaf0827d5e8e8ac`: full Accounts `39252934872` (audit `118060967306`, native/MySQL `118060966662`) and Milling/Exports `39252934823` (audit `118060967040`) passed. The native/MySQL job explicitly logs the new PHP source categories and PHP-to-screen/print/CSV checks. PR362 merged as `6f44e5651b0c564e9016ff0db6e57a2533a962a2`; the exact merged tree matches the feature. Exact-main Accounts `39253462861` (audit `118062739964`, native/MySQL `118062739637`) and Milling/Exports `39253462844` (audit `118062740005`) passed. Production deployment `39253893848` / job `118064221378` passed on its first attempt: exact `6f44e5651b0c564e9016ff0db6e57a2533a962a2`, healthy login, changedUserCount0 and journal-ID migration changeCount0. Startup `39254043734` / job `118064730935` passed first attempt: usable Accounts867ms, Accounts1280ms, Milling999ms and Exports969ms. Protected read-only smoke `39254043674` / job `118064730543` completed22/23 in2.7minutes. Its sole failure was the earlier audit assertion that September must have at least one OTHER row; the report now returns0OTHER rows after classification repair. No application assertion was skipped. All53active ledgers/three companies reconciled with0revision skips; quantity6rows/0holds,0Zakat payment reviews,1retained Balance Sheet classification review and0test financial/stock/reference-link writes. The failure is retained, not labelled a transient connection error. A follow-up live acceptance now traces the exact raw original journals, requires positive native Brokerage withholding evidence and compares every populated category on screen/print/CSV; full fresh acceptance is required before calling this live check passed. Earlier intermittent failures remain in their original coverage item; this passing startup does not establish their cause. Local fixture development first exposed two assertion-shape mistakes (tax summaries distinguish head/treatment, and CSV includes a trailing reversal-reference column); both test expectations were corrected without weakening source totals. The original classification failure is retained above.


## Additional Corn source hold review

A disposable extension of the existing native outside-Corn endpoint fixture tested four attempts after the combined supplier bill was posted: corrected weight, corrected-weight preview, cancellation and cancellation preview. Every request returned409 naming the supplier-bill hold, with complete Accounts and operational-source bytes unchanged. This certifies the existing reconciliation boundary; it does not implement or certify an actual billed cancellation/replacement or reconcile physical stock. No production record was used. The broader Corn action remains partial.


## Source-tracing acceptance follow-up

PR363 feature head `70229d422a1671ef36a0969a528fa1b8d51fb201`, standalone tree `0562c7eb438f1f5b5dd5fa71406bd0c5e2f0095f`, passed full Accounts `39254978523` (audit `118067870165`, native/MySQL `118067869629`) and Milling/Exports `39254978493` (`118067869285`). It changes only four audit documents and the read-only live test, not application reporting/posting code.

PR363 merged as `39e505c3152572feae96350124b00b372ff07138`, combined tree `9b7d58ba35b3e4dcebda3a94f8fab9da8182b266`. Concurrent authorised payroll PR361 merged immediately before it as parent `97785dacdacda3fb80a56860f56b5efc6d3805d3`; comparison from that actual parent contains exactly PR363's five intended files. The full combined-main suites and production acceptance below must validate the resulting release; no concurrent payroll source is discarded.

Combined-main Milling/Exports `39255514094` / job `118069687442` passed. Accounts `39255513985` passed both audit `118069687961` and native/MySQL `118069687350`. A separate deployment trigger `39255663440` and its skipped startup/live children ran before the final-main QA completed; those skipped jobs are not acceptance or application failures. Canonical deployment `39255943002` / job `118071131753` passed first attempt: exact combined39e505c, healthy login, changedUserCount0 and journal-ID migration changeCount0. Final startup `39256130551` / job `118071760564` passed first attempt: usable Accounts897ms, Accounts1438ms, Milling1009ms and Exports986ms. Protected smoke `39256130497` / job `118071760493` passed22/23 in2.5minutes. Crucially, the new WHT check resolved both exact original COMMODITY_BILL_VERIFIED journals: saved brokerage withholding1207.10 and1206.96 equals their original tax credits; both correctly classify as BROKERAGE. All2source/screen/CSV/print rows reconcile, credit2414.06, debit0; writes0. Every populated September category was covered. Its one failure was the older salary heading assertion TOTAL SALARY PKR against the approved PR361 TOTAL MONTHLY ENTITLEMENT PKR; WHT acceptance passed independently. Retain this separate failure, not a network/startup incident. PR364 updates that obsolete read-only salary expectation and its subsequent retired amount/Post controls to the actual transaction/draft-only flow, without application code changes. Fresh full acceptance is still required for the combined general smoke.


The WHT action-level status is now passed on that evidence, with the remaining bank/tax statement and wider print boundaries explicit. Before the hosting-capacity finding, coverage was154:122passed,19partial,4failed,6not tested,2blocked,1intermittent;32open/partial. This is not whole-audit or migration sign-off.

PR364 feature `654bcb0b5b879f409125316fa3c1de37a1fb4d37` / tree `fa7c76d231488901bc82cb190d595d7b6a24f0df` passed full Accounts `39257072235` (audit `118074936072`, native/MySQL `118074936170`) and Milling/Exports `39257072261` (`118074935197`). It merged as `588745d24f6a8b0615bf0883c6dbe69111286959` with the exact feature tree. Only the one read-only test file differs from parent39e505c. Exact-main Accounts39257630039 (audit118076803926, native/MySQL118076803788) and Milling/Exports39257630051 (118076799216) passed. Deployment39258054778 failed at archive SCP before live replacement on attempt1/job118078240759 and unchanged retry/job118079228738. These are not application assertion failures. Later cache/transfer recovery and final acceptance follow below.


## Final protected acceptance and host recovery

PR365 adds only read-only storage metadata to the existing diagnostic. Its merged revision `f3d6539885e61f58b3121b8c775002793fd9e91f` ran39258849858/job118080938005 successfully: the shared filesystem had free space/inodes, incoming/staging caches held53378761bytes, and499historical code backups held6288087456bytes. Do not call shared-disk free space proof of account-quota headroom.

PR366 feature `6a7665eb86135aa2185c33e69dcb9ca35ccfa79b`, tree `0a78b40a932c020e5eb65d2b5fb4a7a90f37911e`, passed its focused disposable boundary workflow39260839346/job118087728863; the privileged host job was correctly skipped on the PR. It merged as `fa5936c7c48f29277a4f0cee991ef0de36ef5a0b`. All three files are under.github and excluded from the deployed app.

Main focused unit39261052673/job118088472582 and the actual host/source-matched audit job118088551574 passed. The disposable4KBprobe first returned DISK_QUOTA_EXCEEDED with0bytes written; its own file was removed. Only4regular generated incoming release archives and3complete SHA-named stages were removed (53378761bytes). Business/private and historical backup files touched0; all499code backups retained. The after-probe wrote4096bytes, flushed/synced and removed its file. A complete source archive then uploaded with matchingSHA256 and was removed. The live application was not replaced.

The audit read the host’s recorded deployed Git revision and proved no runtime application-source changes in Git since `39e505c3152572feae96350124b00b372ff07138`; only diagnostics/tests/docs differ in the later Git revisions. This is source equivalence to the recorded deployment, rather than a new physical installation. It then passed **23/23 protected read-only checks in2.2minutes**, including the current salary entitlement/Actual payments/draft controls and both original native Brokerage vouchers. The2source/screen/CSV/print rows still reconcile to credit2414.06; all populated September categories are covered.53active ledgers across3companies reconcile with0concurrent-revision skips. Quantity6rows/0holds, Zakat payment reviews0, retained Balance Sheet classification review1, Mill references0. Financial/stock/assessment/reference-link test writes0. Actual production remains0; earlier original-source/formal gates remain.

This closes the functional release/test acceptance. It does not label588745d installed, erase the two22/23 failures, delete recovery copies, or certify durable hosting capacity/full deployment peak. See HOST_DEPLOYMENT_CAPACITY_2026-10-19.md. Current coverage155:122passed and33open/partial, because the newly evidenced hosting-retention/capacity gate adds one partial action.
