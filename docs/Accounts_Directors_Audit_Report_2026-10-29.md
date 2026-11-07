# Accounts and Directors — software audit and compiled fixes

**Date:** 29 October 2026, Pakistan time

**Status:** Local audit and compiled fixes ready for review. Not uploaded to GitHub or deployed.

**Production baseline:** `b7075344` (the direct salary-sheet release, PR #649).

## Confirmed faults and fixes

| Area | Reproduced behaviour before the fix | Corrected behaviour |
| --- | --- | --- |
| Reopening a corrected Bank Entry | A date correction updated the journal but the original form snapshot could restore the old posting or payment date. | The form opens with the committed posting date and corrected payment-row dates. Date-only corrections retain the original reconciliation identity. |
| Local Sales and Fellow Exporter receipt corrections | The date-only route could bypass the receipt's normal source lifecycle, leaving SODA payment records out of step with the bank journal. | Corrections use the native receipt reversal/replacement engine. Bank, SODA, advances and audit links update together. Preview does not post. |
| Backdating a corrected fellow-exporter receipt | The receipt's own cancelled original/reversal could be mistaken for an active later financial entry and block its replacement. | Cancelled pairs no longer block their own replacement. Genuine active later SODA entries still protect chronological posting. |
| Historical Directors financial calculations | A legacy backdated amendment could count both the old payment and its replacement before the audit reversal date. This affected cash flow, financing and SODA balances. | These calculations reuse the ledger's verified amendment projection. Accounts receipt balances, Directors SODA statements and financial sources agree at the same cutoff. Original journals and audit dates remain unchanged. |

The historical calculation fault was demonstrated with a corrected payment of 80 that previously appeared as 180 in the affected cash-flow projection. A financing fixture similarly counted 180,000 principal instead of the corrected 80,000. Both regressions now pass.

## Scope reviewed

| Module | Areas checked |
| --- | --- |
| Accounts | Bank receipts/payments/transfers, bank finance, petty cash and cash holders, expenses and invoice payments, salaries and scheduled advances, purchases/SODAs, commodity and supplier liabilities, party settlements, amendments/reversals, reconciliation, ledgers, trial balance, tax and operational reports. |
| Directors | Costing and financing, fellow-exporter agreements, local ex-mill sales, shipment partnerships and settlements, SODA account statements, forecast replacement, cash flow, Daily/ARP stock coverage, report-source permissions and company separation. |
| Connections | Directors-to-Mill SODA/loading handoff, weights and gate passes, stock reconciliation, bag transfers/receipts, Export sources, report copies and document delivery. |

Existing behaviour was retained where the checks passed. No actual business amounts were adjusted and no sample financial transactions were posted to the live application.

## Verification

- **452 distinct scripted test commands passed**, after resolving local browser/database prerequisites and rerunning the affected checks. The package includes the per-command verification index.
- **Nine real MySQL 8 database-backed checks passed**, covering source authority, atomic restore/rollback, mill amendments, bag movements, handoffs and inventory reconciliation. The inventory HTTP suite completed **171 assertions**.
- **271 PHP files** under Accounts, Directors and their APIs passed syntax checks; Accounts/Directors JavaScript syntax checks also passed.
- The Office document policy accepted **38 actual rendered PDF/Word outputs**. The final Export document browser-rendering check passed.
- Five native operational browser flows and eleven focused ledger/party UI checks passed. Added regressions verify preview isolation, corrected native-form reopening, SODA payment dates, advance handling, stale-retry rejection and protection of active later entries.
- Additional full-application read-only checks passed for 16 independent screen scenarios. Three further report scenarios passed against initialized disposable sources: ledger/trial-balance agreement, quantity/Zakat/Mill references, and ARP/shipment report loading. The financial journals stayed unchanged.

Two old browser-test assumptions were corrected separately: Party Ledger overview versus selected-party warnings and the shared print/view controls; and selecting the visible bank-setup alert rather than an intentionally hidden error placeholder. These are test-maintenance changes, not a ledger UI redesign.

## Checks that still require the real deployment and records

The production-oriented smoke suite also requests specific saved records: AMT/TG contracts, Ali Sulaiman invoices, Mehr Allah Ditta, September WHT postings and completed ASAS62 documents. These are absent from a clean disposable installation. Those exact-record comparisons are **not certified by this local audit**; their dedicated fixture-based software paths have passed, and the existing live tests remain required after release.

Publication, remote release CI, verification of the deployed commit and authenticated live read-only checks remain pending approval. Saved historical cash-flow snapshots are preserved; a newly generated historical report can show a corrected total where the confirmed amendment defect applied.

## Review package and release path

`changes.patch` contains the compiled change from the stated production baseline, including regression tests. `verification-summary.json` identifies the scripted checks and evidence sources. The package also includes the technical continuation checkpoint.

After approval: publish this exact reviewed change to `salpiracha-cmyk/transtarde`, require Accounts and Milling/Exports release suites to pass, merge, deploy, then verify the production commit and live read-only checks. If a release check exposes another fault, resolve it and retest before deployment. Preserve the prior release as the code rollback point; these fixes do not migrate or rewrite stored business records.

The automatic continuation remains scheduled for **30 October 2026 at 3:15 am Pakistan time**. It will read the checkpoint and subsequent approval messages before continuing; it must not bypass a publication rejection.
