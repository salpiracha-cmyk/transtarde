# Milling and Exports audit closure — 27 October 2026

Baseline: `d693a98fd3a5c0dfe5be1049bb971684e28234d9`, incorporating Milling PR #606 and Exports PR #607. Current GitHub main and the recorded evidence were inspected before editing. This is closure of the documented software audit matrix, not a guarantee of every possible business input or physical device.

## Correction to the earlier status

The earlier conversational status incorrectly described the Milling walkthrough and `bag_director_decision_ui_test.js` as unfinished. PR #606 already contains that fixture repair and the completed coverage record in `MILLING_AUDIT_2026-10-27.md`. This pass independently reran the bag-decision test on main: it passes.

The retained 105-test result file contains four initial failures, followed by corrections in PR #606. All four were rerun successfully in this closure: stock reconciliation review HTTP, security report rendering, startup delivery and export asset delivery. The retained 72 Milling captures cover 36 states at desktop/phone sizes, with no JavaScript errors or document-wide overflow. Wide tables scroll inside their containers.

Fresh disposable native-browser runs passed for operational save/failure/retry/reload and the Mill source-to-Accounts bill, correction and partial/final payment flow. The Office Agent delivery endpoint, agent jobs and document-policy tests passed. All test saves used isolated application copies; no production business entries were created.

Existing Exports evidence remains 67 states at desktop/phone sizes, all 15 reports, document variant/rendering checks and cross-module permission/source tests. The earlier audit records provide the detailed scope. PR #605 Shipment P&L and ARP reports remain unchanged.

## Additional confirmed defect and correction

On a fresh Exports session, Office Document Refresh → CHECK DELIVERY silently did nothing. It iterated only the browser's previous refresh results, so reopening the browser discarded every job to check.

CHECK DELIVERY now reads the committed active/completed lot list and queries the existing authorised server endpoint for each lot's latest package. It does not refresh folders, save drafts, reopen lots, queue packages or create stock/financial records. It displays missing, pending, verified, incomplete, agent-update and failed states; one denied/unavailable shipment does not hide the other results. Both actions stay disabled during a check/preparation, requests have a timeout, and results preserve any current preparation failure. Warnings remain attached to their original package identity.

The new native Chromium regression covers a fresh session, reopening without remembered jobs, an unsaved extra lot, process/cancelled exclusions, newer jobs, no queued package, incomplete receipts, permission/network failures, concurrency, failed preparation, package-specific warnings and empty state. It asserts zero business writes. The regression and the existing endpoint authorization/checksum test are included in GitHub Milling/Exports QA.

## Live read-only verification

Connected Mac Chrome is accessible. Through Completed Shipments → VIEW → CHECK OFFICE DELIVERY, each of the three currently completed lots reports **7 of 7 files match their saved hashes**. This is an actual persisted Office Agent receipt, rather than an assumption that queued means delivered. No replacement package was queued to obtain that evidence.

Baseline GitHub main checks: Accounts V1 QA `40496909176`, Milling and Exports QA `40496909255`, both successful. Production deployment `40497579612` verified 545 application files against the exact baseline SHA and reported `businessFilesWritten: 0`. The closure PR records its own final GitHub checks and deployment.

## Remaining real-world boundaries

- Browser/PDF layouts are checked. A physical printer's paper feed, scaling and KCCI preprinted-sheet alignment require an actual paper print and remain outside the remote software sign-off.
- AMT/13 currently records PK386, and the visible history records contract amendments. The available history does not establish the exact field-changing amendment or prove that a separate addendum was issued. Preserve this as a document-provenance follow-up; do not invent an addendum or rewrite historical records. Customers remain free to buy any variety.
- The separately requested gate-pass/report presentation redesign is a separate change; this closure does not claim to implement it.
