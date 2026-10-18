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


## Live test-arrival withdrawal completed, 17 October 2026

PR288 passed both full hosted suites39025713924/39025713935 and merged48b8de23ffeb44a596da691e77f11c6e829c34b1. Both main suites39025944944/39025944896 passed. Deployment39026098325/job117377267096 verified that SHA and healthy login. The destructive read-time QA cleanup was removed from commodity lookup; repeated, denied and empty lookup fixtures preserve canonical books.

Read-only production traces39025305464/job117375014100,39025399840 and39026325143/job117377913264 resolved all three receipt identities, their three arrival-list rows, and six stale outbox acknowledgements. The original Sodas26001/26002 still belong to Indus Rice Mill. None of the acknowledged old AUTO Post IDs exists in current Accounts; no current journal, bill or payment dependency matched. Three acknowledgements use legacy TTI POHANCH|PH-number keys and three use stable PHB keys.

The first controlled withdrawal39026232114/job117377647829 refused before writing because its initial exact-source guard did not recognize the three legacy keys. A fresh trace confirmed both store hashes unchanged. PR289 adds only those exact TTI legacy keys, preserving BRM records with the same public number. Full PR suites39026400430/39026400373 and main suites39026654887/39026654896 passed. Mergedd78aa5aa61eadfb805a611d086f35c6225a3d158 deployed in39026838593/job117379379944 with exact SHA and healthy login.

Controlled withdrawal39026907691/job117379600725 succeeded: exactly3 tt30slips,3 tt30queue and6 tt39accountsoutbox rows were archived, removing119500kg/1300 test bags. Protected before-image and removed rows are retained in the server private arrival-recovery/withdrawal-20261017.json. The actual CLI recovery is tested to restore the byte-exact before-image only while the post-withdrawal operational snapshot is unchanged; it refuses to overwrite later work. Original Sodas and all Accounts bytes are unchanged. Accounts SHA256 remains23ef49b98550dcb1e010930f3878a4398944be35c071c0bca8b167302b6afffc; post-withdrawal operations SHA256b8651798522d79b43a3d428453b6428bcc4c12e0b4d13b7bcbda6cd68c7fde0e. Post-withdrawal dependency trace returns no references or financial links. Exact source guards prevent stale caches recreating or posting these test arrivals; new public numbering reserves PH-00001/2/3.

Fresh Mac Chrome, signed in as Salman, selected TTI Rice Mills:23September Pohanch search says No matching Pohanch found; Used Bags shows0 arrival,0 outside and0 total, with no movements. No reprint, source save, receipt, liability, payment, approval or opening entry was created by this UI check. This removes test quantities, not a certification of real physical stock. Opening quantities still await the mill's actual entry.

Post-deployment authenticated smoke39026878609/job117379506449 passed18/18, including50 ledger comparisons across3 company reports,0 concurrent-revision skips and0 financial writes.

## Canonical Mill liabilities — active unfinished draft

PR290/branchimplement/mill-liabilities-20261017 is a DRAFT and has not been deployed. Local worktree mill-liabilities-1017. The initial remote endpoint/UI head353ab80825090859dce0f57737553e704808bb4e failed full hosted suites only on the old exact Expenses menu assertion (two expected actions versus the newly requested Mill Bill Review). Its real DOM Mill review fixture passed. The menu expectation has been updated to retain all three intended actions. Later local changes require a new remote checkpoint and full rerun.

Implemented draft: committed-source and active company/mill/payee validation for Rice labour, KE and reprocessing; source/master fingerprint; immutable original snapshot; canonical balanced5200 expense with2190 labour or2140 supplier liability; supplier-bill identity/allocation; explicit Accounts review; idempotent request replay including replacements; linked supplier payment launcher; unpaid reversals/replacements preserving original journals. Amounts/accounts are resolved by server, not client. Maize labour is blocked and remains deferred. Reported reprocessing payment references require separate reconciliation before posting. Paid bills and bills included in an approved production cost refuse reversal until their downstream records are reconciled. Generic journal corrections cannot bypass the controlled source route.

