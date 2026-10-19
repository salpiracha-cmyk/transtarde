# Withholding source and reversal audit — 19 October 2026

Status: focused source/output repair prepared; full regression and release acceptance pending.

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

Pending feature/main full QA, exact deployed revision and protected read-only acceptance. Local fixture development first exposed two assertion-shape mistakes (tax summaries distinguish head/treatment, and CSV includes a trailing reversal-reference column); both test expectations were corrected without weakening source totals. The original classification failure is retained above.
