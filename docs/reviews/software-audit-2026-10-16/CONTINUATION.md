# Full functional review continuation

Date: 16 October 2026. Status: IN PROGRESS — not approved for full data migration.

This continues REVIEW.md. Earlier test results remain earlier evidence, not fresh
results. No live transaction, user permission, approval or master has been changed.

## Environment

- Actual local Chrome extension, Person 1, connected; signed-in Salman / Super Admin.
- User tab 927050452: production Console. Accounts opened from its actual launcher.
- Remote main admin/app.js blob 1d25f3ee1ad57b3281f9aff3aa23e58de2212c2b matches local source.
- Working branch review/full-functional-20261016 starts from audit commit 0655cf5.
- Shell GitHub access fails DNS; connected GitHub read API works.
- Some localhost HTTP tests fail to bind sockets under the execution sandbox.
  Such failures are blocked test execution, not proof of application defects.

## Live observations requiring resolution

- R01 reproduced: Directors card still says Awaiting module / Not connected.
- R02 reproduced visually: Talha's user editor has Mill, Exports, Accounts but no
  Directors controls. Inspected only, closed without saving.
- R04 reproduced visually: Module Locks promises cross-module protection, while
  the reviewed source persists browser-local flags only. No lock was changed.
- R05 reproduced visually: Audit Trail is empty despite real users, source records
  and pending JVs; source uses local activity rather than authoritative server audit.
- New C01: pending JV accounting lines show `Bank Accounts ·` without the bank
  identity. Trace original draft lines before deciding whether source or projection
  lacks that identity. No pending JV was approved or rejected.
- New C02: customer, business-party, document and term master lists have two
  Status columns. Source generic column selection includes Status and appends it
  again. This wastes width and obscures actions on the Mac viewport.
- New C03: user Last active displays an ISO timestamp with T and timezone rather
  than the agreed readable DD-MM-YYYY time presentation.
- New C04: Export Quality & Specs duplicates Profile / use into the Status badge.
  Investigate actual active/inactive storage before changing filtering semantics.
- Backup panel reports 72 private recovery snapshots, latest 16-10-2026 23:36.
  Restore integrity and complete recovery exercise remain untested. Displayed
  hourly retention policy does not itself prove an hourly scheduler is running.

## Coverage discipline

Opening a list is navigation coverage only. It does not pass create, save,
amendment, deletion, role boundaries, output correctness or migration readiness.
Live mutations are withheld; posting scenarios belong in disposable copies.

| Area | Screen/action | Result | Evidence / remaining scope |
|---|---|---|---|
| Console | Owner home and approval listing | failed | R01, C01; actual approval decisions not tested |
| Console | Open Users & Permissions | passed | Seven user rows load; protected owner visible |
| Console | Open existing user editor | failed | R02, C03; cancelled without saving |
| Masters | Companies list | passed | Three legal entities visible; mutations not tested |
| Masters | Export Customers list | failed | C02; six rows load |
| Masters | Business Parties list | failed | C02; service roles visible including transporter/bag supplier |
| Masters | Export Quality & Specs list | failed | C04; crop-year explicit Save visible |
| Masters | Purchase Commodities & KAT list | passed | RAW/READY and commodity controls visible; KAT posting effect untested |
| Masters | Mills & Locations list | passed | Four records including own mill and external mills |
| Masters | Currency & Reference list | passed | List loads; reference changes untested |
| Masters | Export Documents list | failed | C02; original/copy quantities visible |
| Masters | Export Other Terms list | failed | C02; conditional group values visible |
| Masters | Salary & Staff list | passed | 31 records; amounts and groups present, posting untested |
| Console | Audit Trail | failed | R05; empty list, no central history |
| Console | Module Locks | failed | R04; no flag changed |
| Console | Backup panel / status | passed | Latest snapshot and credential status displayed; restore not tested |
| Accounts | Launcher / home | passed | Real signed-in tab opens; all eight main areas visible |
| Accounts | Reports menu | passed | Thirteen report entries visible; individual reports pending |
| All | Full posting / amendment / reversal chains | not tested | Controlled fixture work in progress |
| All | Restricted staff roles | not tested | Owner inspection alone cannot certify restrictions |
| All | Printed and exported output matching | not tested | Individual report/document review pending |
| Migration | Staged full-data import and rollback rehearsal | not tested | No migration approval until reconciled |

## Fresh focused checks

Ten of fourteen targeted checks passed: bank reconciliation API/projection,
supplier discounts, FI linkage core, approval integrity, bag period report core/UI,
session hardening, inventory core/UI. Four HTTP tests were blocked by socket
permissions (all four failure logs classified as denied socket binding); retain raw logs at
`/tmp/tt-completion-checks`. These are not whole-application acceptance results.

## Further live findings

- C05: Mill Petty Cash Received still opens a manual Amount / Save form.
  `savePettyInline` appends operational `tt30petty` rows without an Accounts journal.
  The view combines those with canonical Accounts rows. Historical cash must stay
  distinct; the standing Accounts-only receipt rule is not fully implemented.
- C06: Exports home at the actual Mac viewport squeezes its introduction and
  search into a very narrow column. The right auto grid track consumes the width.
- C07: TG/AMT/13's B/L/commercial description says 100% broken; its saved Customs
  HS field is 1006.3090, which the invoice faithfully outputs. This conflicts with
  the configured master rule for 100% broken rice. Original issued documents were
  not changed; source-data/approved-document review is needed, not a silent rewrite.
- C08: Milling's `setTodayTextDefaults` changes DD-MM-YYYY text proxies to native
  date inputs when panels open. The live Pohanch search date cleared after entry.
  The control had become a native date while retaining text-mask listeners.
  Reproduced and fixed in an isolated DOM test.
- C09: P&L shows a negative Zakat expense balance; inspect original journals and
  period reversals before judging its accounting. A negative amount alone does
  not prove a defect. P&L rows are plain table cells without direct source drill-down;
  General Ledger is available separately.

## Further screen coverage

| Area | Screen/action | Result | Evidence / remaining scope |
|---|---|---|---|
| Accounts | September bag report | passed | 9,610 ordered, zero receipt records, 4,020 exported; source agreement checked, CSV/print pending |
| Mill | New Export Bags / GHAZAL receipt | passed | Zero saved receipts agrees with Accounts; tare tick shows actual tare; no save |
| Mill | Labour, KE, Rent, Salary, Cost | failed | R06–R08/R10 remain; salary shows empty legacy master despite 31 central staff rows |
| Mill | Recovery report | passed | No production; recovery unavailable rather than fabricated; real totals not certified |
| Mill | Rice Ledger | failed | R13; live arrivals 119,500 kg; competing calculations |
| Mill | Production form | failed | Actual input/brand/contract present; C08; no production saved |
| Mill | Arrival, Pohanch, history search | failed | Three historical records load; C08 date failure; no posting |
| Mill | Export Loading | not tested | Screen opens; own-mill instructions empty; Ex-Mill still pending |
| Mill | Used bags | passed | 400 + 400 + 500 = 1,300 arrivals-derived bags; outward posting untested |
| Mill | Petty Cash Received | failed | C05; manual form remains; no cash entered |
| Exports | Home | failed | C06; severe search/heading width at Mac viewport |
| Exports | Completed ASAS/62 VIEW | passed | Read/reprint and history open; REOPEN separate; no lot changed |
| Exports | AMT/13 B/L draft | passed | Ten containers × 540 bags × 50 kg = 270,000 kg; FI/GD displayed |
| Exports | AMT/13 Invoice / Packing | failed | USD164,700 less USD39,999 = USD124,701; TG bank/payment history present; C07; PDF geometry pending |
| Exports | AMT/13 Customs | failed | USD400 × 270 MT = USD108,000, allocated FI matches; C07; no save |
| Directors | Direct protected route | passed | Actual module loads; Console launcher separately fails R01 |
| Directors | TG overview / rates | passed | Four bank records, dated rates, zero compliance records; writes untested |
| Directors | Brokerage | passed | Rates/WHT controls load; lists empty; competing party-based settings need consolidation review |
| Accounts | P&L | not tested | Live figures load; C09 requires original source review |
| Accounts | Pay Expense / Utilities | passed | Bank/cash/card available; saved Mill KESC fills provider, location/reference; no posting |

