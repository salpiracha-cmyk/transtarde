# Continuation checkpoint — historical shipment opening classification

Application release: PR345, merge6575df7843f1a1094a0a0eea2adbcf4772a5722e,
exact tree038083d3f15683a92a238c6279a1151655898c08. Previous checkpoint:
RESUME-ARCHIVE-READ-2026-10-18.md. The broader application audit remains open.

## Actual Chrome finding and repair

Chrome was signed in as Salman / Super Admin. The Accounts Shipment Contribution
screen showed five carry-forward Pakistan candidate warnings: sale source not
recognized/posted. These linked opening receivables are deliberately produced by
the carry-forward workflow without recreating historical sales or COGS. Original
main7dece05 fails an actual-auth valid-opening regression with the same warning.

PR345 verifies the saved published carry-forward record, exact candidate pointer,
company, source/contract/lot, original invoice currency/native balance and active
balanced opening journal. A shared original opening requires its explicit saved
allocation, line, currency/native capacity and proportional book value. Valid
historical openings receive a screen/print/CSV label, rather than being treated
as failed current sales. Corrupt, foreign, reversed or mismatched sources remain
held; a historical flag alone cannot exempt a current sale/cost posting.

Opening balances never become new revenue or stock costs. Historical contribution
remains unavailable, including mixed historical/current rows. Current posted
supplier costs stay visible; ordinary sale/COGS/liability evidence is preserved.
See HISTORICAL-SHIPMENT-REPORT-2026-10-19.md for the acceptance boundary.

Seven related local commands passed, including actual carry-forward registration
and settlement, report evidence/corruption tests and screen/print/CSV checks.
The old-code regression worktree was removed after reproducing the defect.

## Release evidence

Feature6b07a1930200892a8607fbb8a8272df66d3ad303 passed full
Accounts39165381720 (audit117787113098/inventory117787112964) and
Milling39165381718 (audit117787112805). Both Accounts jobs explicitly ran the
actual carry-forward registration/settlement report checks. The inventory job
also passed the expanded opening/source evidence, report outputs and actual
guarded MySQL Daily reader with zero source writes. Feature and main merge trees
match. Exact-main Accounts39165662194
(audit117787956324/inventory117787956429) and Milling39165662230
(audit117787956427) passed. Deploy39165846010/job117788509229 confirmed exact
6575df7 and healthy production login, changedUserCount0 and journal-ID migration
changeCount0. Startup39165978346/job117788891129 passed: Masters1204ms,
Milling1054ms, Exports866ms; usable Accounts786ms after sign-in. The earlier
intermittent startup failure remains an open gate. Live smoke39165978366/
job117788891514 passed21/21 (2.5minutes). Production verified5historical openings
across5rows; source holds fell from5to0. Across3companies/8rows, all8contributions
remain unavailable because complete original revenue/cost is not established.
The actual historical label renderer passed. This resolves the false sale-status
warnings, not the original commercial amounts or lifetime margin.

The same run listed35dated archive packs and sampled a successful200/PDF download;
it compared10banks and50active ledgers across3companies with0concurrent-revision
skips and0financialwrites. Maize three-route/blank-rate/review delivery passed in
both Milling loads. Recovery still had0production source runs, so positive actual
recovery remains unproved. The run's Pakistan check date was19October; workflow
timestamps are18October UTC.

## Remaining inspection and acceptance

After the live warnings were read, a separate extension panel blocked Chrome
control again when opening Carry-forward Shipments. Sign-in was successfully
restored; do not repeat the previous checkpoint's claim that login is still
missing. The original carry-forward detail forms and C24 receipt/unloading
records have not yet been inspected in this continuation. No browser substitute,
authentication bypass or production test posting was attempted.

- The five false source-status holds are resolved by verified historical
  classification. This is not independent verification of the original
  invoice, historical sales, cost completeness or lifetime margin.
- Complete shipment overheads, actual TG margin/intercompany elimination and
  positive source/allocation acceptance.
- C24 original Indus/TTI unloading ownership, positive stock/actual recovery/cost
  and completed-shipment/production contradictions remain open. Existing missing
  ownership labels do not validate or reassign quantities.
- R08 canonical Accounts rent/salary/advance references and actual settlements
  still need original matching; no guessed mappings or duplicate payments.
- Standalone issued CI/PL indexing, full original-output acceptance and approved
  historical HS/relationship-letter decisions remain open. PR343 archive packs
  retain their original date and are not certified current invoices.
- Remaining master/role/action/output variants, independent full-browser/MySQL
  migration and large offline recovery remain acceptance gates.
- Intermittent startup remains open despite later passes. Formal security scan
  preflight tools are still unavailable; no formal clearance is claimed.
- Annual Zakat and exact accounting-entry/component review remain deferred.

No live financial, opening-balance, stock, approval, role, original-document or
restore mutation was performed. Historical accounting records are unchanged.