Actual PHP endpoint fixture now exercises the existing supplier payment controller: partial400 payment on1200 liability leaves800; full settlement clears it; retry creates no duplicate; expense remains1200. Company, icon, location, CSRF and stale-request denials preserve bytes. A missing operational source remains visible through its original posting/snapshot. Unit rates retain precision. Reprocessing quantities, raw returns, rates and extras reconcile server-side. Labour work/rates are visible read-only in review.

New draft Mill-only status endpoint returns only the authorised location/source's verified Post ID, booked amount, remaining balance and source mismatch; it grants no Accounts posting authority. The Mill screen reads on explicit open/refresh and updates only history/status, preserving active form inputs. Latest status DOM fixture still needs hosted execution.

Costing source review found conversion costs could spread between mills inside a company. Draft now retains historical group identities but allocates each bill to that mill's actual input, reconciles rounded group allocations exactly to the bill, retains the posted snapshot while a source is amended/missing, excludes reversed source liabilities, deduplicates KE against the same utility payment, and holds unknown locations/unresolved postings. Actual old approved snapshots and journals are not overwritten. Dedicated controlled tests pass, as do existing actual-input/recovery cost tests with explicitly scoped fixtures. New physical source/canonical postings have not been made in production.

Before PR290 can ship: finish full hosted endpoint/DOM/regression validation, verify stock/cost correction holds and source permissions, inspect all print/export and acknowledgement paths, and conduct fresh Mac Chrome acceptance after deployment. General processing, operational salary/rent reference reconciliation and acknowledged bag transfers remain separate unfinished gates. Do not describe this draft as all Mill-to-Accounts work complete.

PR284 group reports remains an undeployed draft. Outside-mill bulk Corn, full final/archive document acceptance, automatic source-reconciled management reports, remaining whole-app screen/action audit and migration rehearsal remain unfinished. Maize labour rate and bill design is still deferred for discussion; never infer its rates from Rice.


### Mill source save and payment confirmation — continuation 17 October

PR 290 remains a draft; these changes are not deployed. The previous full suites passed at 4e0f41581204b435e46dd85832fe9cbffe3e8ef6. Follow-up changes now require both suites again.

- Supplier payment now holds a source read lock before the Accounts write lock, verifies the current posted liability and exact preserved operational source, and refuses changed/missing sources. The actual endpoint fixture checks refusal without financial writes, then partial/full payment and byte-identical replay.
- Fixed native KE, Rice labour and reprocessing Save actions which previously only staged browser collections before resetting their forms. The explicit Save now reads the current collection, appends one stable record with its current version, awaits the exact server acknowledgement, and updates only that committed collection. It never flushes another form's pending work. A concurrent update causes a review/retry hold, never an overwrite. Generated LB/RPB references are assigned against the latest saved sequence.
- A durable, user/company/mill-scoped pending identity confirms a lost acknowledgement without duplicate records. The reopened bill form offers an explicit previous-save confirmation; that recovery keeps the current draft. Save & Print prints only acknowledged source details, preserves failures, and escapes saved source text. Legacy unconsumed Accounts-feed copies are no longer appended by these three Save actions; existing history is retained.
- Review CSV and print use the visible bill-type filter, saved source dates/periods, booked and outstanding/settled amounts, Post IDs, reversal and source-change holds. Unsaved correction text is excluded. Changed sources do not offer payment.
- Local source-save, liability, cost-source and actual-controller settlement fixtures pass. New native-form DOM coverage awaits hosted CI. Operational source amendment entry, acknowledged bag transfers, outside-mill Corn, remaining processing/rent/salary reconciliation, shared reports and migration rehearsal remain unfinished; no actual opening stock has been invented.


### Explicit source amendment entry — following continuation

Implemented a separate Mill source review/amendment dialog for KE, Rice labour and reprocessing. View permissions retain read-only review; Edit permission, assigned location and any configured company grants control amendment. No rate master is changed. The source identity, company, mill and generated bill reference stay fixed; actual permitted fields and correction reason are reviewed explicitly. The server recomputes labour and reprocessing totals, retaining unit-rate precision. Reprocessing reviews the selected period's actual company-scoped arrival records, requires their unchanged review fingerprint and excludes another company's receipts.