## First repair batch — merged; deployment verification pending

- Directors opens its existing protected route.
- Unrelated user edits preserve Directors, unrepresented icons, legacy module-wide
  grants and hidden master scopes. Explicit removal of a displayed grant still works.
  Server retains an unchanged legacy grant but does not create new legacy grants.
- Generic master lists show one Status column; activity timestamps display Karachi
  time in readable DD-MM-YYYY format without rewriting stored dates.
- Exports home uses two bounded columns, preserving the existing single-column
  breakpoint. Live visual verification after deployment remains pending.
- Milling date defaults ignore shared date/numeric proxy controls.

Fresh regression tests for permission preservation (UI + validator) and Mill dates
pass. Existing Director approvals, Master topbar, Console cleanup and master
permission migration tests pass. Both hosted QA suites passed for repair commit 5800f59d65a50aa4cb3be43bccca55bd2fef05ef:
Accounts run 38952258160 and Milling/Exports run 38952258469. PR #264 merged as
49ffbc26af52ac33904c6d3747ac312b39bc43b2. This is a source merge, not proof
of production deployment. The merge commit returned no associated workflow runs
at the last connector check. Post-deployment live visual verification remains
pending. No whole-application or data migration sign-off.


## Continuation checkpoint — 17 October, Pakistan time

The local Chrome connection closed during the resumed session. Reconnection and
reset both returned `Transport closed`; fresh live UI checks are blocked until
that connection resumes. Earlier signed-in Mac Chrome observations remain valid
as earlier evidence. Source work and GitHub access continue.

### Zakat — management clarification and 19 October scope update

**19 October update:** Salman confirmed the annual form was already explained and is within the agreed work. The earlier deferred wording below describes the 17 October checkpoint only. PR347 adds native Accounts/Directors navigation and the annual assessment-control debit, original payments/refunds and carry-forward ledger. It does not create a second monetary expense. C09 canonical annual journal mapping and legacy P&L classification remain an explicit accounting review gate. See `NON_UI_AUDIT_2026-10-19.md` and the current coverage register.

Salman confirmed on 17 October that calculated Zakat becomes payable throughout
the year, payments reduce the outstanding payable, and amounts paid belong to that
year's expense. He requested a future annual Zakat form creating the yearly debit
entry. The exact balanced entries and treatment of existing records must be
reviewed when implementing it. Do not add the form or rewrite historical journals
as part of this checkpoint.

The live source trace found POST-2026-00074 on 1 July: credit 7210 Zakat 852,747,
debit 3400 Opening Balance Clearing 852,747. Nine subsequent payments total 103,350,
leaving a credit balance of 749,397. That is a payable balance; its existence is
not itself an error. C09 remains a reporting/classification issue because P&L
currently includes the net credit as a negative expense. Verify the paid amounts
against payment sources and the chosen financial year before changing reporting.
A reminder to add the annual form is scheduled for 18 October at 14:00 Asia/Karachi.

### C10 — Directors write permissions exceed the selected screen scope

A disposable endpoint-copy probe now reproduces an actual Brokerage Master write
for a Director with only `reports: [View]`, and an Accounts user with only
`reports: [View, Edit]`. It uses the real module-access helper and the complete
brokerage endpoint, with isolated login/CSRF/input fixtures. Both persist a WHT
rate in the temporary accounts file; the no-module-access control is denied and
creates no file. See brokerage_scope_probe.py. No live endpoint POST was sent and
no real user grants were changed. This remains unresolved.

The separate `tgfx_can_edit` helper also accepts an unrelated `assets` Edit grant,
but every TG mutation path additionally checks the corresponding Master Records
action. Therefore that helper result alone does NOT prove unauthorized TG master
writes. Keep that defense-in-depth distinction; do not label it an end-to-end TG
write bypass without satisfying the additional master authorization.

Brokerage/freight scope must be mapped to the canonical permission model, with
negative tests for unrelated icons and preserved legacy module-wide grants.

### C11 — Ledger account names disagree with the chart and P&L

Account 7200 is Donations in the canonical chart/P&L, but the ledger replaces its
name with an export-policy Tax label for the same code. The repair lets policies
add missing accounts without overriding an existing chart identity. It does not
resolve the wider policy code collision or reclassify any posting; review tax
posting configuration before relying on policy-created tax transactions.

A disposable CLI fixture executes the actual ledger and report routes against a
balanced 100 debit/100 credit journal. It fails on baseline, passes with the
repair, confirms identical names and 100 amounts, and verifies the source journal
file is byte-for-byte unchanged. The test is included in Accounts QA. This repair
is prepared separately from the first merged batch; no live journal changed.


### C01 source trace and prepared display repair

`journal_vouchers.php` snapshots bank name, title, identifier, account number/IBAN
and currency into the saved draft. The shared approval feed preserves the original
lines, but Console rendering shows only generic account name and party. The repair
renders the original bank details and currency, with HTML escaping. It changes no
approval endpoint, draft or journal. A fixture using the actual approval renderer
fails on baseline and passes with the repair, including an HTML-like bank name.
The revised second repair batch is tracked in PR #265; hosted QA and visual
verification remain pending.


### Hosted regression checkpoint

Accounts QA run 38954253701 failed in the newly added file-backed catalog test:
the test inherited the hosted runner's MySQL environment. Reproduced locally by
supplying those environment variables. The fixture now strips TT_DB_* only from
its PHP subprocess environment, so it reads its disposable file-backed source;
it passes both with and without MySQL variables supplied. Application connection
settings are unchanged. A fresh complete hosted run remains required before merge.

See MASTER_RECORDS_PROPOSAL.md for the proposed shared management area and the
initial field-use classification. It additionally records that labour Effective
From is saved but current rate lookup does not choose by work date. This remains
an incomplete dated-rate feature; no historical bill was regenerated or changed.


### Verified second repair batch

Both complete hosted QA suites passed for application commit
851c77ab51cd50d031f2fbbcec95cea570adfe18: Accounts run 38954589822 and
Milling/Exports run 38954589829. Subsequent audit/probe documentation adds no
application-code changes. PR #265 remains draft; production deployment and live
visual verification are not confirmed. The full review is still incomplete.

