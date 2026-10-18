# Continuation checkpoint — protected shipment archive reads

Application release: PR343, merge78c5a5adb7bab895905ebf1f2858428c8274ce3a,
exact tree251ca900c7acd67585028267b599600f8d647930. Previous checkpoint:
RESUME-REPORT-EVIDENCE-2026-10-18.md. The wider application audit remains open.

## Completed change and boundary

Sales Tax now includes dated protected Master Shipment Documents.pdf copies from
the existing Office Agent jobs. The new GET-only reader reuses the original
Export document target policy and verifies size/SHA-256 before returning the
saved bytes. It exposes the named root master pack only. Accounts still requires
company View; Exports still requires its existing Print View. No Office Agent
credential is exposed or reused in the browser.

Archive dates display in Pakistan time; distinct job revisions remain identified
as archived packs. These are the documents available when each pack was prepared,
not certification of the current issued invoice. Standalone native CI/PL indexing,
completeness and independent original-document acceptance remain open. Neither
archive generation nor the office folder structure has changed. No original was
re-rendered, replaced or rewritten. See SHIPMENT-ARCHIVE-READ-2026-10-18.md.

Actual authenticated disposable tests cover positive downloads, distinct dated
revisions, exact company/module/Export-icon checks, GET-only behavior, missing and
altered files, unsafe paths/symlinks, corrupt metadata and unchanged original
Accounts/operations/upload/archive bytes. Existing Sales Tax source, endpoint,
selection/print/download and authentication-boundary checks pass. Source records
remain untouched; ordinary authentication/session tracking is outside the
business-source byte comparison.

## Release evidence

Final feature1510e30f7343de6774c655cf6e8943cd8c33eb34 passed full
Accounts39163222444 (audit117780717721/inventory117780717943) and
Milling39163222253 (audit117780717128). The inventory job explicitly ran and
passed the new real-auth archive test and all three Sales Tax checks, followed
by the actual guarded MySQL Daily reader and native browser reconciliation.

The feature and main merge trees match. Exact-main Accounts39163535985
(audit117781660157/inventory117781660046) and Milling39163535986
(audit117781660045) passed. Deploy39163825035/job117782502298 confirmed exact
78c5a5a and healthy production login, changedUserCount0 and journal-ID migration
changeCount0. Startup39163879238/job117782667601 passed (Masters906ms,
Milling1068ms, Exports844ms). Earlier intermittent startup failure remains an
open gate despite this pass. Live smoke39163879281/
job117782667374 passed21/21 (2.4minutes). Sales Tax returned3rows containing
35dated archived packs; one sampled pack returned200/PDF after source-byte
verification. This does not certify the contents or availability of all35packs.
The shipment report still had5source holds across8rows/3companies, with all
8contributions unavailable. Ten banks and50active ledgers across3companies
reconciled with0concurrent-revision skips and0financialwrites. Recovery still
had0production source runs, so positive actual-recovery acceptance remains open.
Maize three-route/blank-rate/review delivery passed in both Milling loads. The
run's Pakistan check date was19October; workflow timestamps are18October UTC.

## Live inspection and remaining gates

The newly available Browser connector was verified as Chrome. Transtrade opened
at sign-in, with no existing signed-in app tab. A later open extension panel
blocked further browser inspection. The original tab was left for sign-in; no
credential, alternative browser or authentication bypass was used. The five live
shipment source holds from PR341 have not yet been inspected against originals.

- Reconcile those five sources and complete positive shipment costing, overheads,
  TG margin/intercompany elimination and allocation acceptance. A passing
  unavailable-state smoke test is not a completed margin reconciliation.
- C24 original Indus/TTI unloading ownership, positive stock/recovery/cost sources
  and original completed-shipment/production contradictions remain open. Missing
  ownership labels preserve legacy quantities and do not verify their ownership.
- R08 rent/salary references must match actual canonical Accounts liabilities and
  payments; no guessed legacy mapping or duplicate disbursement.
- Standalone issued CI/PL indexing, approved historical HS/relationship-letter
  decisions, original output and remaining master/role/action variants remain open.
- Independent full-browser/MySQL migration, large offline recovery, intermittent
  startup and the unavailable formal security preflight remain acceptance gates.
- Annual Zakat and its exact entry/component review remain explicitly deferred.

No live financial test entry, stock movement, approval, role grant, original-file
replacement or restore was performed. User/source-owned reconciliation gates are
not marked passed because an isolated fixture or held-state live report passes.