The source amendment stores its original row, actual actor, timestamp, reason and replay identity in a server-built history. Generic collection writes can append permitted scoped records but cannot edit/delete existing sources or forge amendment history. Paid sources and approved cost sources are held. Source amendment never changes Accounts journals: a changed unpaid liability remains held for the separate authorised Accounts replacement action. The actual controller integration now performs this source amendment before exercising the canonical liability replacement and partial/full settlement.

The file and MySQL paths take the source lock first, use a nonblocking Accounts dependency read lock, and retain that lock through the source write. A concurrent Accounts action produces a retry hold without a write or opposite-order deadlock. Lost acknowledgements replay the same amendment. The form retains failed entries, a durable pending request can be confirmed after reopening, and definitive validation refusals allow correction. Cache acknowledgement updates the one committed collection only. Source printouts identify the saved source version.

Local pure, source-controller, lock-contention, original/replay, dependency, permission, scoped arrival and combined Accounts controller fixtures pass. MySQL amendment and browser dialog coverage are included in hosted QA and still require a successful full run. The preceding source-save branch passed Milling/Exports at 47b5025eb211ba56c4a07beb8be524cf8dc8817e; Accounts was stopped by isolated legacy test dependencies, now corrected locally. PR 290 remains a draft, with no deployment or real source/financial test entries.


Hosted confirmation: both full suites passed at 366ef860e3a625f2bb0477aa44cc09d0340c12ff (Accounts 39033723677; Milling/Exports 39033723714). The hosted inventory runtime explicitly passed the actual native save/retention/print DOM case, source amendment UI recovery/read-only/validation case and real MySQL amendment/lock/replay controller case. An additional disposable full-browser handoff now exercises the real authenticated Milling wrapper, failed native source save and retry, actual Accounts review and source amendment/replacement, partial/full payment and replay, original journal preservation, verified Mill outstanding balance and CSV. It awaits its own hosted full run. PR 290 remains undeployed.


### Mill handoff deployed; bag transfer continuation started

PR 290 merged as ee828bbbb828793c0ab6e74ef42eee80232a05ea. Main Accounts 39034598591 and Milling/Exports 39034598425 passed. Deploy 39034740049/job 117402195637 verified that exact deployed SHA and a healthy production login. Live read-only smoke 39034874582/job 117402560644 passed all 18 tests; startup performance 39034874645 also passed. The real authenticated disposable native source-to-liability-to-amendment-to-partial/full-payment browser flow passed before merge. No live test source bill, liability, payment or opening stock was created. Earlier draft-status paragraphs above are superseded by this release confirmation.

Continuation is in implement/acknowledged-bag-transfers-20261017 (bag-transfers-1017). The new pure bag movement kernel is a draft foundation only: explicit source PO/lot lines, scoped quantities, partial receiving, physically acknowledged source returns, immutable dispatch, actual actors and stable replay. Its local tests pass without changing purchase orders or financial records. No transfer endpoint or screen is wired yet and no new bag transfer feature has been deployed. Still required: authoritative stock adapter, original PO linkage at destination without a fabricated PO, explicit production allocation when several source lots match, file/MySQL atomic actions, destination receiving/return UI, read/print/CSV parity and a complete disposable end-to-end browser test. Existing historical ambiguous usage and undated records must remain visible; no FIFO allocation, owner, date or opening quantity may be invented.

### Bag controller and production source allocation checkpoint

PR 291 remains a draft and is not deployed. Added a source-derived, company/location-scoped bag inventory projection, actual authenticated transfer controller and file/MySQL transaction adapter. Explicit dispatch consumes the selected original PO lots; destination stock appears only on its actual partial receipt. Source return requires physical receipt and a reason. Stable request replay preserves state versions and leaves original supplier receipt counters, purchase orders and financial books unchanged. Generic operational reads/writes cannot expose or overwrite the private movement ledger.

