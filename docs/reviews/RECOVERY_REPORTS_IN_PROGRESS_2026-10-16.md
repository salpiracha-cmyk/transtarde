# Recovery reporting — development checkpoint

Unpublished work on `fix/actual-recovery-reports-20261016`, based on bag PR #259 head `9b29b5b`. Bag PR #259 itself is published and both hosted QA suites passed. Do not merge or deploy this separate recovery branch in its present state.

Implemented draft: pure recovery calculation, read-only endpoint, shared Daily Production/Daily Recovery/Brand/Contract report UI with print and CSV, raw-input capture with per-output allocation controls, Accounts report navigation and replacement Mill report views. No historical input is invented from output. Ratios use independent inputs and outputs. Source runs, lots, gross/less/net weights and unassigned allocations are retained.

Verified so far: `php tests/accounts-v1/mill_recovery_test.php` passes the supplied sample's input 475370kg, finished output 350000kg, by-products 128560kg, total 478560kg, negative difference 3190kg, 100.6711% overall recovery and 73.6269% finished recovery. Tests also cover weighted ratios, missing historical input, filters, private-row exclusion and a missing allocation in one run not being filled by another run's input. Draft JavaScript/PHP and Mill inline syntax passed.

Still required before publication:

1. Wire strict server validation of actual input into operations sanitization; preserve legacy records. Review numeric validation and variance explanations on the server.
2. Use actual input for new stock consumption in both the Mill quantity ledger and server reconciliation. Preserve old stock movements where actual input was never recorded.
3. Feed actual input to final and management costing; expose missing input instead of deriving it from outputs. Preserve approved cost snapshots/journals. Confirm entity separation and how mixed missing-input shifts affect weighted raw-stock costing.
4. Finish report API authorization: company permissions plus Mill-only users and location scope; validate calendar dates and fail closed on invalid source shapes. Add real HTTP read-only/entity/location/corrupt-source tests.
5. Review raw lot fields, brand/contract choices, context restoration and partial amendments. Avoid erasing draft values on output-table changes. Check report filter stale-response handling, print and CSV filter parity.
6. Run meaningful entry/report UI tests and browser visual checks at desktop/mobile sizes. Inspect runtime asset paths through module.php. Check new production reset/edit/save flows and existing reconciliation/costing regressions.
7. Persist final review evidence, publish a separate tested PR, run hosted QA, and obtain/confirm release authorization before deployment. Do not describe this checkpoint as finished recovery reporting.
