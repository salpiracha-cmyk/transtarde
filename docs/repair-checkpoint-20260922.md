# Repair checkpoint — 22 September 2026

Baseline: dce868fd82e136167e97d2717938940b2d4fcc48. Preserve PR162 Exports workspace/unsaved-entry behaviour.

## Implemented in this checkpoint

Actual loading remains allowed before current-shift production. Physical stock confirmations are saved facts, not client-entered Ghati values. The server waits until the operator confirms all lots for the relevant shift have been entered. Matching production clears the explained quantity; only the unexplained final physical difference is reconciled. Partial reports do not prematurely finalize it. Night-shift anchoring, source-company/mill/product/Rice Type, retries and same-second report ordering are covered.

Ghati, carry-forward gain, indicative valuation and fixed no-stock reconciliation rows are Accounts/Directors information only. Mill screens, operational API responses, response metadata and old browser caches exclude private stores. Mill staff may still enter physical loading/production and confirm physical remaining stock. Management reports are server-authorized, not just hidden links. Fixed rows remain server-side projections linked to the first subsequent report, with no stock, Raw Rice consumption or extra journal posting.

Existing source functions were amended. This checkpoint also corrects first-use Arrival initialization, header insertion parent checks, typed Ready source deductions, source-location physical NIL and actual input-stage retention during reprocessing. Wider company/permission and accounting defects are not claimed closed by these limited repairs.

## Tests before this commit

- PHP private reconciliation rules: 77 assertions passed.
- Original-function JavaScript/UI tests: 24 assertions passed.
- Isolated original PHP file-backed HTTP tests: 50 assertions passed, with synthetic storage/session cleanup.
- Original Accounts JavaScript suite passed, including 1,004 commodity arithmetic cases.
- PHP syntax and source whitespace checks passed.
- Read-only GitHub verification run 35700968984 passed; staged source hashes match the tested local files exactly.
- MySQL/Chromium and full Exports regressions must run on this committed tree before release; not claimed passed here.

Temporary source-transport workflows are not part of this tree. No runtime override script, production test entries, historical rewrite or deployment is introduced by the checkpoint.

## Still open from the whole-app audit

The G01–G19 audit is NOT fully repaired. Continue canonical Soda/bill/hold consumers; immediate Ex-Mill liability from saved container number plus weighbridge weight; no configured KAT means zero (a failed read is not zero); actual-record company authorization; full Ex-Mill allocation lifecycle; duplicate financial Save protection; receipt allocation aggregation and handoff recovery; freight/transport amendments; multi-line bag invoices; complete shipment costing; wider master/document controls and validation. H23/P09 and the remaining acceptance matrix also remain open.

Do not merge/deploy this checkpoint as a whole-app completion. Keep changes durable on the repair branch and report remaining work accurately. Preserve real transactions; isolate and clean QA fixtures only.