Versioned production allocations now pass through the existing shared production writer. The server checks actual packing counts, stock review, permissions and location, builds source snapshots and records the actual actor. Unchanged packing retains its source allocation during other edits. Stale clients cannot erase the allocation marker. Explicit whole empty-bag counts can differ from fractional food-equivalent bag quantities without changing food output. Backdated entries preserve later committed bag consumption; one collection save cannot independently spend one stock review on multiple changed production runs.

Local pure movement, inventory, production-writer, real file-backed controller, financial preservation and private-response regression tests pass. Hosted QA now includes the real guarded MySQL controller case; results remain pending. Native production save acknowledgement, explicit allocation selection, transfer/receipt screens, quality quarantine, legacy source reconciliation and receipt scope history remain unfinished release gates. No live stock, physical opening or financial test posting was created.

Separately, PR 292 passed both full PR suites (Accounts 39038386003; Milling/Exports 39038386090) and merged as e10fa4954a3665974459dfcede3a52a9b5fe342c. Operational POST acknowledgements now use the same public projection as GET, preserving server-only reconciliation rows without returning them. Main QA and deployment confirmation remain pending at this checkpoint.


### Backend QA and independent Production Save release candidate

Bag backend PR 291 passed both full suites at 343a5313408366053ca75d472e802a438ca99a6e (Accounts 39038823648; Milling/Exports 39038823615). The actual guarded MySQL controller test passed partial destination receipt, physical source return, replay/version conservation, protected private ledger and unchanged original PO/financial records (runtime job 117413980881). This remains a draft, without the transfer/allocation UI, quarantine workflow or legacy receipt-scope reconciliation.

Privacy PR 292 is deployed at e10fa4954a3665974459dfcede3a52a9b5fe342c; deployment 39038874494, live read-only smoke 39038943741 and startup performance 39038943957 passed.

Production Save acknowledgement is being released independently through PR 293 so it does not wait for the unfinished bag screens. The native form now waits for an exact saved-source acknowledgement, retains failed actual inputs/focus and a stable durable request, and checks an edit's original reviewed record. Production, its bag-use mirror and actual-actor amendment evidence commit together. Source-managed history cannot be rewritten through generic collection saves; hidden reconciliation history stays private in related responses. Recovery and late acknowledgements preserve current/reopened drafts. Local source/controller/inventory and hosted native DOM/MySQL checks pass; the full real browser failure/lost-response run and complete final suites remain pending. No live test production, financial or opening records were created.


### Production acknowledgement merged; bank ledger search added to scope

PR 293 passed full Accounts 39040079352 and Milling/Exports 39040079418 at 863084a11889228a5ebf73f36e2cec4c0bf663e2, then merged as 557880902de3bb15ec75aeb9b8f05404d530910b. Runtime job 117417577129 passed native failed-save retention, real MySQL atomic usage/audit/private-row checks, and the actual authenticated production amendment whose committed response is lost and confirmed by retry without another source mutation. Reopened production now restores its saved downtime reason. Historical amendments through the acknowledged source route require actual independently recorded raw inputs. Main QA/deployment/live smoke remain pending at this checkpoint.

Owner added: every company bank ledger must be individually available through Ledgers by Party or Name, including typing Meezan Bank and choosing the actual account. Implemented separately in PR 294: company-scoped bank choices show account number/title/currency, retain the name-search view and route to the exact existing bank ledger without a counterparty-name filter. Local endpoint tests confirm separate same-bank balances, opening and transfer movements, unused USD account, company isolation and unchanged journals. Full hosted UI/regression validation and deployment remain pending. No bank records or openings are invented.

Combined bag backend and acknowledged production save passed both full suites at 858f4bad002f65a3bae93afa0d3a40ee746ffbdc (Accounts 39040571221; Milling/Exports 39040571232). The transfer draft remains undeployed because its native receiving/allocation/quarantine and legacy scope workflows are unfinished.

Production Save PR 293 deployed as 557880902de3bb15ec75aeb9b8f05404d530910b: main Accounts 39040374663 and Milling/Exports 39040374661 passed; deploy 39040620853/job 117419231421 succeeded and startup performance 39040700046 passed. Final live read-only smoke remains in progress at this checkpoint.

