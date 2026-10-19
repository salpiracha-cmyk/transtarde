# Withholding source and reversal audit — 19 October 2026

Status: PR362 deployed after full feature/main QA; first protected smoke22/23, with source-tracing/all-category acceptance follow-up pending.

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

The combined WHT action remains partial pending original statement/category comparison and final actual user-session output review. Recovery's existing full MySQL/source-target and oversized offline acceptance gates remain separate; its passing file fixture is not new overall recovery clearance. Original C24 warehouse ownership, AMT13/older issued documents, annual Zakat monetary/opening classification and positive real production/cost are unchanged gates. No whole-audit completion is claimed.

## Release evidence

Feature head `153be1afad23737c3c8f612cd80b4e0aa342716e`, tree `a67dd499cd5a0769cafc252aecaf0827d5e8e8ac`: full Accounts `39252934872` (audit `118060967306`, native/MySQL `118060966662`) and Milling/Exports `39252934823` (audit `118060967040`) passed. The native/MySQL job explicitly logs the new PHP source categories and PHP-to-screen/print/CSV checks. PR362 merged as `6f44e5651b0c564e9016ff0db6e57a2533a962a2`; the exact merged tree matches the feature. Exact-main Accounts `39253462861` (audit `118062739964`, native/MySQL `118062739637`) and Milling/Exports `39253462844` (audit `118062740005`) passed. Production deployment `39253893848` / job `118064221378` passed on its first attempt: exact `6f44e5651b0c564e9016ff0db6e57a2533a962a2`, healthy login, changedUserCount0 and journal-ID migration changeCount0. Startup `39254043734` / job `118064730935` passed first attempt: usable Accounts867ms, Accounts1280ms, Milling999ms and Exports969ms. Protected read-only smoke `39254043674` / job `118064730543` completed22/23 in2.7minutes. Its sole failure was the earlier audit assertion that September must have at least one OTHER row; the report now returns0OTHER rows after classification repair. No application assertion was skipped. All53active ledgers/three companies reconciled with0revision skips; quantity6rows/0holds,0Zakat payment reviews,1retained Balance Sheet classification review and0test financial/stock/reference-link writes. The failure is retained, not labelled a transient connection error. A follow-up live acceptance now traces the exact raw original journals, requires positive native Brokerage withholding evidence and compares every populated category on screen/print/CSV; full fresh acceptance is required before calling this live check passed. Earlier intermittent failures remain in their original coverage item; this passing startup does not establish their cause. Local fixture development first exposed two assertion-shape mistakes (tax summaries distinguish head/treatment, and CSV includes a trailing reversal-reference column); both test expectations were corrected without weakening source totals. The original classification failure is retained above.


## Additional Corn source hold review

A disposable extension of the existing native outside-Corn endpoint fixture tested four attempts after the combined supplier bill was posted: corrected weight, corrected-weight preview, cancellation and cancellation preview. Every request returned409 naming the supplier-bill hold, with complete Accounts and operational-source bytes unchanged. This certifies the existing reconciliation boundary; it does not implement or certify an actual billed cancellation/replacement or reconcile physical stock. No production record was used. The broader Corn action remains partial.
