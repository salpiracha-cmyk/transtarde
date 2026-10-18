# Latest continuation checkpoint — lifting and reference scope

The latest application main is `b97d02ea6bd41f5ea7f207fb466a8f2b41f07661`,
exact tree `c5b1cef52165e202964b88274f74fbbc291ec7bf`. The whole application
review remains incomplete. Earlier entries below are chronological evidence;
read this checkpoint and CURRENT_COVERAGE.csv before treating an old gap as current.

## PR337 — immutable unbilled lifting corrections and receipt visibility

PR337 merged as `aeb2e5e91cebbb113d550fc51af262b57d7d4819`, tree
`8f26b0348427bab46d0f5eb328120ac7ace7cbe2`. Unbilled Rice/Corn corrections
require scoped Edit permission, a reason and the current journal identity. They
preserve the original, add a linked reversal/replacement at the accepted rate,
and retain source history. Billed corrections and cancellation remain held.
Native Save has a no-write green accounting preview; Confirm checks the reviewed
amount fingerprint under the store lock. Cancel and unchanged replay do not write.

The legacy posted Ex-Mill receipt status was omitted by Accounts reports. The
Rs10,000 actual-auth fixture reproduces that omission on old code. The shared
read projection now includes the exact legacy receipt type without migrating
history, and excludes drafts and unrelated pending sources. New journal status
is Posted with a separate Pending bill dimension. Inventory, GRNI and supplier
balances reconcile through correction and final billing in disposable fixtures.
See LIFTING-CORRECTION-2026-10-18.md for scope and handoff limitations.

Final feature `ad8d607c51660a77bf3581b3e4c7a31d071aacfb` passed Accounts
39153343162 (audit117751350551/inventory117751350400) and Milling39153343168
(audit117751350295). Exact-main Accounts39153605023
(audit117752123304/inventory117752123091) and Milling39153605203
(audit117752123422) passed. Deployment39153862436/job117752886320 confirmed the
exact merge SHA, healthy login, changedUserCount0 and journal-ID changeCount0.

Authenticated read-only smoke39153990547/job117753280878 passed20/20:
10bank comparisons,50active ledgers across3company reports,0concurrent-revision
skips and0financialwrites. Recovery read0source runs, so positive recovery
acceptance remains open. Startup39153990541/job117753280653 FAILED at the
read-only Masters shell with TimeoutError. It returned successful login/Accounts
requests and a200 Masters response, but did not observe the visible Masters view.
The separate smoke passed its Masters round trips. The profiler failure is
retained; neither its cause nor an intermittent startup repair is claimed.

## PR338 — selected company/mill reference estimates

PR338 merged as `2028774d67c16cb0dee61e2a5f85c192b234b343`, tree
`12ed83b24278cc9f081fb8d21081edebb98e2a23`.
Rent and same-person/month salary advance estimates no longer include another
company or mill's reported references. Existing legacy unscoped fallback remains;
no missing source ownership is guessed or rewritten. Actual render/save tests
first fail on the preceding code (Rs350 versus correct Rs850 rent remainder),
then pass, including no-write reads and explicit saves preserving other history.
Canonical salary/rent master, liability and payment reconciliation remains R08.
See MILL-REFERENCE-SCOPE-2026-10-18.md.

Feature `58c0becb1b3b4f67143e376e227c44f09b71f53a` passed Accounts39153666697
(audit117752311717/inventory117752311846) and Milling39153666701
(audit117752311752). Exact-main Accounts39154027220
(audit117753391014/inventory117753391125) and Milling39154027239
(audit117753390955) passed. Deployment39154286642/job117754172273 confirmed exact2028774, healthy login,
changedUserCount0 and journal-ID changeCount0. Startup39154348833/job117754359815
passed (Masters1156ms, Milling899ms, Exports1089ms), without erasing the earlier
Masters timeout. Authenticated read-only smoke39154348821/job117754359422 passed
20/20,10bank comparisons,50ledgers/3companies,0concurrent-revision skips and
0financialwrites. Recovery saw0source runs. Neither smoke checked the blocked
Maize/review runtime or missing Export office hook; their delivery failure was
found in the startup resource evidence and is addressed separately by PR339.

## PR339 — actual Maize/review/office-hook runtime delivery

PR339 merged as `b97d02ea6bd41f5ea7f207fb466a8f2b41f07661`, tree
`c5b1cef52165e202964b88274f74fbbc291ec7bf`. Maize and lifting-review scripts
were requested from the intentionally denied milling folder. The Export office
hook relative URL pointed at an absent root file. Prior shell smoke passes did
not assert these runtimes and therefore did not certify their delivery.

The authenticated module now embeds the existing scripts at their existing
execution positions, as for Rice Ledger. Folder protection and permissions are
unchanged; required missing assets return503. The actual module-renderer test
fails before repair and passes afterward, with denied-user, missing-asset and
assembled-script checks. Native Maize/Corn form regressions pass. Live smoke now
checks installed office hooks, the lifting-review function and actual Maize
route visibility with blank rates, without Save or financial action.

