# Whole-software functional and linkage review — 16 October 2026

**Status: defects found; not a clean-software or exhaustive visual sign-off.**

Baseline: production/main `1beeac3f880fda95ab2dd722e653bdab0ee067ab` (PR262).
Scope: Accounts, Exports, Milling, Super Admin, and their Directors approval/master
dependencies. This review branch changes diagnostics, documentation and a stale QA
selector only. It does not change or deploy business logic, alter real users,
post transactions, remove fields, or consolidate masters.

## Evidence and coverage

| Review layer | Result | Practical limit |
|---|---|---|
| Tracked PHP and JavaScript syntax | 521 files passed | Syntax cannot establish correct business treatment |
| Existing workflow test commands, run independently locally | 200 attempted; 177 first-pass successes; Exports DOM passed on dependency-correct rerun, giving 178 functional successes | 22 browser-launch attempts were blocked before application assertions; MySQL and optional Python-browser variants were not enabled locally |
| Super Admin fixture | All 7 navigation views activated; 12 master types and 135 schema fields inventoried; route, lock, audit and user-edit probes run | Fixture, not a signed-in owner session |
| Milling fixture | 14 panels opened without captured startup/navigation errors; positive-data cost, bill, rent, permission and inbound-Soda probes run | No physical production or real accounting writes |
| Static Milling controls | 84 buttons/tiles, 146 form controls, 106 inline handler references; all referenced function names found | Handler existence is not proof every button works with every record/role |
| Exports current DOM audit | Passed with required jsdom 27; process/lot tiles, production and multi-source loading exercised | Document geometry requires real browser/PDF verification |
| Existing release CI | Accounts and Milling/Exports suites passed for baseline | Fresh review-run results below must be considered separately |
| Manual Mac Chrome visual QA | Unavailable: associated Browser app control tool not exposed | No claim that the signed-in Mac tab was inspected |
| New live production recovery | Prior live API returned zero source runs | Mill must run and record independent input/output before real totals can be checked |

Source inventory: Accounts 108 files/6,147 lines; Exports 16/3,878;
Milling 2/1,351; Admin 9/2,743; Directors 6/136; API 151/13,352.
These are source counts, not numbers of independently verified screens.