Next required work: restore actual Mac Chrome control, complete remaining
screen/action coverage, resolve the Brokerage Master permission defect and Mill
financial handoffs, reconcile policy account identities, test restricted-user
flows and matching print/exports, and complete a staged import/restore rehearsal.
No live journal, approval decision or user grant was deliberately changed.


## Resumed review — 17 October, after 00:32 Pakistan time

Local Chrome reconnected to the same verified extension/profile and original
application tabs. A Console reload briefly reported an open extension panel;
Accounts controls remained usable, and the Console subsequently loaded the new
release. This supersedes the earlier browser-blocked checkpoint.

### Deployment evidence

GitHub's Actions UI shows the main-branch QA runs that the commit-runs connector
omitted: Accounts 38953284753 and Milling/Exports 38953284991 both passed for
49ffbc26af52ac33904c6d3747ac312b39bc43b2. Deploy Production 38953708084 passed,
including backup, migration validation and live health. Its log identifies the
same deployed SHA. The Directors Console card now says Live and its actual button
opens the protected Directors page. User activity dates display DD-MM-YYYY.
PR #265 also passed both complete suites at its final head (38955140821 and
38955140930) and was merged as b40cb01f2cc4d885aa92dfe056e98e572e7be5bb;
its production/live verification is pending at this checkpoint.

### Additional live coverage

| Screen/action | Result | Evidence / limit |
|---|---|---|
| Trial Balance: account and type | passed | Debit/credit each 126,002,433.78; grouped totals agree |
| Trial Balance: party | passed | Includes company/unassigned rows; gross party totals differ from net-account totals as expected; both sides 301,566,168.29 |
| Balance Sheet equation | passed | Assets 72,908,419.36 = liabilities 103,923,181.92 + equity -31,014,762.56 |
| Expenses: recipient and month filters | passed | FAYYAZ GRAVE YARD has two distinct dated posts, total 4,000; four monthly totals sum to 7,272,858.87 |
| Expense drill-down and chronology | failed | C12: plain Post ID cells without source controls; entries follow source collection rather than date |
| Vehicle / Asset Costs | passed | Four real asset choices; monthly totals 257,214; source-post drill-down missing |
| Personal Adjustments | passed | Three remuneration allocations 1,276 / 3,000 / 10,000; zero recoverable displayed, distinct from cash recovery; original-post links missing |
| Receivables / Payables | passed | Company PKR, account and receivable/payable filters visible; unassigned control balances retained; balances not certified as correct source data |
| Commodity & Local Sales | failed | A filtered journal list, without commodity/brand quantities or source drill-down; source records must be reached elsewhere |
| Shipment Contribution | passed | Explicit Provisional status and missing stock-cost/revenue notices; no final profitability sign-off |

The credit Packaging Inventory 5,554,243 and debit GRNI 15,775,607.50 require
source/opening reconciliation before migration. Their signs alone do not prove a
calculation defect. No journal was changed to make these balances look normal.

### Third repair batch — prepared, not deployed

- R04: replace unsupported protection claims with explicit browser-local approval
  notes, retaining the stored flags/history. No operational freeze is invented.
- R05: owner-only, GET-only server administrative audit feed with whitelisted
  display fields. Console shows the server feed and keeps old browser notes in a
  separately selected source. Search/export match that source and filter; failures
  mark stale results and block an unverified export. Financial posting history
  remains in its existing Post ID source.
- R09: remove the startup/read-triggered shared-save wrapper from Soda projection.
  Derived cache still supplies the view; explicit workflow saves remain available.
- R11: await the canonical Location Master acknowledgement before adding a local
  Mill, retain its master ID, surface denial/invalid responses, deduplicate retry,
  and await explicit shared save before rendering the new Mill card.
- R15: transport and service/freight result panels add Edit alongside Print, using
  the existing source-owned bill amendment route and original bill identity.

Fresh tests execute the actual audit endpoint, the actual Console in an isolated
DOM, Milling read/master functions with a delayed denied response and retry, and
both shipment result renderers. Existing Console permission, Director queue,
cleanup and Soda routing/date checks pass. New audit GETs do not mutate the source
file or expose users, password hashes, master payloads or IP addresses. Required
hosted checks and remaining live coverage are still pending for this new batch.


Additional local check: accounting_desk_test.js fails its pre-existing assertion
that Exports calls showTgUnallocatedPayments(). Neither exports/app.js nor
api/export_tg_unallocated.php changed in this repair batch. The absent FI-register
hook may be a retired path or a real missing handoff; investigate the current TG
unallocated-payment route before classifying it. The assertion was not weakened.
The posting-confirmation ownership/review runtime checks pass.


## Continued document and loading review

- The deployed Console now shows each pending JV's original Meezan bank identity,
  account title/number and PKR. No approval was made. Directors permission controls
  are present in Talha's editor; it was inspected without changing any grant.
  The central Business Parties list now has one Status column.
- AMT/13 COO uses the saved B/L wording (PK-386 100% broken), 5,400 bags, 270 MT net,
  270.648 MT gross, saved B/L reference and commercial invoice date. The previously
  recorded Customs HS mismatch remains a source-data review item.
- Upload type GD reveals saved number/date. Added a blank second draft GD row,
  removed only that unsaved blank row, then restored Final/Original B/L. Original
  B/L number/date returned correctly. No file was uploaded or saved.
- Bank Covering preview carries the correct FI/GD and seven document rows. The
  mandatory GD copy cannot be deleted. Printing/saving live documents was withheld.
- TG internal invoice uses Customs USD400/MT, distinct from the buyer's USD610/MT.
  Ticking Include Importer reveals actual buyer AMT Enterprise and its Gambia
  address; the option was restored without saving.
- **C14, source-data blocker:** AMT/13's approved exact Relationship Letter wording
  names ALI SULAIMAN AL-SHEHRI TRADING COMPANY in Saudi Arabia, while the same
  screen's Final Buyer and the lot identify AMT Enterprise, Gambia. Final Output
  marks that relationship letter Ready. Preserve the saved wording; management
  must review the correct approved text before printing/sending the letter.
- Final Output contains system CI/PL, original B/L, COO/GD/phyto/fumigation and TG
  documents; its optional/unissued bank-letter controls are disabled. No lot was
  completed, cancelled, reopened or combined during this inspection.
- AMT/13 history shows actors and source references, but some timestamps are out of
  order in the collection (20:14:56 before 20:14:29). No history rows were changed.
- Ex-Mill → AL-Harmain shows AMT/13 ten loaded containers, 540 bags each, and no
  remaining capacity. Numbers/seals/bags match Exports. Mill weighbridge amounts
  and planned document net weight are distinct: module.php retains millNetKg and
  documentNetKg separately. Do not overwrite either with the other. Loaded rows
  are protected against amendment after loading completion.

### Fourth prepared repair batch

- **C15:** FI Register opening called TT_SHARED_SYNC.saveNow before reading credit
  advice tags, producing a Saving interruption on navigation. Removed that write.
  The read now updates tags only, refreshes after an acknowledged explicit Exports
  save, ignores other-store events and drops superseded/navigation-stale replies.
