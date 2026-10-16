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
permissions or require failure-log classification; retain raw logs at
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

## Prepared fixes — not deployed

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
permission migration tests pass. Required hosted QA and remaining audit work are
still outstanding. No whole-application or data migration sign-off.
