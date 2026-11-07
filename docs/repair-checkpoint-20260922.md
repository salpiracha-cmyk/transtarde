# Repair checkpoint — 22 September 2026

Baseline main: dce868fd82e136167e97d2717938940b2d4fcc48. Preserve PR162 Exports workspace and unsaved-entry behaviour. Draft PR163 is the durable checkpoint; it is not a whole-app release.

## Implemented

Actual loading is allowed before current-shift production is recorded. Mill staff enter physical loading, production and remaining-stock/NIL confirmations. The server waits until all lots for the relevant shift are confirmed entered. Matching production clears the explained difference; only the unexplained final physical remainder is reconciled. Partial reports do not prematurely create gain/loss. Company, mill, typed product, Night-shift anchor and same-second report ordering are retained.

Ghati, carry-forward gain, indicative valuation and fixed no-stock reconciliation rows are Accounts/Directors information only. Operational screens, API values/metadata and old browser caches exclude private stores. The private management report is server-authorized. Fixed rows link to the first subsequent management production report and never post stock, Raw Rice consumption or another purchase/journal.

Existing source functions were amended. Related fixes cover first-use Arrival initialization, the header insertion parent, typed Ready source deductions, physical NIL at the selected location, and reprocessing actual input identity. Wider company/permission/accounting issues remain open.

### Final refinement

Current-shift zero-output or unrelated-brand reports no longer generate false post-reconciliation quantity warnings. Genuine changes to contributing production quantities still trigger private management review and never silently duplicate the adjustment. Source Contract, Lot and shipment references are read from the actual matched saved shipments and carried into physical confirmations, private events and fixed management rows. Explicit unrelated shipment IDs are rejected. Ambiguous legacy references are displayed as saved rather than guessed from brand/order.

The former backend test required the old client-side financial reconciliation UI and heuristic legacy matching. It now protects the server-owned provenance and private-only report. Executable PHP/HTTP assertions verify the same references.

## Test evidence at checkpoint creation

- Final PHP rule suite: 87 assertions passed.
- Original-function JavaScript/UI suite: 24 assertions passed.
- Final file-backed isolated HTTP suite: 53 assertions passed; synthetic storage and sessions removed.
- Original Accounts tests passed, including 1,004 commodity arithmetic cases; PHP syntax passed.
- Local compatible checks across both workflow definitions: 42 commands passed. Five browser/dependency-dependent commands are delegated to CI, not counted as local passes.
- Earlier source commit6507c2d: Accounts V1 QA35701315998 passed, including MySQL8/PHP8.4/Chromium70 assertions. Its Milling/Exports suite35701315962 stopped at the old reconciliation-provenance assertion after document browser tests passed. This refinement repairs provenance and updates the now-inapplicable client assertion; the final committed tree must rerun both complete CI suites.
- Refinement verification35702248185 passed. All five staged Git blobs were compared byte-for-byte with the tested local source before the explicit connector commit.

The temporary verification/staging workflows are absent from the final source tree. No automatic branch-pushing workflow is part of the application. No live test business records, historical data rewrites or deployment were made.

## Still open from the whole-app audit

G01–G19 is NOT fully closed. Continue canonical Soda/bill/hold consumers; immediate Ex-Mill liability from saved container number and weighbridge weight; blank applicable KAT means zero (failed reads do not); actual-record company authorization; full Ex-Mill multi-allocation lifecycle; duplicate financial Save protection; receipt allocation aggregation and handoff recovery; freight/transport amendments; multi-line bag invoices; complete shipment costs; wider master/document security and validation. H23/P09 and the remaining lifecycle/concurrency acceptance matrix remain open.

Do not merge/deploy this checkpoint as whole-app completion. Review subsequent source amendments on the management side; automated reversal of finalized variance and the full management review lifecycle are not certified here. Keep real records intact and isolate/clean QA fixtures. Retain test code and sanitized evidence.