- Expense/vehicle detail rows now sort by date/Post ID. Run preserves recipient,
  beneficiary and asset selections, including an empty-result period, rather than
  silently broadening the report. Company changes clear these scoped selections.
- Expense/vehicle, Personal Adjustment and General/Commodity Ledger Post IDs open
  the existing authorized posting viewer. CSV retains the same sorted/filtered
  records; printed Post IDs remain visible. Aggregate account/party drill-down
  and commodity quantity reporting remain unfinished parts of C12.
- Corrected the permission editor's misleading claim that module users can manage
  masters by default. The existing server fallback is View/Use; the explicit
  matrix governs writes. No permission semantics or saved grants changed.

Actual runtime regression tests cover all of the above fourth-batch behaviours
without server writes. A separate existing ownership test passes its assertions
but logs closed-jsdom MutationObserver teardown warnings in unchanged helpers;
those warnings are not suppressed. Hosted verification is still required.

Third repair batch PR #266 passed full Accounts 38957701428 and Milling/Exports
38957701777 and merged as 412f6a15f5ff5f1e9b74af44df960105bd90a570.
Its deployment/live recheck is pending at this checkpoint. No migration sign-off.


### Fifth prepared repair batch — Brokerage scope

C10 now has a source repair and actual endpoint regression tests. Granular
Accounts/Directors matrices must explicitly name Brokerage Master. Create cannot
change an existing rate; Edit cannot create a new dated rate. Super Admin and
pre-existing flat/module-wide write grants retain their former master access.
The Console exposes the explicit Brokerage row in both matrices, initially empty
where no such grant exists. No actual user file or grant was changed.

Both Accounts and Directors master editors consume the server canCreate/canEdit
capabilities and disable writes for a read-only user. The endpoint also rejects
impossible calendar dates, nonnumeric WHT and nonfinite rates instead of storing
misleading zero/normalised dates. Valid existing data is untouched.

brokerage_scope_test.py executes the full actual endpoint with the actual shared
authorization helpers against disposable books. Reports-only Directors/Accounts
and View-only Brokerage requests receive 403 with unchanged source bytes; explicit
Create/Edit and legacy controls succeed only as intended. The paired actual UI
fixtures verify read-only fields/buttons on both routes without writes. The
original brokerage_scope_probe.py is historical reproduction evidence for the
pre-fix commit, not a current acceptance test. Hosted QA is pending for this batch.

Fourth repair PR #267 passed both full suites (38959196021 / 38959196140) and
merged as 7a66895f97413a9da9c0be7bd0713f17d260d9d7. Deployment/live verification
is still tracked separately. R06–R08/C05 and unreviewed screen actions remain open;
these permission/report repairs are not a full-migration sign-off.


### Audit usability correction

A live owner Audit Trail check stalled browser inspection after the unbounded
server history was rendered. The third batch's table therefore needs bounded
rendering, even though its smaller fixtures passed. The follow-up renders 50 rows
per page with Previous/Next and a matching-entry count. Search scans all retained
entries and resets to page one; Export Matching Activity includes every matching
page. A 137-entry runtime case verifies page bounds, the next page, finding the
last retained entry and exporting beyond the visible page. No audit row is
truncated, deleted or rewritten. This correction is included with the pending
Brokerage repair and must be verified live after deployment.


### Sixth prepared repair — consolidate the legacy production-cost report

The Labour / Mill Expenses → Production Cost / Kg entry retains its KE-period
inputs but now mounts the canonical actual-production/recovery report, passing
company, selected mill and both dates. It no longer divides global arrival
quantities into local estimate totals or shows a zero cost as an established
rate when no production exists. The shared report retains its existing Accounts
approval/estimate labels, permissions, filters, source runs, print and CSV.
Existing approved Accounts rates remain authoritative; this change does not
reallocate costing pools or rewrite approved snapshots.

The actual entry function plus shared report UI fixture checks BRM / another mill,
period changes, empty production, invalid periods, zero writes and late replies.
The first fixture exposed that remounting on the same connected root could let a
prior request overwrite the report. Each explicit Generate now replaces the
child report root, so the existing disconnected-root guard rejects that reply.
The expanded UI fixture and actual-input costing PHP fixture pass locally.
Hosted QA / deployment remain pending. R06 labour draft scope/effective rates and
R07/R08 financial handoffs are still open, not repaired by this report change.

Additional live action coverage: Accounts → Forward Booking Register opens and
loads TTI as-of 17-10-2026 with no bookings. Its text correctly distinguishes an
agreement from a bank receipt. No booking was created, changed or deleted; those
actions remain not tested here.


### Seventh prepared repair — R14 display issues

TG receipt/basket amounts now occupy the second mobile row beside the description,
rather than the narrow remove/tick column. The amount input uses the full column
with border-box sizing. The existing actual-browser remittance fixture now checks
at least 180px and no horizontal text clipping on each visible numeric input.

Bank Finance keeps a deliberate 680px table inside its own horizontal scroller;
facility names retain readable width and the three actions stay on one row.
Both existing browser runners now check container scrolling and action positions.
These layout assertions require hosted browser execution; local syntax passes do
not establish visual success.

Freight quantity summaries use a separate inline role=status element. Only actual
failures populate the role=alert error. Changing customer or clearing shipment
clears prior summaries/errors. The actual form-function runtime fixture verifies
normal summary, blank selection, failure and clearing without any posting.
Financial payloads/calculations are unchanged. Hosted QA/deployment remain pending.

PR268 (Brokerage scope and bounded audit history) passed both full hosted suites
38960520048 / 38960519956 and merged as d68850b85f9719f32f94aa9548fb8d2ae171141e.
Live verification is temporarily blocked by Chrome's debugger connection failing;
this is a control-session limitation, not evidence that the application failed.


### Eighth prepared repair — C05 cash source separation

Mill cash now distinguishes Accounts-posted cash from manual operational history.
The headline and default ledger use only canonical Accounts journal rows. A source
selector exposes preserved operational history separately, scoped to the selected
company/mill (unscoped legacy rows retain the existing original TTI mill fallback).
The two sources never enter one running balance. The period's opening is shown.
Loading or failed Accounts reads show unavailable/loading, never a fabricated zero.
Both request sequence and company/mill identity protect the read result.

The cash-receipt reference inputs/history remain visible; new manual receipt Save
is disabled and the handler also refuses a direct call. Receipts are posted in
Accounts, with no duplicate operational receipt. Existing operational expense entry
remains explicit, now saving company/mill identity and describing its Accounts
posting as unverified. The old operational records are neither deleted nor posted.

Actual function/runtime regression checks positive canonical/manual quantities,
wrong-company history, true opening, disabled and direct receipt Save, separately
scoped operational expense, failed reads and same-name company switches. Existing
real operational-write authorization tests also pass. Hosted QA is pending.
The canonical API's location filtering was traced through ev1_owned_locations;
no missing location check is asserted merely from the endpoint wrapper.

### Deployment and live report verification

