# Audit checkpoint — 17 October 2026

**Not ready for full data migration.** This is a continuation checkpoint, not completion of the exhaustive screen/action review.

## Verified releases

| Batch | Status and evidence |
|---|---|
| PR279 | Deployed c9bda30904ef99b93d8c33aa65e4c44ff3b4bb2a; deployment39013354578/job117340327907, backup and healthy login. Both final-head suites passed39012882204/39012882228. Actual Mac Chrome Rice Ledger Generate now works; three arrivals30500+39000+50000=119500kg. Period24–30Sep shows opening119500, period0, closing119500 with Outward-only display. Corrected permission link opens authenticated Console. Bag ledger opens; positive period cases remain fixtures. |
| PR280 | Deployed7475f111271c1fb315eb1326c916b1a43e796176; deployment39013780419/job117341567768, backup, changed users0, healthy login. Both final-head suites39013302325/39013302356 and main suites39013566515/39013566529 passed. Fresh live smoke39013826582/job117341704668 passed18/18, including all12 Masters round trips,50 financial ledgers/three balanced companies and4 recovery modes. No production records existed for those recovery checks. |
| PR281 | Final head282dae03859d9d2ee5938d81244b37b7d17d8d65 passed both full hosted suites39014169065/39014169055. Merged09a899d14ed99ffa91cba73ce3a5031253843ad0. Main QA39014376897/39014376884 is in progress at this checkpoint; deployment/fresh Mac acceptance are not claimed. |

PR279's earlier smoke passed17/18 but failed to find the Masters return control on round7 after six successful round trips. PR280's fresh smoke passed all12. The earlier intermittent cause is still unverified; PR281 adds failure-only diagnostics without relaxing assertions.

## PR281 behavior awaiting production/Mac acceptance

- Actual selected TTI Rice Mills list incorrectly exposed ASAS orders delivered to Indus Rice Mill. The repair scopes visible/actionable orders, stale receipt/status/transfer calls and production bag selection to the selected company/mill; legacy issues inherit their original production's scope.
- Fractional transfers and ambiguous same-brand source orders are refused. Success now says destination receipt is unverified. This does not implement the missing C20 destination receiving workflow or allocate consumption across multiple POs.
- Actual Artwork control displayed only hardcoded GHAZAL BAG ARTWORK under Approved Artwork. The repair displays the original linked image/file reference and filename, handles missing/denied files truthfully, rejects unsafe/external references and preserves existing authentication. No approval is invented.
- Actual conditional tare input preserved122 when hidden and reshown; no receipt was saved.

## New reproduced calculation defect: C23

The actual generateLabourDraft function from PR281 was executed in an isolated JavaScript fixture after the local device disconnected. Selected commodity was Maize; the same company/mill contained Rice1000kg and Maize2000kg within the selected period. **Expected Maize work2000kg; actual auto-filled milling work3000kg.** The function filters company, mill and dates but ignores the selected commodity when gathering work. No live data or accounting record was touched.

Correction remains outstanding. Verify source commodity identities, shared container/labour treatment and historical missing metadata; do not guess a commodity or silently mislabel mixed work. Existing scope tests do not cover mixed commodities.

## Connection limits

Actual Mac Chrome was successfully controlled in the signed-in application throughout this continuation. It later lost its control transport, and the local exec service disconnected too. This is a session connection failure, not evidence of incorrect Chrome setup. GitHub tools still respond. PR281's final fresh Mac check cannot currently be performed.

Mac Rice CSV download inspection timed out and reset the browser tool. It does not prove an application download failure; live CSV acceptance remains unverified. Older Export Bags Ledger lacks print/CSV controls and leaves From visible in Till mode.

## Remaining migration gates

1. R07/R08: canonical Mill bill-to-Accounts liability/review/payment/amendment/reversal handoff is missing; reported rent/salary references are not proof of posted payments. See MILL_HANDOFF_REQUIREMENTS.md. Never bulk-post unverified estimates.
2. C20: source-only bag transfer with no acknowledged destination receipt.
3. C23: commodity ignored by Labour auto-fill.
4. Used-bag scope/undated dates, multi-PO stock allocation, stock source drill-down and physical/production reconciliation.
5. Remaining financial/commodity/tax report drill-down and output parity, exact FI/receipt allocations and all untested screen/action variants.
6. Owner review of saved AMT Customs HS/description and relationship-letter party mismatches; preserve issued documents.
7. Master consolidation and exhaustive field-to-consumer inventory; no field deletion approved.
8. Named secure disposable import destination, source/control totals and rollback rehearsal.

No real approval, payment, receipt, reversal, master or staff grant was committed in the UI review. Application releases were tested changes, not financial data migrations. Zakat remains deferred; the existing reminder is18 October2026 at14:00 Asia/Karachi.

The full current report, coverage register, test history and preliminary master proposal are preserved in docs/reviews/software-audit-2026-10-16 on PR281's branch. This checkpoint adds the new calculation case and connection boundary.
