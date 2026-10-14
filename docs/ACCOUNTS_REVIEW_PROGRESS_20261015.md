# Accounts review implementation checkpoint — 15 October 2026

Scope: the owner's 42-item Accounts Form Review dated 14 October. Preserve immutable journals, module/entity permissions, Director/Super Admin approval symmetry and explicit-save-only behaviour. Ordinary ledgers remain chronological under the later permanent rule; the review's descending ordinary-ledger suggestion is superseded.

- Batch 1, PR 231: PKR reconciliation calculation, dated cancellation display, bank-workspace activation, old-company summary removal and event-driven Bags review. Merged and production deployment/authenticated read-only smoke passed.
- Batch 2, PR 232: relevant fields, draft overrides, defaults, conditional purchase history, empty planning state, TG Payables ownership, deliberate classification and Carry-forward origin. Both QA suites passed; merged.
- Batch 3, PR 233: shared payment details, concise reference/narration presentation, TG receipt sequencing, consistent bank labels and dropdown master actions. Full QA gate remains mandatory.
- Final batch: Bags reports/files in Reports, GST rates under Master Records, separate atomic investment Edit and Reverse, payment preview, explicit short-mode dots, bank-funded petty cash tracking and final authenticated release coverage.

Financial corrections are exercised against disposable fixture books. The new release case blocks API POSTs and compares posted PKR bank balances to reconciliation while inspecting TTI/BRM/TG views. Do not use production financial postings as test data.

Two findings require record-by-record owner/master review rather than automatic data changes: similar or abbreviated party identities, and missing Fumigation/Inspection role classifications. Keep original IDs/history; do not merge by spelling or assign roles by name guesses.

Final completion requires: current-head Accounts and Milling/Exports QA success, reviewed merge, production deployment log for the exact merged SHA, successful authenticated live smoke including the review-release case, and an updated owner review showing actual coverage and any remaining limitation. QA credentials stay in the protected live-qa environment; never export them.