Chrome control recovered after navigation/rebinding. Production deployment
38960073960 confirms 7a66895f97413a9da9c0be7bd0713f17d260d9d7 deployed and health
checked. On the refreshed Mac Chrome Accounts screen, expense details are ascending
by date with clickable Post IDs. Selecting FAYYAZ GRAVE YARD and running again
retains the selection, two original rows and Rs4,000 total. Opening POST-2026-00290
shows Home Expense 6910 Dr2,000 / Meezan Bank 1110 Cr2,000 dated 1 September.
Returning through the ledger and closing restores the original filtered report.
A visual screenshot confirms aligned filters and an internal wide-table scroller.
No payment/approval/edit/delete occurred. CSV/print parity for the change remains
covered by the actual runtime fixture, not a claim of live export execution.

Forward Booking Register empty-state read passed. Post ID Register opens all three
companies with separate currency subtotals. Shared public IDs on personal-adjustment
journal rows require further source/identity review; balanced gross register totals
are not themselves trial-balance reconciliation or proof of duplicate money.

PR269 passed both suites 38960904496 / 38960904508 and merged as
5bfe0556fea9d22382f94d3ceb07881197e085ff. PR270 passed 38961292673 / 38961292647,
including the stronger actual-browser mobile amount/table checks, and merged as
eb749027ed05994d7c26ead715026a4a87239d50. Deployment/live checks for these newer
batches remain separate. R06 labour rates/scope, R07/R08 accounting handoffs,
R13 stock ledger, C14 approved-document identity and untouched actions remain open.


### Ninth prepared repair — truthful operational status, not a completed handoff

The six old operational saves (KE, labour, processing expense, rent payment
reference, salary advance reference and reprocessing) no longer claim an Accounts
liability/payment or settled payable. New rows and success messages explicitly
say posting/payment is unverified. New rows retain company/mill scope; feed rows
also retain the original source-record ID. Historical statuses remain stored
unchanged and are shown as unverified saved references. Labour/reprocessing print
estimates and local rent/salary summaries explain their operational basis.

The actual six save functions execute against disposable DOM/stores with unchanged
amounts, saved scopes, original feed references and zero financial endpoint calls.
This is a correction to misleading status claims, NOT implementation of R07/R08.
The existing financial handoff, payment verification and reconciliation remain
migration blockers. No legacy estimate/reference is automatically posted.

### Additional live verification, 17 October Karachi time

Super Admin in a fresh signed-in Mac Chrome tab displays 50 of 4,652 retained server
audit entries; Next shows 51–100. Search Updated finds 82 entries including older
master/settings changes beyond the first page. Browser Notes is a distinct empty
source. Search Updated companies shows 35 actual server changes. The visible table,
filters and action toolbar were inspected in a screenshot. No record was modified.

Fresh Exports home visually confirms the bounded two-column card grid (one active
contract occupies one column). FI Register opens with no Saving interruption.
Its read-only credit-advice refresh marks both saved FIs Credit advice not linked;
that missing linkage requires further reconciliation, not a guessed assignment.
The USD32,000 FI restores its date/customer/bank/currency/value in Controlled
Amendment; Cancel closes it unchanged. Transfer shows only the same-currency target
and protects the USD32,000 unallocated amount. Allocate opens the matching AMT lot.
Transfer/Allocate were cancelled without committing. Actual save/reversal cases
remain fixture-tested or untested as previously recorded, not live-posted.

Deployment logs confirm PR268/d68850b in run38961412400, PR269/5bfe055 in
38961824433 and PR270/eb749027 in38962200441, all successful. The last run reports
production login healthy, backup created, changedUserCount=0, Post ID migration
no-op/changeCount=0, and no business-document recovery applied. These deployment
checks are separate from the PR suites. PR271 passed both full suites
38962056492 / 38962056054 and merged as d03128783bd2fffc38e1f15de2700e3248e145d1;
its deployment/live cash recheck is pending at this checkpoint.


### Tenth prepared repair — scoped labour draft and safe billing dates

R06 labour autofill now filters arrivals, production packing, loading parents and
paltai work to the selected company/mill. Undated feed work is not assigned today's
date. Previous labour billing end and KE period choices are likewise scoped;
operational bill/production summary quantities no longer mix other mills.

Autofill requires a saved agreement effective on/before the full selected period
and matching its scope. No agreement, a newer rate or another scope preserves the
existing draft and requires explicitly agreed tasks/rates. Existing rates and
bills are untouched. No historical rate revisions are invented. The legacy rate
store remains a consolidation limitation: unscoped agreement data retains the
original TTI mill fallback, not an assumed BRM/outside-mill agreement. The charging
basis of the agreed Unloading-to-Loading line remains arrivals; this change does
not redefine a commercial agreement as production consumption.

The new positive fixture found a further defect: next-period local midnight plus
one day, converted back to UTC, repeated the old calendar day in Karachi. Labour
and reprocessing now advance calendar dates in UTC. The actual functions pass in
Karachi and Los Angeles, including month/year boundaries and leap day.
The scoped fixture includes matching work, another TTI mill, BRM with the same mill
name/ID, undated work and invalid/missing/future agreements. No source write occurs.
Hosted checks and live deployment are pending. This reduces R06 but does not make
R07/R08 handoffs or R13 stock ledger complete.


Live recheck also caught the old workspace's separate summary still displaying
Rs0.00 per kg with no recorded production, below the repaired canonical report.
That summary now says unavailable and identifies its amounts as operational bill
estimates; the fixture verifies missing production and selected-mill output.

Fresh Mac Chrome confirms PR271 cash separation, disabled manual-receipt inputs /
Save, source switching and period opening labels. Both scoped cash and operational
history currently contain no rows; existing generic company petty-cash transfers
are not silently attributed to a mill. Positive amounts/reversal behaviour remain
verified through disposable fixtures. The cost entry correctly carries September
1–30, TTI and TTI Rice Mills into the canonical actual-production report.
PR272 passed both full suites 38962953176 / 38962953237 and merged as
1c895eba7c7350cbd9f3cef26c02ea8e47b35109. Its deployment/live status remains tracked.


### Current review register and additional report gaps

CURRENT_COVERAGE.csv supersedes early checkpoint status tables for the actions it
names. Passed applies to that action/environment only; it is not a percentage of
all possible controls. Remaining screen/variant families are explicitly not tested.

The existing accounting_desk_test.js still fails at its TG pending-payment display
assertion. Source tracing confirms renderFI has no showTgUnallocatedPayments hook;
export_tg_unallocated.php exists but is not surfaced. This is an unresolved
integration, not a weakened/removed test. Current manual TG Unallocated FI records
are not proof that Accounts outgoing payments are being shown correctly.

Withholding Tax Report opens TTI January1–October17 and shows OTHER payable
Cr2414.06 and EXPORT recoverable Dr2421170.88. Its screen embeds subaccount controls
and presents no visible row Post ID drill-down or dedicated report Print/CSV.
Totals/source classification and full output parity require further reconciliation.