Fresh branch [Accounts QA](https://github.com/salpiracha-cmyk/transtarde/actions/runs/38947118019) and [Milling/Exports QA](https://github.com/salpiracha-cmyk/transtarde/actions/runs/38947117730) both passed at review commit `7eea2ea23e96680c73358d9236144844b84ea2d4`, including the configured MySQL and hosted browser jobs. This resolves local browser-runtime limitations for the existing required Accounts cases; it does not validate the independent defects automatically.

Existing release evidence:

- [Accounts QA](https://github.com/salpiracha-cmyk/transtarde/actions/runs/38890251160)
- [Milling and Exports QA](https://github.com/salpiracha-cmyk/transtarde/actions/runs/38890251054)
- [Production deployment](https://github.com/salpiracha-cmyk/transtarde/actions/runs/38890544586)

Fresh review runs (completed results):

- [Authenticated read-only live smoke](https://github.com/salpiracha-cmyk/transtarde/actions/runs/38946007433): **18/18 passed**; 50 ledgers across TTI/BRM/TG reconciled, all three trial balances balanced, balance-sheet differences zero, no startup POSTs and zero financial writes. Recovery endpoint again returned zero production source runs.
- [Three-module disposable walkthrough](https://github.com/salpiracha-cmyk/transtarde/actions/runs/38946032748): failed on obsolete container selector; the stored `#contNo` is intentionally hidden behind visible split entry. Diagnostic test corrected on this branch; this failure does not establish a broken user input. The [corrected rerun](https://github.com/salpiracha-cmyk/transtarde/actions/runs/38947157532) passed the container save and return-to-Exports check, then stopped on another obsolete Freight Agreed search selector. Its later supplier bills were not reached.
- [Remaining Accounts disposable walkthrough](https://github.com/salpiracha-cmyk/transtarde/actions/runs/38946055403): 5 failed scenarios; obsolete UI/fixture assumptions need updating before this suite can provide end-to-end assurance (details R12).

Machine-readable local results: `local-checks.json`, `syntax-checks.json`,
`console-probe.json`, `milling-probe.json`. They retain initial failures and reruns.

## Confirmed defects and misleading controls

### R01 — Directors does not open from the Console (high)

`admin/app.js` defines Directors with no `href`, "Awaiting module" / "Not connected".
`openModule` therefore shows a status toast instead of opening the existing
`directors/index.php`. Both module cards and sidebar use this launcher.

Fixture: clicking the Directors module card opened zero windows while the actual
protected route exists. Add the real authorized route and accurate status, keeping
the server's existing role/permission checks.

### R02 — Editing a user drops Directors permissions (high)

`MODULE_ICONS` in `admin/app.js` contains Mill, Exports and Accounts only.
The editor builds its permission matrix from that object; `saveUser` reconstructs
the complete permission payload from visible checked inputs. The unused
`admin/directors-permissions-override.js` is not loaded by the Console.

Fixture: an existing ordinary user had Accounts Reports View plus Directors
Dashboard View and TG Master View/Edit. The unchanged form submitted only Accounts
permissions; there were zero Directors checkboxes. No real user was edited.

Provide the canonical Directors permission editor and preserve authorized existing
permissions during any unrelated amendment. Test edit/save/reload with a non-owner
user and both allowed and denied pages. This is a priority before normal staff edits.

### R03 — Milling's Users & Permissions is a second, ineffective editor (high)

The visible Milling tile opens fields for username, role, mill, module and actions.
`saveUserPermission` writes `tt37users`, an operational list. Authentication and
actual permissions are stored through `api/users.php` / `auth_store.php`; no
authorization code consumes those operational rows. Saving the fixture issued no
user-management request while showing "User permission saved".

Replace this operational editor with a permission-controlled link to the one
Console Users & Permissions source. Preserve historical operational rows for
reference until a deliberate migration/removal decision; do not import them as
actual access grants automatically.

### R04 — Module Locks promises protection it does not implement (high)

`toggleLock` changes `state.locks`, calls `saveState`, and stores the result only in
`transtrade_super_admin_v1` localStorage. No server request occurs. Runtime module,
master and deployment code do not consult that browser state. The confirmation
nevertheless promises that no cross-module change can alter the locked module.

Fixture: Exports became "Locked" with zero requests and a browser-local flag.
Decide whether this is a development approval register or a real administrative
control. Until decided, remove the protection claim; do not invent an operational
posting freeze from a development label.

### R05 — Console Audit Trail is not the central server audit (high)

`renderAudit` and Recent Activity read browser-local `state.audit`. Server actions
are recorded with `tt_audit` in a different persistent store; the Console does not
load that log. Opening another browser cannot reliably show the same administrative
history. A supplied server-audit sentinel was not rendered in the fixture.

Use an authorized, read-only server audit feed with actor, timestamp, action and
source reference. Keep posting/journal audit history in its existing authoritative
store and link to it; do not silently imply one local list contains all activity.

### R06 — Older Milling cost/labour calculations use wrong scope and input (high)

Milling → Labour / Mill Expenses → Production Cost / Kg still calls
`renderMillingCostReport`. It sums **arrivals**, labelling them "Raw Rice Input",
instead of actual consumed production input. It filters dates but not selected
mill/company. `generateLabourDraft` similarly sums arrivals, production and loading
records without consistently selecting the current mill/company.

Fixture: selected TTI mill, TTI arrival 1,000 kg and another-company arrival
9,000 kg. Old cost screen showed 10,000 kg. Labour draft charged 10,000 kg at
Rs49/100kg = Rs4,900 instead of selecting only the relevant work. This also
demonstrates why production input must remain independent of arrivals.

Consolidate this old cost screen with the actual-input/Accounts-costing services.
Keep arrival handling charges distinct from milling charges: the correct charging
basis must follow the agreed labour rate, not a guessed production equivalent.
The new actual recovery endpoint is separate and its tests remain passing.

### R07 — Mill bills claim an Accounts liability without a complete handoff (high)

`saveKEBill`, `saveLabourBill`, non-cash `saveProcessingExpense` and
`saveReprocessBill` store operational bills and call `postAccountsLiability`.
That function writes `tt38accountsfeed`, with generated identity/reference/amount
but no robust source record link, mill or legal entity. The active Accounts bridge
does not consume that feed; repository tracing found no Accounts bill/payable
consumer. The production costing engine reads some original operational bill
stores for **cost valuation**, which does not create a payable or journal.

Fixture labour bill saved an operational row and feed entry for Rs4,900, with no
Accounts bill or accounting endpoint call. The success text says an Accounts
liability was created. Treat the observed result as an estimate awaiting a proper
handoff, not a posted payable.

Implement one acknowledged, idempotent Accounts review/bill route with company,
mill, original source ID, supplier, dates, amounts and accounting treatment. Show
queued/failed/approved/posted states truthfully; do not auto-post legacy estimates.

### R08 — Mill rent/advance forms mark payment without a linked posting (high)

`saveRentPayment` appends `tt39rentpayments` and labels the result "Paid by Accounts".
It collects no real payment account or Post ID and calls no financial endpoint.
`saveSalaryAdvance` similarly updates `tt39salaryadvances`, separate from the
canonical Accounts advance and monthly salary settlement engine.

Fixture rent Rs25,000 saved as "Paid by Accounts" with zero accounting requests.
No real payment occurred. Mill should show the canonical Accounts posting/status,
or an explicitly labelled request/history reference, rather than duplicate payment
entry. Preserve existing rows until matched/reviewed; never post them en masse.

### R09 — Reading purchase Sodas can trigger an operational save (medium)

`loadPurchaseSodas` calls `syncExMillPurchaseSodas`; its migration wrapper calls
`TT_SHARED_SYNC.saveNow()` when derived Ex-Mill arrays change. This path runs at
startup and on opening Arrival List/Ex-Mill, without the final explicit Save.

Fixture: a newly returned committed Ex-Mill Soda caused one `saveNow` call and a
derived record even though no final-save action was clicked. This is an observed
save request in a stubbed bridge, not evidence that a real financial journal posted.
Reconcile committed read models in memory; persist required migrations through an
explicit reviewed action or authorized server-side migration with conflict checks.

### R10 — Legacy master forms conflict with the central masters (medium/latent)

The Milling `#masters` panel remains in delivered source but has no direct
`openPanel('masters')` navigation; header M goes to central Master Records.
It contains:

- Hard-coded Meezan, Alfalah, HBL and internal bank account examples.
- Season/status/variety dropdowns in a static Broken Deduction table with no data
  binding or save path.
- A separate salary master `tt39salarymaster`, separate rent/cost master stores,
  and an old prototype import/export labelled for Irfan test data.

The central Salary & Staff master and Accounts approved fixed-overhead engine use
different authoritative sources. These old settings can drive old local screens,
but they do not establish canonical Accounts salary/rent/payments. These are
latent delivered remnants, not a claim all are reachable through normal navigation.
Archive/remove after checking historical dependencies and move useful configuration
to one Master Records area. Do not delete historical records.

### R11 — Add Mill does not acknowledge the shared master result (medium)

`addMillPrompt` adds a local/operational mill, then `syncLocationMaster` fires a
request whose HTTP/body result is never checked and whose errors are swallowed.
A forbidden or failed master save can leave the mill visible operationally while
Soda/Accounts/Exports masters do not receive it. This is confirmed source behavior;
a failure-injection browser case remains to be added.

Use the canonical master operation, await acknowledgement, display errors, and
only advertise the linked location after success. Preserve the draft on failure.

### R12 — Older walkthrough tests no longer follow the actual UI (medium)

Fresh three-module video test stopped while filling hidden `#contNo`; the user
control is now `.tt-container-main` and `.tt-container-check`. This branch corrects
that selector to use the visible controls and asserts the resulting stored value.

Remaining Accounts walkthrough failed 5/5. Observed stale assumptions include
waiting for an old Soda alert, expecting salary advance immediately rather than
opening it, treating supplier autocomplete as a select, and looking for the old
"All Ledgers" label. The credit-advice POST returned HTTP 200 and its following GET contained one receipt; the trace screenshot shows the current green posting dialog with Edit and Print. Its old `Print Receipt Voucher` text assertion is obsolete. These runs cannot certify their later actions. Retain the
failures and update fixtures/selectors/assertions without weakening the business
validation or assuming a timeout is an application defect.

### R13 — Older Rice Ledger is not a canonical stock ledger (high)

`renderRiceLedger` builds a separate movement table from arrivals and sales/loading;
it does not select company/mill and does not incorporate the actual production
input/output transformations used by inventory reconciliation. Its running balance
also starts at zero after filtering a date range, without a brought-forward balance.

This is a source-confirmed competing report, not a validated replacement for the
current stock service. Route operational stock ledgers through canonical movement
and identity projections, retain true period opening, and keep raw/finished/byproduct
stages distinct. A positive-data opening/production/sale/reversal fixture should
be the acceptance test before using it as a stock reconciliation report.

### R14 — Hosted visual evidence reveals cramped amounts and misleading alerts (medium)

These observations come from current hosted **disposable fixture** screenshots,
not the user's Mac Chrome. The browser tests can pass while these details remain.

- On the TG multiple-invoice/advance phone view, amount labels fall into the first
  30px grid column. USD100/50/200 appear as tiny clipped inputs (1/5/2). The mobile
  `.tter-item` rule has two columns but its third child amount label is not placed
  across the useful width. Place it under the description with enough numeric width;
  assert full visible amount text, not merely an unchanged underlying input value.
- Bank Finance on a phone wraps a facility name into isolated characters while
  putting three actions in a narrow right-hand column. Use a compact record card
  or a deliberate scrollable table; keep actions in one consistent row.
- A normal freight quantity summary (contract/already agreed/remaining containers)
  is placed in `#ttFreightAgreementError` with `role="alert"`; the screenshot shows
  this harmless information as a floating red error over the next bill form.
  Give informational summaries a normal inline status and clear screen-owned
  messages when leaving their screen.

Preserved examples: `visual/tg-invoice-phone.png`, `visual/bank-finance-phone.png`,
`visual/freight-information-alert.png`. These screenshots show the reported
conditions; they do not prove every visual layout has been reviewed.

### R15 — Shipment bill success panels omit the required Edit action (medium)

`showPosted` in both Transport and the shared service/freight bill entry generates
Pay This Bill, Post Another Bill and Print controls, but no Edit action. The hosted
Transport success screenshot confirms it. This conflicts with the standing
post-result rule requiring separate Edit and Print actions.

Connect Edit to the existing source-owned amendment route with bill/journal
identity, reason, allocations and paid-bill safeguards. Do not substitute an
unrestricted generic JV edit or reverse a bill just to open its form.
Evidence: `visual/transporter-post-result.png` and
`accounts/accounts-accounting-desk.js` transport/service `showPosted` functions.

## What belongs where: consolidation proposal

No fields/controls were removed based only on this proposal.

| Item | Current situation | Proposed home |
|---|---|---|
| Companies, owned bank accounts and retention/default bank | Central company master already owns them; old Mill sample table remains | Master Records → Companies → Banking; operating screens select/read it |
| Staff login, module/location access | Central Users & Permissions plus ineffective Mill editor | One Console Users & Permissions editor; permission-controlled links elsewhere |
| Staff recurring salary, rent and production overhead setup | Central Salary & Staff/Accounts plus legacy Mill duplicates | One authorized master group; Mill shows approved read-only costs/status |
| Purchase products, grades and Kat rules | Central purchase masters; static old Mill deduction controls remain | Master Records → Products & Quality, including dated rules |
| Labour agreement rates and arrival quality defaults | Mill-local configuration with unclear central access | Master Records → Mill Settings, with dated revisions, mill/company scope and existing permissions |
| Bag GST | Separate Accounts bag-report master view | Master Records → Bag Tax Rates; keep date-effective history |
| Saved utilities and credit cards | Contextual setup inside expense workflows | Master Records → Recurring Expenses, plus permission-controlled contextual Add/Edit |
| Brokerage / freight rate masters | Directors and specialized workspaces | Master Records → Commercial Rates, preserving Director authority and historical effective dates |
| TG company rates, closing and compliance | Directors TG Master | Master Records → TG Settings with existing restricted access; alerts remain in Directors |
| Document reader provider setup | Separate Super Admin sidebar route | Console Settings / Master Records group, provider health remains visible |
| Backups/restore and real approval decisions | Administrative actions | Keep distinct administrative/approval areas; these are actions, not ordinary reference masters |

## Input usefulness and output lineage

Do **not** delete notes, contact details, reference documents or compliance fields
merely because they do not affect money. They can be intentional historical or
operational evidence. The important distinction is whether the screen clearly
states their purpose and whether they are stored/retrievable.

| Input group | Established consumer/output | Review outcome |
|---|---|---|
| Console company/bank fields | Accounts bank choices, transfers, receipts, statement identities | Canonical source exists; retention/default tests passed |
| Customer/notify/consignee masters | Active export document identities; closed-lot snapshots | Existing document/master propagation tests passed |
| Purchase product, stage, grade, Soda terms | Mill arrival/Ex-Mill selection → Accounts bill/payable → stock/cost pools | Linkage/routing/quantity tests passed; older Mill reports do not consistently reuse them |
| Export contract/packing/brand | Bag PO and production/loading instructions → Mill actuals → shipment documents | Existing DOM/bridge tests passed; refreshed full walkthrough pending |
| Mill actual input/output/brand/contract | Stock movement, daily production/recovery and brand/contract recovery | Sample/isolated tests passed; real production figures unavailable |
| Mill KE/labour/expense records | Some production conversion-cost sources | Useful for valuation; payable handoff incomplete (R07) |
| Mill user permission fields | Only `tt37users` display list | No actual login-permission consumer (R03) |
| Mill rent-payment and advance fields | Only old operational rent/salary screens | Do not establish Accounts payment (R08) |
| Static Mill season/status/variety selectors | No bound calculation/save | No functional effect in that old master block (R10) |
| KE units | Stored on the original bill | Reference information; no claim of energy-efficiency reporting without a consumer review |
| Canonical Accounts salary/rent/advance and bank receipts | Authoritative journals, payables/receivables, ledgers and report projections | Existing focused tests passed; retain these as financial source |

## Cross-module acceptance matrix

| Flow | Checks/evidence | Remaining gap |
|---|---|---|
| Soda → Mill product/location → Pohanch → supplier bill | Soda routing, master compatibility, ready-rice HTTP, original bill/party tests | End-to-end video uses stale controls; refresh before full sign-off |
| Pohanch Kat/quantity → provisional receipt → final bill → broker | Rule, supplier discount, ledger-narration/control tests | Real data review beyond current ledger smoke not performed |
| Ready Ex-Mill purchase → instructions → container lifting | Authority, product match, quantity reservation, linkage tests | Inbound read-trigger save R09; corrected video confirmed own-Mill container return; full Ex-Mill fixture still required |
| Export bag PO → receipt/spec → cumulative bills → Director decision | Bag report, history, bill, approval integrity and inventory HTTP tests | Physical supplier quality/weights can only be confirmed from actual receipts |
| Mill production → stock → actual recovery → costing | Inventory, actual-input, production-cost and report UI tests | No live production; reprocessing valuation is explicitly held for review |
| Mill bills → Accounts creditors/payment | Source tracing and positive fixture | R07 incomplete handoff; no clean sign-off |
| Mill rent/salary → actual payment | Positive operational fixture + canonical Accounts tests | R08 competing entry paths |
| Export invoice/FI/GD → credit advice → customer ledger | FI link, receipt scope, TG mirror/remittance, amendment and customer tests | Old receipt walkthrough requires diagnosis |
| Bank/payment/cheque → party ledger → Post ID/TB/P&L/BS | Existing backend/DOM tests and baseline live ledger reconciliation | Manual all-role visual audit unavailable |
| Directors/Super Admin → decisions | Shared endpoint and approval integrity tests | Console launcher/editor defects R01/R02 |
| Company/party master edits → module forms/documents | Master-role and Export DOM propagation checks | R11 location acknowledgement, scattered masters R10 |
| Login/session/startup/save conflicts | Existing authorization/session/startup tests | Fresh live smoke passed 18/18; no signed-in Mac inspection |

## Recommended implementation order

1. Preserve Directors access on user edits; repair the actual Directors launcher.
2. Replace ineffective permission/payment screens with canonical routes or clearly
   labelled read-only history. Correct misleading saved/posted/locked claims.
3. Complete Mill bill handoff with acknowledged, idempotent Accounts review and
   correct legal-book/mill/source identity. Reconcile existing estimates first.
4. Retire competing stock/cost calculations and use one actual-input/stock/cost
   projection with period opening and company/mill filters.
5. Remove navigation-triggered saves and acknowledge new master creation failures.
6. Approve the master consolidation map, then migrate useful controls while
   preserving permissions, dates and historical records.
7. Refresh stale walkthrough fixtures and finish remaining visual checks, including
   responsive layouts, print/PDF geometry, every action and restricted roles.

No assertion is made that all buttons, every historical posting, all output
documents, every responsive layout or every live role has passed this review.

## Delivery status

Fifteen findings are recorded (R01–R15). The current production release remains unchanged. [Draft review PR263](https://github.com/salpiracha-cmyk/transtarde/pull/263) retains this report, positive-data probes and sanitized fixture evidence. The follow-up commit only records final results and screenshot evidence; it changes no application/test logic. Required Accounts and Milling/Exports suites both passed on the preceding review commit. The three-module walkthrough remains partially blocked by stale Freight Agreed selectors, and the older remaining-flows walkthrough requires updates. Manual all-screen Mac Chrome testing, all-role acceptance, exhaustive print/PDF review and actual mill production figures remain outside this completed technical audit pass.
