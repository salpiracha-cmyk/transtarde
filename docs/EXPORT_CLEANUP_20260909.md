# Transtrade Export cleanup — 9 September 2026

## Purpose
Make the current Export SOL/V3 build the only active Export application without risking genuine operational history or cross-module data.

## Removed
- Retired monolithic Export V2.6 prototype HTML.
- Superseded duplicate Export bridge/release tests under `tests/exports/`.
- On Export load, only the two confirmed browser-storage keys belonging to the retired V2.6 prototype are removed:
  - `transtrade_export_master_v26_final`
  - `transtrade_export_master_v26_final_draft_sales_contract`

## Preserved deliberately
- Current SOL/V3 operational state: `transtrade_export_v3_operational`.
- Export↔Mill shared keys and live Mill data, including `tt30bags`, `tt30prodinst`, `tt30ship`, and `tt35exmill`.
- Shared Customer Master and Super Admin master records.
- Real contracts, shipments, FI/GD history, audit/version records and document references in current operational state.
- Current `tests/exports-v3/` acceptance suite.
- `exports-canary/assets/`: despite the historical directory name, it currently contains the larger/latest approved TTI/BRM/TG artwork used by the live document wrapper. It is retained as an asset source, not as a canary application.

## Live/private operational records
Transtrade live operational storage is outside the Git repository under the private Hostinger data directory. This cleanup intentionally does not perform a blanket deletion of server-side operational records. Any future deletion of known test/corrupt records must be record-specific and reference-aware; records used by contracts, Milling, FI/GD, Accounts or audit history should be archived rather than erased.

## Safety
A pre-cleanup Git branch was created: `backup-before-export-data-cleanup-20260909`.