**C17 — Sales Tax document results:** AMT search returns both the actual lot and
its preparation process. The lot correctly identifies invoice/GD/B-L, but shared
contract-level artwork appears in both rows; the lot lists two separately uploaded
fumigation files. Both rows are selected by default. The source download handler
flatMaps all row documents/advices and opens every URL without deduplicating file
identity, so the same contract artwork can be opened twice. Generated issued CI/PL
are not offered in this attachment finder. This is an output/linkage gap requiring
further review, not permission to delete uploads or rewrite originals. No live
print/download was executed. September Monthly Supplier Files explicitly reports
no attached supplier scans; no empty PDF is falsely reported as generated.

PR273 final head1c4c478 passed full Accounts38963690513 and Milling/Exports38963691030
and merged as569d68c2c12cf88eaaed39e8f55c25c566670d76. Production deployments for
PR271(run38962870007) and PR272(run38963869599) succeeded, retained backups, reported
changedUserCount=0 and Post ID changeCount=0, and confirmed healthy production login.
The later PR273 deployment is still being checked at this checkpoint.

## Rice Ledger replacement prepared (R13, awaiting hosted QA/deployment)

The old Rice Ledger reset its running quantity after filtering, omitted production,
combined different products in one balance and used today's date for missing dates.
It is replaced with a read-only server projection of `tt_inv_stock`. An optional
observer exposes the exact rounded deltas of that existing calculation; existing
stock arithmetic and saved records are preserved. Per-container dates split the
aggregate outgoing delta, conserving its three-decimal quantity. Each product has
its own opening, movements and closing; movement-only display filters do not alter
balances. Undated history is separately disclosed and excluded from dated balances;
legacy consumption inferred from output retains canonical quantities and is marked
provisional. Product/source references are shown, not invented accounting Post IDs.
Source-form drill-down remains a separate coverage item.

The endpoint validates company, location, report permission, dates, movement filters
and source JSON; GET never writes. The UI discards late responses after scope/filter
changes, clears stale results on failure, and uses one generated snapshot for its
screen, print and CSV. Hidden report panels do not issue report requests. Till Date
retains its To field. Positive local tests exercise scoped arrivals, actual production,
by-products, different container dates, period openings, movement filters, amendments,
removal, rounding, unverified history, permission denials and unchanged source bytes.
Existing inventory (103 assertions), recovery, inventory UI, Mill cash, recovery UI
and labour scope checks also pass locally. Hosted QA and real Chrome output/layout
verification are pending at this checkpoint; this is not migration signoff.

## Historical Milling permission editor (R03, prepared)

`tt37users` was never an authoritative permission source. Preserve every stored
entry and every legacy input, but make that form read-only, label its table as
historical drafts, and route management to the existing Super Admin Console.
Direct calls to the old Save handler now perform no write and explicitly say no
access changes were saved. The historical action text is escaped before rendering.
A focused DOM test confirms disabled controls, retained historical records, escaped
content and no direct-handler writes. No actual user grants, roles or company
restrictions are imported or changed.

PR273 production deployment38964450170/job117187320570 succeeded at569d68c,
retained its backup, reported changedUserCount=0/Post ID changeCount=0 and confirmed
the production login healthy. Rice Ledger PR275 remains under hosted QA.

## Sales Tax document selection/output (C17, prepared)

The actual selected-files handler now deduplicates original export file IDs and
credit-advice URLs across selected rows. Distinct files with the same filename are
retained. Clearing every Use checkbox now selects nothing; previously it selected
all rows. Print/download reject changed company/filter snapshots. Starting another
search clears stale results/actions, and older or detached responses cannot replace
the new results. A positive DOM test executes the actual form and both output
handlers with shared artwork/advice, two distinct same-named certificates, empty
selection and competing searches. It passes without changing stored attachments.
Process/lot result inclusion, issued CI/PL integration and source-store failure
handling remain open; this patch does not claim the full Sales Tax workflow passed.

Real Mac Chrome verification after PR273: own-mill Labour Bill shows operational
estimates and “Unavailable — no recorded production”, not a false zero unit cost.
AUTO-FILL WORK gives the missing effective agreement message while keeping the
blank draft and current dates; no Save was clicked. The form and validation were
visually inspected at the ordinary desktop viewport. Contractor dropdown is empty
and the central dated labour agreement path remains a readiness gap.

## TG pending payments / FI reference display (prepared)

Restore the actual pending Accounts-source display in the Exports FI Register,
including explicit Refresh, source record, exporter, invoice/contract, bank reference,
currency and amount. This GET-only panel does not create FI records, allocations or
journals. Late, detached and navigated-away responses cannot overwrite the screen;
source errors remain errors rather than empty-success messages.

The existing endpoint incorrectly excluded the whole outgoing payment when any
receipt linked to it, including reversed receipts. Subtract the active received and
correspondent amounts instead, retain the remainder, and exclude reversed payment
and receipt sources. Unapplied active Pakistan receipt allocations remain separate
reference sources. Require FI View access and fail on malformed JSON/record stores,
non-numeric amounts or missing exporter identity. Release the read session before
reading Accounts. A positive temporary endpoint case has original100, received35,
correspondent5, remaining60, a separate unapplied35 and a reversed1000 ignored;
permission denials and failed reads leave source bytes unchanged. DOM tests check
source identities/amounts, failures and stale-response rejection.

The broad accounting_desk_test.js now passes the previously real missing integration
assertion. Its next failure was an obsolete `data-bank-retention` control selector:
the current Company Master uses bankRetention and serializes independent PKR linked-
retention versus foreign-account flags. Update that assertion to those current
controls while retaining the server designation checks. The complete test now passes
and is added to hosted CI alongside the positive pending-payment tests. Existing
linked-retention HTTP tests provide independent posting/master evidence. Live TG
sources and downstream FI matching still need reconciliation after deployment.

## Additional Mill report gaps found during live/source review (C18)

The signed-in own-mill Export Bags Ledger opens correctly but has no recorded
movements. Positive source review finds Till Date ignores To; range/movement filters
recompute running brand balances from displayed rows; its inputs do not apply mill/
company scope. Production-brand summary also reads all saved shifts and includes
unescaped brand/quality text. Used-bag movements likewise read unscoped arrivals and
assign today to missing source dates. These are source-confirmed gaps, not a positive
live reproduction with another mill's data. The Accounts canonical bag-order report
already separates order dates, receipt activity and undated receipts, but is restricted
to authorized Accounts report users and must not simply be opened to Mill staff.
Preserve existing role/entity restrictions when replacing the competing report paths.
No bag stock quantity or saved receipt has been changed in this investigation.

Rice Ledger PR275 final head0649bd72 passed full Accounts38966386607 and
Milling/Exports38966386316; merged38fd57e59ddc505a73fce6192d689d86f15777c3.
Production deployment and fresh Mac Chrome output checks are pending.

## Production/recovery report entry scope (prepared)

The report entry functions previously mounted the shared report without passing the
selected mill/company. It could inherit the dormant production form's company and
open all mills. Pass the current Milling company and mill explicitly. Preserve an
existing report's filters when the scope is unchanged; mount a fresh child root when
scope changes, so late responses only own detached content. Replace the competing,
unscoped legacy brand total table with a link to the canonical production report.
No stored brand specifications are deleted. A DOM test executes the actual entry
functions for TTI then BRM/another mill and verifies filters, root detachment, hidden
panels and the brand-report route; existing canonical recovery UI tests also pass.

