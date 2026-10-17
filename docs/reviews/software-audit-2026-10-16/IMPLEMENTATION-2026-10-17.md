# Approved implementation and acceptance register — 17 October 2026

Owner has authorised implementation of the group reports and costing requirements, completion of the outstanding screen/action review and repairs. This register records implementation, not a clean-application or migration sign-off. Production figures still require real mill entry.

## Owner clarification: Maize labour

Maize labour bills and agreed rates are separate from Rice. The rate setup and charging basis are deferred for discussion with Salman. Never populate a Maize bill using a Rice agreement. Reminder created for 18 October 2026, 14:00 Asia/Karachi. Existing Rice rate identities/history remain intact.

## Implementation batches

| Area | Required behaviour | Current evidence |
|---|---|---|
| Corn purchase | Maund Soda (40 kg), Mill receipt, commission, supplier bill/payable/payment reuse canonical purchase flow | Source trace in progress; no new duplicate purchase ledger |
| Corn bulk loading | No production; zero bags; positive actual net per container; explicit weighbridge basis and packing/vehicle deductions | Working-branch implementation and controlled tests; not released |
| Corn bagged loading | Existing bagged shipment workflow; actual commodity net weight retained | Working-branch implementation; full browser scenario outstanding |
| Corn customs | Preliminary plan retained; explicit Mill loading completion switches revised customs and final outputs to actual net | Controlled bridge/document tests; all issued document/archive paths still under review |
| Rice/other commodity regression | Preserve existing bag/production behaviour and permissions | Focused bridge/export/container suites pass locally; full CI outstanding |
| Labour commodity scope | Rice and Maize volumes/rates never mix | Mixed-company/mill/commodity fixture passes; live verification outstanding |
| Arrival location | Receipt must belong to Soda location; never silently default an Indus Soda to TTI | New-save guard in working branch; historical source repair/cleanup not performed |
| Test arrivals | Remove only PH-00001/2/3 and verified test dependencies with backup and audit | Authorised; impact trace/cleanup outstanding |
| Zakat | Shared Accounts/Directors annual 1 Ramadan assessment, section completion, source detail, TTI payment expense and signed carry-forward | Approved; implementation outstanding |
| Cashflow | Shared group net trading position, actual/provisional/missing lines, no financial assets/shares, GST at end | Approved; implementation outstanding |
| Daily report | Date-selectable group report, contracts, Soda balances, stocks, receivables/payables, banks and raw LONG/SHORT | Approved; implementation outstanding |
| Costing / Watak | Editable versioned Rice/Corn scenarios, B2/B3/CSR, actual comparison, max affordable purchase rate | Existing actual recovery/cost engines located; management workbench outstanding |
| TG shipment P&L | Pakistan realization plus matched TG–Pakistan margin at linked advice FX, expenses once | Approved; implementation outstanding |
| Credit advice sales | Advice-date realized-sales reporting reconciled with existing recognition/receivable journals | Source traced; repair/report validation outstanding |
| Whole-app audit | Every screen/action traced to sources, accounting/stock and output, each marked passed/failed/blocked/not tested | Existing coverage register retained; not complete |

## Binding business calculations

- Whole group TTI/BRM/TG; legal company books remain separate. Eliminate intercompany double counting. Historical dates/FX and saved snapshots remain reproducible.
- Property entered value excluding instalments is the premium **on top of** amounts actually paid: PKR 7m premium + PKR 3m paid = PKR 10m assessed property value. Do not count the same property advance twice.
- Zakat includes group eligible net financial wealth, trading assets/properties, shares and gold from all three wives at entered valuations. Exclude personal/operating home, office, cars and motorcycles. Annual date is 1 Ramadan, Gregorian date explicitly confirmed. Final obligation and actual payment expense belong to TTI. Assessment and payment must not both expense the same amount. Carry over both underpayment and excess payment.
- Shared sections save only on explicit action. Optimistic version conflicts protect another user's changes and unfinished entry. Changes to report rows do not silently rewrite source journals.
- Cashflow includes valued trading stock/by-products, banks/cash, receivables and advances/excess paid less all incurred payables, estimated when unbilled. Missing actual invoices must not duplicate GRNI or existing bills. GST receivable appears last. Immediate cash is separately identified. Properties/shares excluded.
- Raw LONG/SHORT = physical usable raw on hand + undelivered purchases − required raw for outstanding sales after usable finished stock. Rice recovery is editable per spec; 25% silky indicative 78–80%, 5% indicative 58–62%. Corn requires no milling recovery. Never count receipts again as undelivered purchases.
- Effective realization FX = FX × (1 − 0.0125 − 0.001). WHT and bank deductions are identified separately. Freight realization receives the same treatment. Do not subtract 0.0135 from the numeric FX rate or deduct twice.
- Costing separates money currencies/units; waste affects yield without an invented sale credit. Rice output/by-product/waste proportions reconcile to 100%. Actual unavailable remains unavailable.
- TG internal shipment margin = (TG price − Pakistan price) × matched MT × original Pakistan credit-advice FX. Example USD 20 × 100 MT × 280 = PKR 560,000 internal margin, not another bank receipt. Partial receipts retain their individual FX; no duplicate group revenue or expenses.