The new owner bank-ledger requirement passed full Accounts 39040449161 and Milling/Exports 39040449158 at 740f0caf4427d6f1551752c8defe9f31116e0348. The hosted native selector test passed individual account/currency/zero-activity choices, no reads while typing, exact chosen bank identity and print, retained name lookup, and ordinary party/direct-bank routes. PR 294 merged as 4a7d213d65f444d6bf5578e8c58eb3d8be72b129; main QA/deployment remain in progress. No actual bank or journal data was changed.

Production Save release final verification: live read-only smoke 39040700045/job 117419452710 passed 18/18. Bank lookup main Accounts 39040726613 and Milling/Exports 39040726698 passed; deployment 39040963203 succeeded at 4a7d213d65f444d6bf5578e8c58eb3d8be72b129. Bank release live smoke 39041022060 and startup performance 39041022169 remain in progress at this checkpoint. All live validation is read-only; no production, bank opening or financial test entry was made.

The bag allocation draft additionally rejects future-dated actual packing, using the same actual-date validation as dispatch. Local shared-writer allocation tests pass. This additional draft change and integration with the deployed bank lookup require fresh full branch QA; native transfer/receipt/quarantine/legacy reconciliation release gates remain open.


### Production source-bag selection UI — resumed 17 October

Added a draft Production review of actual empty bags, original PO/source lots, packing and material. Separate lots and packing sizes are chosen explicitly, including received transfer stock; quantities must match actual whole bag usage. Fractional output equivalents remain unchanged and require independently entered whole empty-bag counts. Invalid or blank physical counts cannot be rounded or converted into zero. Reads occur only on explicit Review; typing never commits or refreshes.

Save Production now passes the reviewed source allocations and stock fingerprint to the existing atomic source writer. Saved source snapshots remain visible during amendments. Raw-input-only edits retain their original bag allocation without a fresh stock read. Quantity, date and company/location changes require review; failed reviews preserve entered source quantities, and late responses cannot replace another scope. The existing real browser handoff now selects actual source bags and verifies original PO and remaining stock.

Local syntax and pure count/unchanged-allocation checks pass. Local workspace execution became unavailable during this continuation; the enhanced DOM and actual browser/controller suites are being run through the draft branch's hosted QA. The connected local browser tool also reports that this chat lacks its originating browser context. No live stock test was posted. Transfer/receiving screens, quarantine and legacy receipt-scope reconciliation remain unfinished release gates. Maize labour design remains deferred.


### Acknowledged dispatch and accepted receiving UI — draft continuation

Production allocation at 8364d34ba05644ed69e68d722063bc8be99eef63 passed full Accounts 39046365220 (audit 117435784508 and actual runtime 117435784738) and Milling/Exports 39046365242 (117435784793).

The native New Export Bags panel now opens the draft source-lot transfer controller instead of writing a legacy brand-only transfer into a supplier PO. It shows company/physical location, original PO lines, packing/material, available committed stock and held lots. Dispatch chooses explicit source lots and an actual Master destination. Destination receiving requires actual accepted whole quantities; physical returns are a separate source action with a reason. Neither dispatch nor receipt fabricates a destination PO.

Every write retains an actor/scope-bound durable request before sending, waits for the controller acknowledgement and clears only its own acknowledged entry. Uncertain responses can only retry the original facts; a definite 403/422 refusal permits correction with the same uncommitted request identity or explicit discard. Failed saves retain entered quantities and focus. Scope navigation retains dispatch drafts; response refresh preserves source quantities typed during the read. No typing initiates a read or write.

Added DOM regressions for full dispatch, partial accepted receiving, failures, lost responses, exact retries, permission/refusal corrections, source/destination actions and unrelated draft isolation. The legacy native location test now verifies controller delegation and no PO/cache writes. Production's review also preserves source quantities typed while its response was in flight.

This remains draft PR 291. Complete quarantine handling, supplier receipt atomic acknowledgement and legacy receipt-scope reconciliation are still release gates. Existing supplier receipt entry remains staged. No live inventory or financial entry has been created or amended. The local workspace/browser connections remain unavailable; hosted QA verifies this checkpoint.