## Additional bag workflow findings, C19/C20

Disposable source-function probes are stored under probes/. They are diagnostic
failed audit results, not passing application acceptance tests. C19 reproduces the
bag-list renderer's automatic renumber/write when duplicate source IDs occur.
Renumbering historical IDs without receipt/link mapping must not be treated as a
safe migration repair. C20 enters a20-bag transfer out of100: the source records20
transferred and claims destination stock updated, but creates no destination receipt
or order. Repository searches found only source deductions/report display consuming
that transfer array, with no receiving consumer. The destination company/location
and receipt acknowledgement require a canonical transfer workflow. No actual bag
record was renumbered, transferred, deleted or posted by these probes.

## 17 October recovery and report

Local commands and GitHub access respond again. The last interrupted checkpoint
command did not save/commit its intended coverage/readiness updates. HEAD remains
e7bc7f4 with the Export Bags Ledger report repair and its before/after fixtures
uncommitted. PR278 is confirmed merged as ee25a145fa43a9d8116732541bd3d121b974bbcc.
The required Browser control tool is not exposed in this session. The current Chrome
panel state could not be rechecked. A fresh local DOM test stopped at missing jsdom:
temporary dependencies from the previous session are gone. No new application test
pass, deployment or browser acceptance is claimed. STATUS-2026-10-17.md provides
the consolidated report, including the pending repair and remaining migration gates.

## 17 October signed-in live continuation and Rice Ledger delivery defect

Chrome control returned and Salman signed in. Actual own-mill Rice Ledger opened,
but Generate left its initial message unchanged. DOM script references included
/milling/rice-ledger.js. Source tracing found Milling's deliberate `Require all denied`
folder protection; PR275 introduced a direct script dependency into that folder,
while the older production helper was already delivered inline by module.php.
This is a defect in the audit's Rice Ledger repair, missed by its standalone DOM/
endpoint tests. Deployment success was not functional acceptance, and no passing
live Rice Ledger result is claimed before this correction.

Deliver the ledger source inline through the authenticated module, preserving the
folder denial. A new positive test renders the real module.php in a disposable CLI
fixture, checks the actual ledger initializer is inline with no denied external path,
parses every emitted inline script, verifies forbidden users receive403 without
source and a missing required ledger asset produces503. It passes locally. Extend
the existing authenticated HTTP module test to assert the same delivery path.

The pending bag-ledger report repair is included in this stock-report batch: selected
company/mill, parent production scope, genuine openings, movement-independent balances,
Till cutoff, explicit undated history and ambiguous-ID refusal. Saved before/after
fixtures retain expected25 versus old-5/125/525 and corrected25 in each case. No stock
writer, existing ID or destination-transfer record is changed. Used-bag scope, automatic
bag-list ID renumbering and destination transfer receipts remain separate open defects.
Temporary jsdom installation cannot resolve the npm registry in this local environment;
DOM tests will run in the established hosted runtime. Previous DOM results are retained
as historical evidence; the real PHP renderer test is a fresh local pass.

Live permission screen verification: all historical inputs/actions are disabled and
the explanation correctly says the drafts do not grant access. Its new Console link
pointed to /admin/ and actually opened403. Correct that audit-introduced route to the
real authenticated /index.php entry. Strengthen the permission test with existence
and authentication checks for the actual linked entry rather than accepting an
invented directory. No actual user grant is changed.


### 17 October continued: PR279 and C19 prepared protection

Both final-head PR279 suites passed (Accounts39012882204; Milling/Exports39012882228). Merged as c9bda30904ef99b93d8c33aa65e4c44ff3b4bb2a. Main QA and production delivery/live acceptance pending. Current deployed PR278 independently confirmed via38968355570/job117200326455; backup, users0, PostID changes0 and healthy login.

C19 prepared correction makes bag identity normalization a pure validation/derived copy. Missing, duplicate, unsafe or noncanonical numeric IDs stop the list and stale receipt/status/transfer actions without writing. It never assigns replacement IDs or repairs history. Local actual-function regression test and preserved probe show [7,7] unchanged,0 writes. Canonical numeric-string IDs remain usable as in the old view. Full hosted QA and deployment not yet claimed.

Actual POST45 balances PKR29943000 (USD108000 at277.25). Its edit dispatch sees confirmed tgRemittanceId, correctly requiring linked TG reopening first; the current generic switch-company message lacks context. No financial correction made. FI matching requires2-day unique date proximity; actual25/22Sep versus receipt1Oct explains no match, without authority to guess allocation.


### PR279 live acceptance; PR280 merged

PR279 production run39013354578/job117340327907 succeeded: DEPLOYED_SHA c9bda30904ef99b93d8c33aa65e4c44ff3b4bb2a, backup retained, changed users0 and healthy login. Mac Chrome now generates canonical Rice Ledger: arrivals30500+39000+50000=119500kg; period24–30Sep opening119500/period0/closing119500 even with Outward-only display. All three source references remain visible in the full-period report. No claim of physical stock reconciliation. Mac CSV download inspection timed out and reset browser tooling; actual CSV acceptance is unverified, not an application failure. Chrome was recovered with existing tab/account intact.

Corrected Milling permissions link opened the authenticated Console at /index.php. Export Bags Ledger opened and explicitly showed no matching movements for selectedTTI/TTI Rice Mills. Positive closing/period cases remain controlled fixtures. That older report lacks print/CSV controls and shows From even in Till mode; these remain open UI/output gaps.

Both PR280 final-head hosted suites passed39013302325/39013302356. Merged as7475f111271c1fb315eb1326c916b1a43e796176; main QA/deployment pending. No production identity repair or renumbering performed.

Live Labour/KE/rent/salary controls inspected without saving. Labour agreed-rate guard behaved correctly. Salary Cost — Accounts opens a separate operational reference sheet; launcher wording remains misleading. R07/R08 acceptance requirements source-traced in MILL_HANDOFF_REQUIREMENTS.md.


### PR280 deployed; actual wrong-mill order exposure prepared fix

Deployment39013780419/job117341567768 confirmed7475f111271c1fb315eb1326c916b1a43e796176, backup, changed users0, healthy login. Actual bag list opens; no replacement IDs assigned. It exposed unscoped actual ASAS/Indus orders under selectedTTI Rice Mills alongside GHAZAL5508/TTI. Prepared next batch scopes order display and stale receipt/status/transfer and production selection, scopes issues through original production, rejects ambiguous transfer sources/fractions and replaces false destination-updated success with an unverified-receipt statement. It does not implement C20 receiving or multi-PO consumption allocation. New local actual-function scope regression passes.

PR279 live smoke39013404296/job117340469064 passed17/18, including50 ledgers/three balanced entities and all4 actual recovery modes (0 production). Six Masters round trips passed; seventh lacked masterBackTop for30seconds despite index200. Root cause unverified. Failure artifact12094191462 exists; inbound download URL could not be retrieved by local DNS. Prepared minimal failure-only browser diagnostics keep the12-round assertion unchanged. PR280 fresh smoke39013826582 pending.


### Original bag marking display added to pending PR281