Final feature531b4bfffe835f991e6f928ec33fae5c18741afc passed Accounts39154736432
(audit117755508479/inventory117755508346) and Milling39154736447
(audit117755508240). Exact-main Accounts39154981721
(audit117756231644/inventory117756231961) and Milling39154981666
(audit117756231161) passed. Deployment39155244119/job117757012123 confirmed exactb97d02e, healthy login,
changedUserCount0 and journal-ID changeCount0. Read-only startup39155329309/
job117757262183 passed. Expanded live smoke39155329141/job117757261502
passed20/20 (4.1minutes). LIVE_MAIZE_REVIEW_DELIVERY_OK appeared in both Milling
loads: all3actual routes, initially blank agreed rate and review function. The
Export installed-office-hook assertion also passed. Module POST paths were[],
10bank comparisons and50ledgers across3company reports matched with0concurrent
revision skips and0financialwrites. Recovery read0source runs. Startup measured
Masters1181ms, Milling987ms and Exports800ms. This certifies the tested read-only
runtime delivery, not a live labour bill, correction or office-agent write. See MODULE-SCRIPT-DELIVERY-2026-10-18.md.

## Open acceptance boundaries

Separate Maize labour PR318 implemented three routes, separate initially blank
agreed rates,25/50kg paltai and additions only. Rice work/rates are not reused.
The subsequent PR339 delivery repair is required because source-file fixtures
and module-shell smoke had missed blocked scripts. Do not infer usable live
Maize controls from earlier shell checks; use the new route/form assertions.

Open: current positive stock/source and intercompany reconciliation; mixed
historical allocations; billed/cancelled receipt corrections; canonical rent/salary
reference reconciliation; issued-document/archive contradictions and remaining
master/role/action/output variants; independent MySQL/full-browser migration;
intermittent startup and formal security preflight. Local Mac Chrome control is
not exposed in this chat. No Mac visual acceptance is claimed here. Mandatory
security preflight could not run because its prologue/tooling is unavailable;
ordinary functional checks are not formal security clearance.

No production financial posting, stock test, payment, approval, original issued
file replacement, role grant or restore was used. Positive cases are disposable;
production browser checks are authenticated and read-only.

---

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


## Native outside-mill Corn follow-up — PR335

PR335 merged as `9e3dc6c98ca2053ed8901eb2cd2eb439a683946e`, tree
`67fdfe5966343e6078276ec46a7bfbb05e542342`. This supersedes the route/zero-bag
failure described above for the tested native Corn cases. It does not supersede
the broader source, correction, mixed-allocation, migration or security gates.
See `OUTSIDE-CORN-2026-10-18.md` for the full acceptance boundary.

Accounts accepts the explicit Ex-Mill route for RAW Corn; Milling and Export
instructions retain Corn identity and loose/packed packing. Native entry checks
net/packed/vehicle weight bases and deductions. Loose bulk has zero bags; packed
Corn and Rice still require bags. A new Corn liability requires its committed
Export allocation and correct company, product, location and packing, within
instruction container/quantity and Soda limits. A retry acknowledges original
journal bytes; materially changed posted Corn liftings are held for Accounts
correction. Corn bills use their selected saved container net weights with the
approved KAT and agreed rate unit, without rewriting original journals.

The actual-auth test fails on original main aa13422 at Corn Ex-Mill creation;
the actual module bridge independently loses Corn identity on that old tree.
The corrected disposable test covers Exports instruction save, native Milling
link, packed/loose loading, source/weight/company/quantity denials, shared loading
save/completion, combined bill, Corn KAT, duplicates and original-journal
preservation. Actual Accounts/Milling form functions run in JSDOM, including
conditional inputs and explicit Save. Eighteen related local commands passed;
focused API/form checks passed after final replay/completion enhancements.

Final feature head `8d87fcd7ee01e2e286215964133344f1d94019b7` passed full
Accounts39150597667 (audit117743268898/inventory117743269150) and
Milling39150597653 (audit117743268678). An earlier CI run failed because the new
JSDOM test was placed before its dependency installation. It now runs in the
existing browser job with JSDOM installed; no assertion was removed or weakened.
Final feature and merged-main trees match exactly.

Exact-main Accounts39150892699 (audit117744135147/inventory117744135197)
and Milling39150892757 (audit117744135240) passed. Deployment39151077320
(job117744671383) deployed exact9e3dc6c and confirmed healthy login,
changedUserCount0 and journal-ID migration changeCount0. Read-only
startup39151238590 passed. Authenticated read-only live smoke39151238583 / job117745148802 passed20/20,
with10bank comparisons,50active account ledgers across3company reports,
0concurrent-revision skips and0financialwrites. The production recovery check
still saw0source runs; it does not establish positive production/recovery
acceptance. No positive Corn liability/bill was created in production. No production
stock, supplier bill, payment, approval, issued document or role was changed by
positive tests. This turn has no connected Mac Chrome tools; local-Mac UI
inspection is not claimed. Other original report/intercompany/source acceptance,
rent/salary reference reconciliation, full migration and formal security gates
remain open. The correction workflow for a changed posted Corn lifting remains
unfinished; holding the change is not completion of that workflow.
