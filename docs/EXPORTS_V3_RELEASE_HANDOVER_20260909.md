# Transtrade Exports V3 release handover - 09 September 2026

## Release intent

Clean Export-module rebuild. The legacy prototype was used only to compare screens, labels and business behaviour. Its layered JavaScript was not copied.

## Locked interface

- Active Shipments is the default contract-centred screen; no separate Home workflow.
- Main screen retains `+ FI`, `+ New Sales Contract`, Active Contracts, Active Lots, and hover-only Mill Updates.
- Contract cards contain Shipment Process and their Lot buttons.
- Shipment Process contains Sales Contract, BAG ORDER, Production Instructions and Loading Instructions.
- Loading Instructions create the lot; lot documents live in the separate lot workspace.
- Tiles use a single-open accordion and open their form immediately below the tile row.
- Logout is a power icon at the top right.

## Locked workflow and integrity controls

- Customer contract and L/C uploads are reviewed before becoming masters; extraction runs locally in the browser.
- One contract reference, one process shell, and separate exact lot records prevent duplicate operational entry.
- One multi-line Bag PO covers all packing lines and master bags. Uploading each GOOD SIDE mark is the approval event; no approval status/source stages exist. Every mark receives its own large PO page.
- Production and multi-location Loading Instructions transfer to Milling. Container, seal, bags, weights, gate pass and loading date return from Milling to the exact lot and are locked on the Export side.
- Valid containers require four letters and seven digits, displayed with the hyphen before the final digit.
- FI allocation, reversal and transfer are audited. Customs cannot save/print unless FI plus open-account balance equals invoice value.
- Accounts receipts are consumed read-only by stable receipt identity; Exports has no duplicate receipt-entry form.
- TG customer documents and Pakistan exporter-to-TG settlement documents remain separate. Pakistan Customs uses only the approved intercompany price.
- Final document upload, bank covering, courier AWB, dispatch freeze and completion gates are enforced.
- Every document print asks between digital artwork and physical letterhead.

## Backend

- Shared operational source of truth: MySQL `tt_operation_records` with row locking and optimistic versions.
- Mill can merge only Mill-owned shipment actuals; Accounts can merge only posted/reversed receipt records.
- Protected original documents are stored outside public module files, with authenticated metadata/downloads.
- Production starts clean; old Export dummy records are not migrated.

## Verification completed locally

- JavaScript syntax, unique functions, DOM references, accordion behaviour and navigation.
- Contract/L/C parsing and reviewed import.
- Complete dummy Contract -> Bag PO -> Production -> Loading -> Lot -> Mill return -> FI/Customs -> documents -> dispatch flow.
- Accounts receipt posting/reversal and exact-lot Mill bridge.
- TG customer/Pakistan route separation.
- PHP static parsing and security assertions for authentication, CSRF, row locks, conflicts and protected uploads.
- TTI, BRM and TG representative A4 sets rendered to 13 pages each; no blank pages, clipping or orphan signatures.

## Rollback and promotion

- GitHub production baseline before this release: `7c230deb2106d5abb3a9b8ba2faf517b61c65cb6`.
- Canary files are deployed alongside the existing Export module first.
- The live `module.php?id=exports` route is changed only after the authenticated canary passes.
- Rollback is the baseline commit above; the old Export files remain preserved during canary.
