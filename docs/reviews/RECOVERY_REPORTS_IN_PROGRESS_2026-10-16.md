# Actual recovery reporting — release verification

This change follows deployed bag PR #259 and Bank menu PR #260. It adds daily production, daily recovery, brand and contract reporting from actual raw lots and actual outputs, with source tracing, quantity filters, search, print and CSV. It reuses the final Accounts and management costing engines rather than maintaining another financial calculation.

## Corrected behavior

- New production requires independently recorded raw input. Raw consumption uses that actual input. Existing output-only stock movements remain unchanged until an explicit production amendment; no historical input is generated.
- The supplied reference is reproduced: 475,370kg input, 350,000kg finished rice, 128,560kg by-products, 478,560kg output, -3,190kg difference, 100.6711% total recovery and 73.6269% finished recovery. Excess remains visible and requires an explanation on new saves.
- TTI/BRM production, raw purchase pools, processing costs and prior sale valuation sources are separate. Missing earlier input blocks a falsely final weighted average. Incomplete conversion-cost allocation periods remain pending.
- Approved costs retain their saved snapshots. New approvals require the reviewed source fingerprint; source changes require review. Report reads never save rates, estimates or journals.
- Management estimates identify their purchase/Director/fallback sources. Missing actual quantities or brand/contract allocation produces no invented cost. Shared by-products are not divided proportionally between brands.
- Lot brand/contract defaults reduce typing; optional row allocation supports mixed production. Finished brands follow the actual stock product. Explicit changes clear known incompatible contract defaults; edits and output-table changes retain raw drafts.
- Reports enforce company, module and location restrictions. Location-restricted and Mill-only users receive quantities without company-wide financial figures. Invalid dates, filters or corrupted sources fail explicitly.
- Print and CSV use the same generated report and applied filters. Filter changes disable old exports until Generate. Company/filter changes discard stale responses. Return navigation restores the Accounts Reports origin.

## Local evidence

- All 113 workflow-listed Accounts audit/control/runtime checks passed locally.
- Inventory reconciliation: 103 unit assertions, 24 client assertions and 103 real file-backed HTTP assertions passed.
- Actual recovery sample, weighted costs/by-product deductions, allocation boundaries, legacy approvals, read-only/location/company HTTP access, stale cost approval and corrupted-store protection passed.
- Entry/report DOM tests passed: saved draft restoration, no automatic writes, dependent defaults, exact cost presentation, CSV/print parity and stale-filter response ownership.
- New backend checks run from the existing required end-to-end audit; report DOM/browser checks run from the existing costing-navigation check. No workflow file or secret changes.

## Hosted verification

PR #261: initial hosted Accounts QA [38885558293](https://github.com/salpiracha-cmyk/transtarde/actions/runs/38885558293) and Milling/Exports QA [38885558292](https://github.com/salpiracha-cmyk/transtarde/actions/runs/38885558292) passed. Hosted actual MySQL/browser reconciliation passed 121 assertions on that revision. Desktop (1440px), mobile (390px) report screenshots and the actual Mill entry screenshot were inspected; tables scroll inside their containers and the entry card is aligned. Shorter row-default labels and a single cost summary for a one-group report remove visual duplication.

Final checks additionally exercise the canonical configured file/MySQL operations backend for recovery, final costing and management costing. SQL connection failures must not substitute a stale file. Reprocessing waits for input-stock cost review, distinct B2 CSR values stay separate and empty shift markers create no costing hold.

Pending: final-head hosted suites, production deployment health and extended authenticated read-only live recovery smoke. Local headless Chromium could not start inside the current runtime; no claim is made that Mac Chrome visual QA was performed. Hosted browser evidence is required before declaring the release verified.

Historical input and actual allocation cannot be recovered from an output-only record. Such records remain explicitly incomplete until someone enters the original facts; this is a data limitation, not an automatic migration task.