### Verified native bag workflow checkpoint

Code checkpoint b12f81107907c0a9146779f610f7822c91f918c4 passed full Accounts 39047137957 (audit 117438013527; inventory runtime 117438013310) and Milling/Exports 39047137962 (audit 117438013713). Both new source-allocation/transfer DOM suites passed. The actual guarded MySQL controller passed company/location/role/CSRF checks, atomic partial receipts and returns, stable replay/version conservation, private-ledger protection and unchanged original PO/books. The real authenticated native browser flow passed failed dispatch retention, exact retry, zero destination availability before acceptance, partial receiving whose committed response is lost, replay with byte-unchanged store, and physical source return. Final source available stock was 80 after original 100 less production 12 less dispatch 10 plus physical return 2; destination accepted stock was 6. These are disposable fixtures, not live balances.

Receipt drafts now survive location navigation, including actual date, quantities, reason and inspection choice. Explicit review preserves quantities typed while its request is in flight. Visible movement dates follow DD-MM-YYYY. The previous runtime failure was an isolated legacy fixture without window after native transfer delegation; the fixture now tests the fail-closed component boundary and keeps every original identity/history assertion. No application validation was bypassed to make that test pass.

Remaining release gates are concrete:
- Supplier receipt functions receiveBagsInline/receiveBags still stage tt33bagreceipts and tt30bags separately and clear without exact server acknowledgement; they need one actor-bound atomic request and retained retry identity.
- Legacy receipts without immutable company/location/packing evidence can still follow a changed PO. Their source must be confirmed or held without inventing a physical opening or rewriting issued documents.
- Accepted-only destination receiving is tested. Rejected or quarantined physical receipts and their explicit disposition still need a separate inventory workflow; the existing invoice-specification approval must not silently make rejected bags available.
- Existing untraceable external ready-rice bag consumption and ambiguous historical allocations remain held until actual facts are supplied.

PR 291 remains draft and undeployed. The independent bank ledger and Production Save releases remain live and verified. No live stock, opening or financial test movement was posted.

Local source-save, shared-cache isolation, actual production controller, Create/Edit boundaries, atomic usage/audit, private preservation and inventory regression tests pass. Hosted full QA, native DOM and real authenticated browser failure/lost-response tests are pending for this release candidate. No live production, financial or opening records were created.


### Continued full review — 18 October 2026, connection-limited checkpoint

The owner's specifically authorised date correction is saved and verified after
reload; the current bank balance did not change. The three previously identified
test arrivals remain archived; no second withdrawal was performed.

Sales Tax PR298 merged dfcbeda51496ee1b44e8e30d790e5234ce3222ea after final
Accounts39067668505 / Milling39067668421 passed. Main39067928763/39067928762 passed.
Deployment39068162740/job117500035685 confirmed this exact deployed SHA and healthy
login. Post-deploy smoke39068250504/job117500292732 and startup39068250546/
job117500292906 passed. Actual Chrome verified three matching lot rows for the
selected September-to-October interval, with preparation rows removed and distinct
same-named uploads retained. Issued native CI/PL archive links and original advice
mapping are not certified by this result.

WHT PR300 final head d126f3a9938e04ef6391a6c867f5526af1750e83 passed full
Accounts39068647505 (jobs117501457087/117501457256) and Milling39068647514
(job117501457056). Native browser tests printed the source report, downloaded CSV,
compared matching source references/tax figures and retained byte-identical books.
Initial requests now use displayed dates; source detail, summary, category filter
and exports share one snapshot. Narratives require Reports View and are omitted
from ordinary master-editor responses. Merged f7dcdc0c92a535b3d42f97bc3f3e1e59e02706ea.
Its main Accounts39069072183 passed; Milling39069072160 passed. Combined production
deployment and fresh live acceptance still need final confirmation.

Sales Tax source integrity PR301 head17220c0e112cb713c9d80d1d8963fe2aa8b87f4a
passed Accounts39068925008 and Milling39068925085. Merged
cd4f78727a6710434eecf9934c17a7d4f969d8ba. Shared file-read locks and strict JSON
validation now return an explicit failure for unreadable sources instead of false
empty results; a failed configured document database no longer falls back to an
older file index. Corrupt-source endpoint probes preserve the source bytes.