## Release gate

No production data writes or migration sign-off have been made by this batch. Full hosted suites, deployment identity, read-only live smoke and actual Mac Chrome workflow/visual verification remain required. Discretionary fields/master removal still require Salman's decision. Deferred Maize labour rates must stay unconfigured rather than silently inherit Rice.


## Resumed Corn validation after interrupted session

- Focused local checks pass: commodity identity/packing weights; server reconciliation and new/corrected Corn basis guard; stock net deduction; commodity-scoped labour; lost completion acknowledgement/retry; own and external bridge contributions; corrected post-completion weight totals; inventory reconciliation (103 assertions); runtime asset delivery and existing Export/Mill bridge.
- Corn completion now retries the existing pending save without another audit event or false success. External contributions precede completion aggregation; later corrected weights refresh the actual total.
- These checks are disposable/controlled. Full hosted suites, document browser tests and production deployment remain required. Local jsdom/Playwright are absent; hosted suites install them.
- Chrome connected on 17 October but Transtrade redirected to login after inactivity. The login tab was left ready; live application acceptance awaits sign-in.
- New shared group-report files are unfinished drafts, excluded from the Corn release. They require source, permission, concurrency and accounting validation before integration. Daily report, automatic estimates, actual-versus-costing, source-linked TG profit and canonical Zakat payments are not completed by this batch.
- Bulk Corn through external-mill UI and all archive/revision outputs remain acceptance gaps; a bridge fixture alone does not certify the full route. No real financial records or test Pohanch entries were altered.


## Live continuation at 14:45 PKT

Chrome signed-in Console, Milling, bag orders and arrival search were inspected. TTI Rice Mills now shows only GHAZAL5508; the original GHAZAL artwork image `50 KGS.jpeg` loads visibly, without an invented approval claim. This closes the previously pending positive UI checks for PR281 behaviour; it is not a transfer destination receipt test.

Date23September arrival search still returns exactly PH-00001/ABC-123/Soda26002, PH-00002/ABC-345/Soda26001, PH-00003/TK-123/Soda26002. Search offers Reprint only. No reprint, save or deletion was performed. Controlled cleanup and verified dependencies remain outstanding.

Corn PR283 (`implement/corn-actual-weights-20261017`) is running full hosted QA. The first full attempt caught two concrete integration failures: empty previous loading payload decoded as invalid JSON, and older isolated VM fixtures omitted the now-required commodity helper. Both corrected locally without weakening assertions. Existing ownership tests additionally prove Corn instruction fields can save while Exports cannot alter Mill physical weights. No deployment claimed.

Group foundation PR284 is a separate unfinished draft stacked on the Corn branch. Local formula and actual PHP endpoint tests pass for separate atomic report storage, zero-write reads, section conflicts, Create/Edit distinctions, existing entity restrictions from either portal, CSRF, duplicate source IDs and preserving original Accounts bytes. Remaining automatic daily/estimated/source-linked management workflows and UI acceptance are not complete.

Additional source finding: Used Bags still aggregates arrivals/manual history across mills, invents today's date for undated receipts and writes unscoped manual records. Its legacy sale reference writes an operational cash credit rather than a canonical receipt. This is uncorrected and must be treated as unverified operational history, not confirmed cash or stock reconciliation.


## Corn released; Used Bags prepared

PR283 final head9add15660edad18699ffd5ffadbabc28001cea5a passed full Accounts39022148194 and Milling/Exports39022148190. Merged49d4a9318751212f20885bd6f5571c1811aec591. Main QA passed; production run39022638272/job117367330012 succeeded with the exact DEPLOYED_SHA and healthy login. Mac Chrome Exports opens without errors and includes the authenticated commodity helper. Opening then closing an unsaved contract left the three original contracts unchanged; Next was deliberately not used because it saves a contract draft. Actual live positive Corn loading/documents remains unverified; controlled suites cover it. Smoke39022679246 is still running at this checkpoint.

PR285 final head52ee151f172678d3020d4d3fea552960a5b56d61 passed both full hosted suites39022649875/39022649868. Its Used Bags correction scopes dated/verified movements, retains unassigned history with warnings, records company/mill on new manual entries and removes new fake legacy cash credits for sale references. It does not create the canonical Accounts sale receipt. Deployment/live verification must be recorded separately.

