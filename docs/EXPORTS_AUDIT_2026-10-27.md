# Exports module audit — 27 October 2026

Baseline: `ff6e72e0a41bb5bdaa62d3a4fe3ab9605c092a92` (Milling audit PR #606). Existing work was inspected before edits. Shipment P&L and ARP work from PR #605 remains in place.

## Confirmed defects corrected

1. FI creation, amendment, transfer, allocation and reversal could close or redraw before a server acknowledgement. These actions now await their exact staged payload. Linked Customs callbacks run only after acknowledgement. Failure retains the form and entered values, removes only this attempt's local staging, and requires reload/server review before another FI/cancellation attempt. No automatic rollback POST or blind replay is sent after an uncertain response.
2. Contract cancellation removed browser rows without a durable deletion marker; the server's additive merge could restore them. Immutable cancellation markers now remove the specific contract/process identities, survive stale browser copies, and permit a new contract identity to reuse the released reference. Server validation blocks downstream lots, bag orders, production/loading work, Mill actuals, Accounts receipts and FI allocations. Contract Edit and company scope remain enforced; Director and Super Admin use the same path. Cancellation history is visible in the Cancelled screen.
3. Cancelled lots were removed during browser normalization, hiding them from the Cancelled list after reload. They now retain a read-only history/original-document view, stay out of active reports, and wait for acknowledgement before leaving the cancellation screen.
4. The Sales Contracts table compressed references and buyer names into narrow columns on a phone. It now keeps a readable width inside its scrolling wrapper.
5. Two older standalone assertions were obsolete: the approved own-mill default is valid, and the document API now uses `tt_require_login()`. Updated those assertions and included them, plus the new failure regressions, in GitHub QA.

## Coverage and evidence

| Area | Verified coverage |
| --- | --- |
| Live Chrome | Connected Mac Chrome; authenticated Super Admin Exports navigation. Read-only inspection only. |
| Screens | 67 populated/empty states captured at 1440px and 390px (134 captures), reviewed for layout. No page errors or document-wide horizontal overflow in that sweep. |
| Contracts | Eight editor sections, saved preview, packing/pricing/payment variants, customer/master links, draft/reset and reference reuse tests. Active TG/AMT/14 process: contract, bag order, production and loading forms inspected live without saving. |
| Lots | Direct CAD, TG and L/C fixture paths through Customs, B/L, commercial documents, COO, certificates, uploads, bank dispatch, TG pack, L/C draft, final output and history. Completion/reopen permissions, closed reprints and focus/draft preservation tests. |
| FI | Live register, creation form, edit, transfer, allocation eligibility and local L/C upload form inspected without saving. Native browser tests cover seven final actions × delayed acknowledgement, rejected save and response lost after commit. Reopening another form cannot bypass review after failure. |
| Reports | All 15 report tiles inspected live. Native print actions exercised in disposable fixtures. Report groups: contracts, shipments, pending shipments/documents, customer/product, containers, B/L, bags, dispatch, FI, GD, invoices, cancelled and completed. |
| Documents | Production renderer matrix across TTI/BRM/TG, payment and packing variants; native sales contract, B/L, commercial invoice, packing list, KCCI/background-free COO, TG proforma and covering layouts reviewed. Package tests verify order, included originals, separate protected phyto, labels and filenames. Synthetic placeholder pages in package-composition fixtures are not original-document visual evidence. |
| Integrations | Milling production/bag/loading and actuals bridge; Accounts receipts, FI credit-advice links/tags and TG pending references; document indexes/uploads/downloads; entity/icon restrictions; Director/Super Admin parity. |
| Office copies | Office refresh controls and delivery-status action inspected. Disposable package/copy tests verify committed-source behavior and reprints. No live folder refresh was queued. |

Local verification: all 45 Exports JavaScript test files passed after the primary fixes; all 15 additional PHP/Python/Accounts integration files passed. Follow-up tests cover the final entity/history, uncertain-save and phone-layout changes. GitHub runs and deployment are recorded on the PR rather than asserted in this pre-deployment document.

## AMT/13 variety finding

The current saved Sales Contract, live Sales Contract Register and Product-wise Export report show **PK386 White Rice, 270 MT**. Live history records amendments by Salman on 25 September and Jazib on 29 September. The visible history does not identify which amendment changed the variety, and an issued addendum was not established. No historical document or business record was rewritten. A customer remains free to buy any variety; no customer-variety restriction was introduced.

## Limits

Production transaction saves, cancellations, uploads, approvals, reopening and financial/stock postings were not used as tests. All mutation and failure tests used disposable fixtures. Physical printer/KCCI paper alignment and actual office-PC delivery were not exercised. Optional real-world documents and unusual input combinations beyond the documented fixture matrix are not an exhaustive guarantee. Existing financial amounts, missing credit-advice links and original uploaded documents were not inferred or altered.