Actual Chrome confirmed Product Status duplicated Profile/use wording. PR302
head010eb2e6c3da7b11f3498ab13780c67b6be37cd8 passed Accounts39069028358 and
Milling39069028419. Merged ee4e85de869d9f521ebb6ae2324c69a9547f66a9. The label
uses the same existing inactive rule, preserving profiles, filters and records.
Main Accounts39069326811 / Milling39069326681 and combined deployment are pending.

Draft PR284 is superseded by PR299. Latest group foundation
94e1815e54e0f893a6bc0efa3bec711246e2b03a passed Accounts39068452707 and
Milling39068452799. It includes cutoff-aware original-post detail, partial annual
assessment labels, completed-only prior-year carry and server-derived selected
Rice actual comparisons. Original run IDs/source revisions survive; estimates or
incomplete cost allocation never become actual costs. Optional separate freight
proceeds receive proportional deductions once, while freight paid remains a cost.
Keep draft/undeployed: automatic Daily report, missing-cost estimates, Corn actual
comparison, source-linked TG shipment profit and final annual payment reconciliation
remain unfinished.

PR303 strengthens the existing workspace-response fixture: uncaught DOM exceptions
must fail the test. Its initial drain-only cleanup still failed because window
closure itself queues observer callbacks. Latest b5dba16401c554081bee901c2250764b8d05b2de
disconnects fixture-owned observers before closing and retains all active-lifetime
error assertions. Accounts39069446646 / Milling39069446651 are running.

Local shell, alternate file runtime and Chrome transports disconnected during this
continuation. GitHub remains available; source review and hosted checks continue.
Fresh Chrome checks, local updates and restore acceptance cannot be claimed until
the connection recovers. Security Scan SKILL.md was read but its mandatory prologue
and preflight could not load (skill environment unavailable). No security scan was
started, no worker was launched and no security clearance is claimed. Resume by
reading the required references and obtaining a ready preflight first.

The full review and migration sign-off remain open. Preserve missing historical
receipt facts and salary/rent allocation holds. Complete the canonical recurring
reference reconciliation, external Corn route, report/document outputs, Master
agreement versions and remaining real role/action/source/restore acceptance.
Maize labour design remains explicitly deferred; do not infer Rice rates or repeat
its existing reminder. No master field or historical financial record was deleted.


### Final verified release evidence for this connected interval

Main ee4e85de869d9f521ebb6ae2324c69a9547f66a9 passed Accounts39069326811 and
Milling39069326681. Deployment39069565736/job117504163064 succeeded; logs confirm
that exact DEPLOYED_SHA and healthy production login. It includes PR298/300/301/302.
Post-deployment Accounts smoke39069616913/job117504322430 passed18/18:10 individual
PKR-bank comparisons,50 account-ledger comparisons across3 companies,0 concurrent
revision skips and0 financial writes. Startup39069616869/job117504321811 passed.

A separate hosted browser reached the production reports through the existing QA
account while the local Chrome connection remained unavailable. Targeted
run39069913412 passed initially; final count-evidence run39070073487/
job117505673824 passed with3 Sales Tax rows,8 WHT source lines and15 product
availability statuses. It validates deployed source loads, report date cutoffs,
original-file IDs, WHT summary-to-line reconciliation and omission of transaction
narratives from ordinary master responses. All report requests are GET. PR305
retains these checks in the normal read-only post-deployment smoke; merged
52e9ff0e2aaf7c307822f39028b6da7703d2d307. The temporary verification workflow is
confined to verify/deployed-reports-20261018 and was not added to main.

PR303 final full suites39069446646/39069446651 passed; merged
311018374a19c884e99f019ba29dca1d70e76588. Its main full suites39069708396/
39069708438 also passed. The first drain-only attempt remains recorded as failed;
tracking/disconnecting fixture-owned observers before closing fixes the teardown
without hiding uncaught errors during the test. These later commits change tests
and evidence, not application transaction behavior.

