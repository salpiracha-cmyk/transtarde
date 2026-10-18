# Continuation in the replacement chat — 18 October 2026

Recovered main d10bd1b and the final PR330/331 audit checkpoint. The application
review remains open. Do not infer that earlier navigation/empty-state passes
certify positive financial or physical-stock cases.

## Confirmed Ex-Mill retry defect

The actual `sync_lifting` endpoint rewrites the original provisional journal and
lifting, adds version history and increments Accounts revision for an unchanged
retry. It also rereads the current Soda rate, so a response-loss retry after a
Soda amendment can reprice the original lifting. The extended actual-auth test
fails against unmodified d10bd1b on its byte-identical retry assertion.

Unchanged requests now acknowledge the verified existing lifting, journal and
event without writing any Accounts bytes. The original Post ID, amount, rate,
bill linkage, versions and revision stay intact after rate/quantity changes on
the Soda. Missing/deleted/mismatched original journals are held for Accounts
reconciliation. Existing role/location/company checks still run before replay.
This does not implement immutable correction of materially changed liftings;
that broader correction route remains a separate open finding.

Local related endpoint, source routing, linkage, Daily calculation/endpoint/UI,
Corn stock/completion/weights and Export bridge checks passed (11 commands).
Initial Daily failures were local missing PHP ctype configuration; the provided
runtime configuration resolved them. The MySQL-only test correctly refused to
run without its guarded disposable CI database; no real database was used.

## Remaining confirmed Corn gaps

The current purchase UI/server and Milling feed restrict Ex-Mill routing to
READY stage, while Corn uses RAW product identity. The Ex-Mill native form and
completion validator also require positive bags, blocking loose-bulk Corn.
The own-mill Corn weight/bridge tests do not validate this outside-mill path.
Do not mark the outside-mill native purchase/load/bill flow complete.

Other inherited gates remain: broad management-report acceptance, exact source
and intercompany reconciliation, original zero-production/completed-shipment
contradictions, remaining role/action/output variants, salary/rent handoff,
independent MySQL/full-browser migration, and formal security review.

The local Chrome connector is not exposed in this replacement turn. No Mac UI
inspection, production financial posting, stock movement, approval, original
document replacement or live restore was performed. The Security Scan skill's
required `references/scan-prologue.md` resource cannot be read, and no scan
preflight tools are exposed. Its required ready preflight did not run; no formal
security clearance is claimed.