The shared report draft additionally preserves the original book figure/provenance when the reviewed report amount changes. Original source details are resolved on the server; forged book IDs/provenance are rejected. Formula and direct endpoint tests pass locally. This does not finish the management workflows or justify deploying PR284.


## Further live acceptance and exact remaining scope

- Corn production smoke39022679246/job117367448838 passed18/18, including12 Masters round trips and50 account-ledger comparisons across3 company reports with0 skipped concurrent-revision cases.
- Mac Chrome Maize AUTO-FILL refuses to infer a Rice agreement and asks for a separate commodity/company/mill/period agreement. Rice selection restored; no bill saved. Maize rate/bill design is still deferred.
- Used Bags PR285 mergedf96d0d17762e14865dada9e506e62134dfd39c0e. Deployment39023086699/job117368637535 confirmed that exact SHA, backup and healthy login. Mac Chrome opens the repaired screen. The displayed1300 arrival bags are400+400+500 from PH-00001/2/3, still user-identified test data, not accepted opening/physical stock. Their current metadata passes explicit company/location checks; a missing-location fallback alone cannot explain their TTI grouping.
- Live acceptance caught stale static cash guidance. PR286 corrects that wording; mergedf1192bd8a130f2028bcc2e496d5be1d5fd42af5b after both hosted suites passed39023221631/39023221589. Deployment/fresh text acceptance still pending at this checkpoint.
- Actual TTI Accounts search returned no matching previous record for each PH-00001/2/3. This is limited to search coverage and is not proof of zero dependencies. No Delete, reversal, payment, approval or reprint was performed.
- Universal search wrongly gained Add Business Party because its placeholder contains party. PR287 adds the existing master-ignore attribute to the search input. Its CI/live acceptance is pending. Real party selectors are untouched.
- Source review confirms the outside-mill Corn route is not complete: the existing Ex-Mill selector still expects READY/EX_MILL purchases and positive bag counts. The own/direct-loading repair must not be described as full acceptance of every outside-mill bulk-Corn route.
- Latest group foundation UI now has a real DOM fixture covering typing with zero writes, row draft retention, failed-save conflicts, successful explicit saves and context restoration after failed report changes. Both prior/full foundation suites passed; latest UI-expanded full suites39023096322/39023096474 also passed. PR284 remains an undeployed draft, without the final Daily report, automated missing-cost estimates, actual comparison, fully source-linked TG P&L, annual completeness controls or complete Zakat payment/allocation reconciliation.

Next implementation priorities remain: verified test-arrival cleanup with dependency/backup/restore checks; canonical Mill bill-to-Accounts liabilities/payment/reversal; acknowledged destination bag transfers; remaining Corn routes/documents; then complete and connect the management reports. Do not invent migration openings or silently rewrite issued documents, real journals, staff grants or historical stock locations. Do not declare the whole-application review or migration complete.


Additional live Accounts finding: the Home Recent Transactions section renders278 rows and repeats displayed public Post IDs00332,00145 and00112. This is a display/source-grouping and density review, not proof of duplicate journal money. Do not delete or sum those postings without tracing linked journals. The search dialog was closed without changes after tracing test references.


## Final verified continuation checkpoint

- PR285's read-only production smoke39023123803/job117368746112 passed18/18. PR286 wording deployed asf1192bd8a130f2028bcc2e496d5be1d5fd42af5b in39023679267/job117370343441, with exact SHA and healthy login confirmed. Fresh Mac Chrome shows the corrected stock-only sale/Accounts receipt guidance.
- PR287 final headc04faac881242a74f6f894e3381f1fb5a21f655c passed Accounts39023508026 and Milling/Exports39023508018. Merged047c9dc253aefc7a8f7f148e4dc2c7bfef66ef0d. Both main suites passed39023737844/39023737866. Deployment39023946620/job117371115981 succeeded. Fresh Chrome verifies the universal search has master-ignore, finds the expected no-match result for PH-00002, shows0 Add Business Party buttons, and closes back to Accounts. No data was added, posted, approved, reversed, deleted or reprinted by these live checks.
- PR284 remains a draft. Latest functional head's full suites passed39023096322/39023096474, including the added real DOM conflict/draft test. Its report source code and adjustment provenance are saved in branchimplement/shared-group-reports-20261017. It must not be merged or called complete while the management-source and annual-accounting requirements listed above remain open.
- Current local continuation branch isimplement/shared-group-reports-20261017. The separate Used Bags and Accounts search changes are already on main; integrate that current main before the report feature's eventual release. No migration opening was inserted. The three test Pohanch records remain; their1300 bags and119500kg must not be accepted as physical stock.

The whole application review, automatic management reports and migration rehearsal remain unfinished. The next worker must continue the unresolved source and workflow work, not repeat the completed releases or treat passing suites as whole-application sign-off.