Actual Chrome GHAZAL receipt conditional tare field passed:122 persisted while hidden/reshown, with no receipt save. The Artwork control instead opened hardcoded GHAZAL BAG ARTWORK text headed Approved Artwork. C22 prepared fix renders the actual linked image and filename, never invents approval, handles missing/failed images visibly and rejects external/unsafe references. The module bridge now retains file-backed artworkDocument download/data references. Existing document authorization is unchanged; a403 remains a visible failure, not a bypass. Local actual-function tests cover embedded/protected/file-backed images, stale failures, unsafe URLs and wrong/ambiguous order scope. Full hosted/live acceptance remains pending on the revised head.


### C24 — 119500kg cannot be accepted as TTI Rice Mills stock

User challenged the mill location. Actual Mac Chrome source tracing now confirms original23September Pohanch PH-00001 / ABC-123 / Soda26002, PH-00002 / ABC-345 / Soda26001, PH-00003 / TK-123 / Soda26002, all shams broker. Original Accounts search confirms both Purchase Soda26001 and26002, dated11September2026, list indus rice mill. This conflicts with Rice Ledger grouping30500+39000+50000=119500kg under TTI Rice Mills. The earlier statement accepting that total as verified TTI stock is withdrawn; only arithmetic/rendering was verified.

Source inventory_reconciliation.php defaults missing millName/locationName/mill/stockLocation to TTI Rice Mills. This is a possible contributor, not yet proof the three saved receipts lack location fields; explicit wrong receipt metadata is another possibility. Inspect exact saved Pohanch fields, sourceSodaId/locationId and actual unloading before correction. No records moved, reprinted (which writes printed status), saved or amended during tracing. Coverage Rice Ledger marked failed for source location integrity.


## 19 October — accounting reports and Mill references

PR349 adds paid-basis Zakat expense reporting with original ledger/control holds, source-linked commodity quantities and non-monetary reconciliation of Mill rent/salary references to existing canonical payments. This supersedes the earlier C09 negative-expense/C12 journal-only/R08 no-link implementation descriptions. Exact evidence and remaining annual monetary/source/UI gates are in `ACCOUNTING_REPORTS_AND_REFERENCES_2026-10-19.md`; no overall audit completion is claimed. Financial B/F never creates physical stock, and actual physical openings remain separate inputs.


PR352 closes the two source-specific false holds identified after PR349: asset-only salary advances do not block Zakat, and original outside loads retain their unique native parent’s explicit company in the quantity view. Live `39229102040` passes 23/23 with 0 quantity holds, 0 Zakat payment reviews and 0 financial/stock/reference-link writes. One Balance Sheet classification review remains. C12 quantity reporting is now passed; the register has 112 passed / 34 open or partial action rows, with annual monetary mapping, C24 warehouse originals, historical documents, formal acceptance and final MacBook review retained. See the accounting continuation report for exact release evidence.


## 19 October owner defects and permission editor release

PR354 (`ccc8d095...`) releases persistent private home JV decisions until X, explicit Director JV/company approval authority, permission-filtered multi-workspace Home, incoming bank/Received from labels, separate Rice/Maize review and the Exports delayed-logout null fix. Full feature/main QA passed; production deploy39240712717 succeeded. Live39240809713 passed23/23 with53 ledgers/three companies,0quantity holds,0Zakat payment reviews and one retained BSclassification review. Startup39240809718 first login timeout remains recorded; unchanged retry passed998ms usable Accounts. Real Mac Chrome confirms receipt labels and Maize filter without financial writes.

PR355 (`e8ee6606...`, exact treeeab641b69e03bb0ab591c116d91f8e1f6aa52152) fixes the proven missing Accounts Delete editor/cleaner action and six-column layout. Full feature39241274856/39241274830 and main39241793874/39241793908 passed. Production39242212776 confirmed exact SHA/healthy login/changedUserCount0/journal-ID changeCount0. Startup39242306616 passed770ms usable Accounts. Tayyab's real Director form is prepared with exactly23 new Delete inputs includingTTI/BRM/TG; **no saved grant expansion has occurred**. Browser security policy requires action-time confirmation before Save. All other existing grants and role are unchanged.

Coverage153:120passed,20partial,4failed,6not tested,2blocked,1intermittent;33open/partial action checks. Annual Zakat form already exists; monetary counterpart/legacy BSclassification, original warehouse/documents, positive real production/cost and wider/formal acceptance remain unclosed. No physical stocks inferred from financial B/F, no fabricated positive production/payment and no original stock/document reassignment. Exact release evidence and permission-review boundary are in ACCOUNTING_REPORTS_AND_REFERENCES_2026-10-19.md. Do not claim the whole audit100% complete.

Final PR355 live smoke39242306584/job118025803153 passed23/23 in2.8minutes:53ledgers/3companies,0quantity holds,0Zakat payment reviews,1retained BSclassification review,0financial/stock/assessment/reference-link writes. Actual production remains0;8shipment contributions unavailable/5historical openings. Exact code-release acceptance is complete; original-source and wider/formal gates remain open.

## 19 October — Director is now an automatic business role

Salman explicitly superseded the individual-permission proposal: selecting Director must supply full business control one level below Super Admin, with no checkbox selection. PR358 (`0aace6a...`) derives all four modules, TTI/BRM/TG, all locations, operational actions and Master permissions from the current authenticated Director role. It hides the matrices and location choice behind a compact summary. Existing Directors, including Tayyab, need no user-record Save. Ordinary staff retain explicit custom scopes; a role downgrade restores those scopes without newly persisted synthetic all-grants. Owner user/security/backup/lock/purge controls and all business validation remain enforced.

Full feature and exact-main suites passed, deployed role startup passed, and live39245919672 passed 23/23 with 53 ledgers in three companies and no financial/stock/assessment/reference-link test writes. Native desktop/mobile acceptance includes actual no-box Director creation and a saved granular staff downgrade. Connected Mac Chrome confirms Tayyab's automatic summary; the old checkbox draft was cancelled without Save. The earlier pending Delete-grant confirmation is superseded for already assigned Directors; future actual browser account creation/promotion still follows its applicable action-time access policy.

PR359 fixes the invisible Active switch input extending 26px outside the compact dialog. Its full feature and combined-main suites passed. Concurrent PR357 salary-opening work is preserved in final source `4b7834a6f62c7cf12aa67c38d6fdf630c8261864` / tree `a7139c93838df6c263de32691611fd3c6cd1de45`. Deployment39247182375 confirmed exact SHA, healthy login, changedUserCount 0 and journal-ID changeCount 0. Actual Chrome now measures dialog width760 / scrollWidth760 and a44px switch, with the matrices hidden. Startup retry39247392099 passed838ms usable Accounts. First post-deploy runs were cancelled alongside duplicate skipped triggers; no application failure assertion is attributed to that cancellation. Exact final live evidence follows in DIRECTOR_ROLE_PRESET_2026-10-19.md.

Coverage is now 154 action checks: 121 passed and 33 open/partial. The original warehouse/issued-document, annual Zakat monetary/opening classification, real production/cost and wider/formal audit exceptions remain. The annual Zakat form already exists. Financial B/F remains ledger-only and creates no physical rice or bags. No whole-audit 100% claim is made.