External Corn follow-up source trace: purchase_sodas.php chooses EX_MILL only for
READY products; milling_purchase_sodas.php forces every RAW feed back to
DELIVER_TO_STOCK; syncExMillPurchaseSodas in the native Mill HTML selects READY
EX_MILL sources. The existing lifting handoff already carries commodity metadata
and permits zero bags at the server, but the external native UI and Soda route
still impose Rice assumptions. Before expanding this path, review handoff
company/location authority and dated provisional-journal correction behavior in
milling_purchase_sodas.php, then exercise the real native Corn purchase/load/bill
flow in disposable storage. Do not describe the existing bridge-only case as full
external Corn acceptance.

Local shell, alternate filesystem runtime, Chrome and mandatory security skill
reference reads remain disconnected. No security scan was started; its required
preflight never returned ready. Preserve the open full-review/migration gates and
resume without asking for the authorised date or repeating the test-arrival cleanup.


## Review resumed with connected Mac Chrome — 18 October 2026

Read [RESUMED-2026-10-18.md](RESUMED-2026-10-18.md) for the current continuation evidence. It records deployed backup fixes PR307/308, successful verification of the original 705-file snapshot, actual isolated original-file restore and three-store rollback, 19/19 read-only live checks, exact bank-name ledger acceptance and unreleased bag receipt/report fixes PR306/309.

The whole-application review remains in progress. PR297 mill/bag handoff and PR299 group management reports are not production releases. Management source workflows, outside-mill Corn, original advice/document reconciliation, remaining role/action/output variants, large offline recovery and migration acceptance remain open. Do not repeat the earlier test-truck withdrawal or authorised date correction, invent actual opening stock, apply Rice labour rates to Maize, or treat these targeted passes as whole-application sign-off.


## Integrated bag and Mill processing release candidate — 18 October 2026

The current source-readiness probe (39113356547) runs the draft projection against original live records without mutation: 3 bag orders, 0 receipts, 0 production runs, two TTI locations, no ambiguous/held lots and no unallocated consumption. All physical and available quantities remain zero. This resolves the historical-data release concern for the current sources; it does not invent opening stock or certify future unentered receipts.

PR291/297 changes, including discrepancy guard PR306 and return-report PR309, are now integrated with latest main and the deployed backup repair in a separate release candidate. No application merge conflicts occurred; historical branch notes and newer continuation evidence are both retained above. Full hosted integration suites, exact deployment identity and fresh native Chrome acceptance remain required before calling this candidate live. Larger automatic management reports and outside-mill Corn work remain separate.


## Current release / Daily continuation checkpoint — 18 October 2026

The integrated bag/Mill candidate was deployed through PR311, followed by permission repairs PR312/313. Current verified production code is2815a6616403306747caabc3ee10561dbdedbe51; its live smoke passed19/19. Earlier draft wording above is historical. PR297 is closed as superseded. Daily source-derived work continues in draft PR314 and is not deployed. See the final section of [RESUMED-2026-10-18.md](RESUMED-2026-10-18.md) for exact deployment/test identities, the retained intermittent Exports startup failure, original-file source proof and remaining work. Whole-application completion remains open.


## Container identity release and validated Daily candidate — 18 October 2026

PR316 is released asca7050c6d0f5b29c2eaf33e7ae6a47417f18e98d (deployment39119392157); startup39119473546 and authenticated read-only smoke39119473548 (19/19;0financialwrites) passed. It prevents equivalent physical ISO container aliases from recognizing another Ex-Mill liability, retains native display text and preserves authorization. Full feature/main suites passed. Actual production financial test records were not created.

The Daily candidate8f5d98d passed full hosted actual-auth/MySQL/native-browser tests. Draft PR314 now includes the released repair plus an additional reproduced Rice/Corn stock mismatch guard at727080e; fresh combined full Accounts39119342929 and Milling39119342931 passed. Reports are not deployed. Intercompany/source/product compatibility acceptance, broader management reports, Corn source authority/immutable correction and migration/security sign-off remain open. See the newest checkpoint in RESUMED-2026-10-18.md; it supersedes older pending/menu/tool-availability statements.
