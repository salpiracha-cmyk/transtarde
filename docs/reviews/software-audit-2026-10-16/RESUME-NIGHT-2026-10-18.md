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

## Released corrections and current acceptance boundary

PR332 merged as `2d7d592993db76da94576c58ab758a2de3eb25a0`. Full feature
Accounts39147165296/Milling39147165275 and exact-main
Accounts39147502603/Milling39147502622 passed. Production39147696184 and
read-only startup39147883454 passed. This is the unchanged-lifting replay fix
above, not a completed redesign of lifting amendments or outside-mill Corn.

PR333 merged as `d200630ad8bea120b4dc3b89e8104564fb28fed4`. Both Corn and
Sesame bills now honor the saved KG, MAUND or MT rate after KAT. An actual-auth
disposable request reproduced 1,000 kg at Rs100/kg being calculated as Rs2,500;
it now calculates Rs100,000. Legacy records with no unit retain MAUND behavior;
explicit unsupported units are held. No historical bill or agreed Soda price
is rewritten. The matching numeric preview and authenticated endpoint checks
are in Accounts CI. See `NONRICE-PRICE-UNIT-2026-10-18.md` for scope.

The final PR333 head `ce617f4b69bc7aa76eb7a35a9ce83edb13338e55` passed full
Accounts39147826479 and Milling39147826474. An obsolete static assertion that
required every bill to use maunds was replaced with the new actual numeric
unit tests; KAT architecture coverage remains. Exact-main
Accounts39148217137 (inventory117736331195/audit117736331376) and
Milling39148217115 (audit117736331145) passed. Production39148487334 /
job117737117581 deployed the exact merge SHA and confirmed a healthy login,
changedUserCount0 and journal-ID migration changeCount0. Final read-only
startup39148541769 passed. Authenticated read-only live smoke39148541819 /
job117737343492 passed20/20, with10bank comparisons,50active account ledgers
across3company reports,0concurrent-revision skips and0financialwrites. The
production recovery checks saw0source runs, so positive recovery/source cases
remain unproved by that live pass.
The earlier live smoke39147883583 was cancelled automatically by the newer
deployment; it is not claimed as a pass.

The existing separate Maize labour flow from PR318 remains in the combined
tree and CI: direct container loading; warehouse unloading plus bulk container
loading; or warehouse unloading, 25/50 kg paltai and separate container loading.
Rates remain explicit and initially blank; extra work is additions only. Rice
labour quantities and rates are not reused. These checks do not certify the
separate outside-mill Corn purchase/loading route.

No live test stock, supplier bill, payment, approval, original issued document
replacement, role grant or restore was performed during this continuation.
Production automated browser checks are authenticated and read-only; positive
financial cases run only in disposable test stores/databases. Local Mac Chrome
control remains unavailable in this turn. The broader gates listed above are
still open; there is no whole-application acceptance or formal security sign-off.
