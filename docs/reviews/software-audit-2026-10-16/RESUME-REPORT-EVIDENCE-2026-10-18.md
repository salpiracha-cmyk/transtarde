# Continuation checkpoint — shipment and stock-source evidence

Application release: PR341, merge79ec45bd2ed6201a8eb67e2661a18bc6f9d1fd04,
exact tree d1ea34ec6ace7fa266174fe7f1d36fcc1460a9e0. This follows the completed
PR337/338/339 application releases and PR340 checkpoint. Whole-application
acceptance remains incomplete; historical labels do not certify original sources.

## Confirmed defects and repair

The actual authenticated shipment endpoint on main6a58f70 reports Rs1000 revenue,
Rs400 stock cost and Rs600 contribution with no linked journals. Original code
checked supplier liabilities but trusted sale/stock-cost source amounts.

PR341 validates active posted source identity, legal entity, ownership, balanced
nonnegative numeric legs and accepted amounts for sale recognition and COGS.
Explicit same-source TG direct costs remain supported. Duplicate sources/posts,
stale amendments, partial reversals and active orphan costs require review;
fully reversed sale/cost pairs remain excluded. Missing revenue or stock cost
leaves totals and contribution unavailable. Screen, print and CSV retain that
state. Report reads do not repost, repair or rewrite original financial sources.

A second regression fails the old Rice Ledger label for a dated legacy receipt
without saved company/mill. The quantity observer now carries missing-scope
metadata, and the ledger labels affected product/movement statuses unverified.
Canonical quantities, default historical scope, dated balances, undated handling
and inferred-input warnings remain intact. This is not a stock reassignment or
proof of physical ownership. The original C24 Indus/TTI unloading discrepancy
remains failed pending independent source reconciliation.

See SHIPMENT-REVENUE-EVIDENCE-2026-10-18.md for the implementation boundary.
Current Sales Tax source/actual-auth/output checks also pass: canonical lots and
file-identity download deduplication already exist. The older C17 duplicate
claim is superseded, while native issued CI/PL archive integration remains open.
No uploaded original or office archive has been altered.

## Validation and release evidence

Seven related report commands passed. Ten further stock/Daily/API/UI commands
passed after the ownership-label change. The local Daily MySQL test refused to
run without its disposable database guard; no production database was used.
The guarded hosted test then passed against the actual isolated MySQL source,
including source versions, stale-file rejection and zero source writes. Native
Daily browser save/conflict/CSV/print checks also passed in the disposable app.

Final featurea79ab55a2db6fdea59a6a5275d6af8ba69f4bb87 passed full
Accounts39156947005 (audit117762009899/inventory117762009672) and
Milling39156947009 (audit117762009879). The feature and merge trees match.
Exact-main Accounts39157266600 (audit117762949939/inventory117762950115)
and Milling39157266535 (audit117762949761) passed. Deployment39157534132/job117763748938 confirmed exact79ec45b, healthy login,
changedUserCount0 and journal-ID migration changeCount0. Startup39157593556/job117763925790 passed (Masters847ms, Milling840ms,
Exports742ms). Expanded authenticated read-only live smoke39157593519/
job117763925522 passed21/21 (2.5minutes). The new shipment-evidence test checked
3companies,8rows and5source holds; all8contributions remained unavailable.
This is an outstanding original-source reconciliation result, not a completed
positive shipment-margin acceptance. The actual held-state renderer passed.

The same run compared10banks and50active ledgers across3company reports with
0concurrent-revision skips and0financialwrites. Maize three-route/blank-rate/
review delivery passed in both Milling loads. Recovery still had0source runs;
positive recovery remains unproved by that live pass. The runtime's local check
date crossed into19October Pakistan time; workflow timestamps are UTC.

The new read-only live test checks all3companies' shipment projections and the
actual rendered held/unavailable state. Positive financial cases remain confined
to disposable stores. Current original business mappings and cost completeness
are not inferred from an empty or held live report.

## Remaining gates

- Original stock/unloading ownership, completed-shipment/zero-production and
  product-source contradictions; current positive stock and costing reconciliation.
- Complete shipment overheads, actual TG source margin and group intercompany
  elimination; mixed historical allocations and billed/cancelled receipt correction.
- Canonical rent/salary reference matching to existing Accounts liabilities and
  payments. No duplicate disbursements or guessed legacy identities.
- Native issued-document/archive integration, approved historical document/HS/
  relationship-letter decisions, remaining master/role/action/output variants.
- Independent MySQL/full-browser migration and large offline recovery acceptance.
- Intermittent startup (earlier Masters timeout retained despite later passes)
  and formal security preflight. Scan tooling remains unavailable; no security
  clearance is claimed. Local Mac Chrome control is still not exposed here.
- Annual Zakat form and exact entry/component review remain deferred as requested.

No live financial test entry, stock movement, approval, role grant, issued-file
replacement or restore was performed in this continuation. The broader audit remains incomplete. The5held shipment sources require their
original evidence/mappings to be reconciled before contribution can be accepted.
