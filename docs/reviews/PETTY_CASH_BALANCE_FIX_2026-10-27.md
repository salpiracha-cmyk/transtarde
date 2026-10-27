# Office petty-cash balance consistency — 27 October 2026

Baseline: main `2052199b0df5d83c184dd71c33ef23a5d86334f5` (PR #608), inspected before editing.

## Confirmed issue

Read-only live Chrome review of TTI showed Office Petty Cash at PKR 57,180, cash with Hassan at PKR 2,000 and cash with Balouch at PKR 3,000. The owner confirmed the office figure. Home's cash row, Bank Accounts & Balances and its cash ledger displayed PKR 153,280. The bank projection summed every 1120 cash leg, and the card opened the combined 1120 control ledger instead of the office custody ledger.

## Correction

- Use the existing office/staff/mill cash identity convention in a shared read helper. Unlabelled historical cash remains office cash; mill location and staff identities remain distinct.
- Home and the bank cash card explicitly show Office Petty Cash and its balance. The office payment-planning choice uses only office cash.
- The cash card opens `CASH|TTI` (or the selected company's office identity). All Ledgers supports office, staff and mill custody ledgers with date/opening/running balances, print and Excel. Inactive holders retain their history. The 1120 combined control remains available and is explicitly labelled All Cash · Office, Staff and Mills.
- Preserve company/workflow permissions, journal source identity, correction/reversal projection, the general cash control total and functional currency. No adjustment, migration or journal rewrite is included.

## Verification before PR

A disposable HTTP fixture runs the actual Petty Cash, bank, Home, ledger and CSV routes. It verifies office/staff/mill separation, transfers, amended postings, opening/date cutoffs, inactive holders, other-company rejection, TG AED cash and malformed-date exclusion. The fixture asserts byte-unchanged financial storage. A synthetic reconciliation of 57,180 office + 2,000 and 3,000 staff + 91,100 mill = 153,280 reproduces the reported mismatch; the mill component is not claimed as independently verified production evidence at this stage.

Native Chromium checks exercise the bank-card button, ledger name lookup and direct cash selection, printed heading/amount, Excel content and 1440/390-pixel layout. Existing bank name and party searches remain covered. Desktop/mobile screenshots were visually reviewed.

Additional fresh passing tests: bank_balance_asof, petty_cash_http, ledger_catalog_identity, ledger_visibility, ledger_amend_dates_ui, bank_ledger_name_search_ui, dashboard_commodity_dues_http, accounts_read_scope and bank_entries_http. New HTTP coverage is included in Accounts V1 QA; expanded native-browser coverage runs in its existing job.

## Milling / Exports closure deployment

PR #608 deployed successfully as `2052199b0df5d83c184dd71c33ef23a5d86334f5`; deployment run 40501285331 verified 545 application files and reported `businessFilesWritten: 0`. After reloading Chrome, Office Document Refresh → CHECK DELIVERY reported 3 of 3 packages verified, each with 7 of 7 files, for AMT/13, ASAS/62 and ASAS/63. No packages or business entries were created during verification. Physical paper alignment and AMT/13 addendum provenance retain the limitations in the closure audit.
