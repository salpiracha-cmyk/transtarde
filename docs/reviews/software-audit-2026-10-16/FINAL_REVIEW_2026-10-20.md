# Full audit continuation and closeout evidence — 20 October 2026

This report reconciles the complete 156-scope register. The register measures
business acceptance, not the number of times an automated suite has been run.
The preceding checkpoint was 125 accepted scopes and 31 scopes with remaining
acceptance. Repeated green tests do not turn absent original records, physical
printing, a future migration or an unseen office PC into completed business cases.

## Repairs from this continuation

- PR435 updates the live SODA check for the approved inline search and removal
  of Search/Amend. It tests searching an absent reference and clearing the search.
- Final live Chrome inspection found that shared master autocomplete inferred
  Supplier from the new SODA search placeholder. PR442 keeps that free search
  outside master autocomplete. The native SODA test now loads the real shared
  autocomplete and verifies no supplier role is added before checking historical
  search, row details, amendments and filtered printing. The earlier isolated
  SODA fixture did not load this interacting module; that gap is now explicit.
- PR435 adds exact, complete application-source verification to deployment.
  The previous gate checked only `exports/app.js`. The CLI verifier now checks
  all tracked application files, including the account chart and reconciliation
  endpoint, against this release only. It runs outside the public application,
  is removed on exit, and stops/restores code on a mismatch. No customer data is
  included in the manifest. Existing rollback-retention controls remain in place.
- PR438 validates actual calendar dates for Salary Master effective dates,
  Brokery effective dates, KAT dates and company registration expiry dates.
  Impossible dates are rejected before a save. Existing source dates are not
  migrated or guessed. Valid leap days and optional blank fields remain usable.
- PR438 rejects non-finite salary/Brokery/KAT figures. Salary Master encodes
  before truncation and refuses damaged Accounts storage instead of replacing
  it with seeded salary data. No historical financial entry is rewritten.
- PR438 prevents a new/retargeted company from sharing another company's short
  code under the master write lock. Unchanged historical identities remain
  editable; no company or bank is automatically merged.
- PR438 adds actual-auth create/amend/reload tests for all ten visible master
  families, canonical customer/KAT/reference consumers, advanced-field retention,
  invalid-input refusal, and unchanged original financial journals.
- The concurrent retained-salary UI now requires explicit cash/bank choices.
  The older native salary scenario is updated to test that behaviour: reducing
  cash leaves a retained balance, explicitly selecting Bank allocates it,
  deselecting Bank restores retention without altering the cash amount, and
  saved bank references survive reopening. This changes test expectations,
  not the owner's approved payment design or payroll accounting.

The first PR438 candidate exposed a standalone Salary Master helper dependency
in existing isolated tests. That dependency was removed without weakening date
validation. Both earlier failed runs remain part of the history. Exact final
release acceptance must be recorded below before calling the repairs deployed.

## Current original-source and Chrome evidence

On 20 October the protected original-source audit, run39437074264/job118676890154,
matched all 458 application files to6b08ca047f86b209132b44bf60ec382149ae1a94.
It independently downloaded all35indexed shipment archive revisions,
174454403bytes in total, with their saved SHA256 checks; document writes0.
This verifies the archives' bytes and protected access, not their commercial
wording or a physical printer. The retired PH-00001/2/3 arrivals remain absent,
with no current target references or financial links. Their source Sodas remain.

Actual connected Chrome, Salman/Super Admin, then showed:

- Bank Reconciliation loading the main TTI Meezan bank's dated original entries
  and an independent Al Baraka selection with a truthful empty-entry state.
  No clearing tick, statement balance or reconciliation was saved.
- Account2230 named Salaries, Remuneration & Home Payables under Liabilities.
  The live Balance Sheet's Assets and Liabilities+Equity agreed exactly.
  The original7210Zakat classification review remained visible.
- The deployed monthly filing workspace links September GDs to their own
  Pakistan invoices and B/L copies; a later B/L date does not move the GD month.
  A missing K-Electric scan holds complete printing. The marked bank statement
  remains separate. No original document was invented or uploaded for a test.

Earlier live run39433779061 passed20/23, failing the obsolete SODA control,
one reconciliation HTTP lookup and an unclassified2230balance. Those observations
are retained. Later success does not establish the precise cause of the earlier
HTTP/file-state observation. The full-source gate and final live run provide
fresh acceptance; they must not be described as a proven historical root cause.

The subsequent9b69e11d live run39440832958/job118689895426 passed22/23.
All three companies had balanced Trial Balances, zero Balance Sheet differences
and no unclassified accounts; the bank comparison passed. The sole failure was
the search input acquiring a Supplier combobox role. This provided the actual
integration evidence for PR442, not a reason to weaken the search assertion.

## Disposition of every previously outstanding scope

| Scope numbers | Software evidence / current disposition | Business or external acceptance still required |
|---|---|---|
| 8,37,84 | Shared Director/Super Admin HTTP decisions, CSRF, role denial, actor history, replay/concurrency and source-preserving reversals are tested. Actual authorised Paklink correction is retained. | Actual pending approvals and each real business decision are not fabricated for an audit. |
| 9,72,110 | Existing private original-file restore/rollback rehearsal and current bounded-backup checks are retained. | An independently reconciled source/target migration and office/browser recovery rehearsal are separate acceptance. Production is never restored merely to test it. |
| 22 | All ten master editors were previously inspected; the new actual-auth create/amend/reload matrix and canonical consumer tests pass locally. Existing KAT/location/date/document tests continue. | Customer fields16–21 remain stored but have no demonstrated canonical customer-reader consumer. No stored values or controls are removed without the requested product decision. This does not certify every combinatorial document/master amendment. |
| 30,143 | Paid Zakat reporting, refunds, annual assessment form and source-integrity tests remain accepted. | Approved annual monetary counterpart and original7210opening allocation remain missing. No balancing journal is invented. |
| 50,95 | Exact Mill reference/person/company/month/payment matching, stale/reversed-source holds and no second payment pass the actual endpoint tests. | Current positive Mill references are absent; a real reference cannot be invented to complete this row. |
| 51,82,83,100,133 | Canonical stock movement, production/reversal, instruction, source-amendment, outside-Corn/shared-loading/billing and MySQL/browser suites cover disposable positive cases and billed-source holds. | Physical Mill/bag openings, approved Maize rates, positive original mixed-source/reprocessing/billed-correction cases and independent stock control totals are unavailable. Financial B/F never supplies physical stock. |
| 62,63 | The AMT13 original relationship-letter and Customs HS discrepancy is recorded; generated identities and HS rules are tested separately. | Commercially approved relationship/buyer wording and Customs classification must come from the original source. Neither is guessed or silently amended. |
| 71 | The cross-module regression workflows, native browser scenarios, 42-case exporter/payment/packing renderer matrix and named source/permission tests form the bounded software audit. | This is not mathematical proof of every possible control/data combination, nor a record of actual financial test postings in production. |
| 73,75,88 | The month-based filing feature is deployed; source/permissions/uploads/native invoice/complete six-page synthetic PDF tests and live GD/invoice/B-L reads are accepted. | Required original supplier/utility scans must be attached. Missing originals remain visibly incomplete; historical packs are not substituted. |
| 76,77,78 | Existing assets/investment/property, bank/finance/reconciliation, supplier allocation/plan/amendment/reversal/retry and read-only live suites are retained and rerun for the final release. | Positive original finance/share/property/statement/plan transactions are not created as audit samples. The separately requested expansion of expense invoice-capture/partial-payment options remains a feature plan, not a deployed claim. |
| 79,80,81 | Native Sales Contract/FI/document/upload boundaries, saved terms, multi-GD/multi-page/PDF/folder tests and source-preserving variants are covered by the full workflows. All35current indexed archive revisions passed protected downloads. | Commercial acceptance of every issued original, physical print, and the actual office folder still requires the original documents/device. |
| 119 | Current startup checks and read-only diagnostics are retained. | The historical intermittent runner-to-login/network cause is not proven by a later successful run. |
| 123 | Current generated packing layout is fixed and tested. | The older issued/uploaded ASAS62 PDF is preserved. Publishing a corrected historical revision must retain the original and its issue history. |
| 155 | Hosting code-backup retirement/15-day retention evidence and current deployment verification are retained. | Full billable hosting quota/file counts and the actual office PC's installed agent, task history, destination and independently verified archives remain external checks. The separate backup chat owns that PC investigation. |

The prior inactive FTR mapping exception is also retained: legacy rules pointing
to7200Donations stay inactive pending the auditor-approved mapping. Current
advance WHT remains1260. This continuation does not enable an old tax rule or
rewrite historical tax/donation postings.

## Formal security boundary

The [Security Scan skill](skill://Plugin_1e648473be9c8191a91ac3947151af55/security-scan/SKILL.md)
requires: “Start source review and launch scan workers only after preflight
returns `ready`.” Its referenced `references/scan-prologue.md` again failed to
load in this continuation, and no preflight tool was available. Therefore no
formal security scan or formal clearance is claimed. Existing functional
authorization, privacy, CSRF, storage, replay and concurrency tests are separate.

## Release acceptance

The initial accepted audit release was27a847e69239d5778bd835a102cfe00e528c2c88.
The final combined-release addendum below records the subsequent release.

- PR438 final combined featureaf7d85603ecbc875b0deb0c7eb4b541db413a9b2
  passed Accounts39439155009(both jobs) and Milling/Exports39439155088.
  Its master-data fixes remain in the deployed descendant, with the concurrent
  salary corrections preserved.
- PR442 feature43c011f2542d0ab68b4b4d3c965d646fedf3129d,
  exact tree03db22205c2432598c015c43a27264b6042708f2, passed
  Accounts39441412462(both jobs) and Milling/Exports39441412298.
  The native SODA regression includes actual shared autocomplete.
- Exact-main27a847e passed Accounts39442057543(both jobs) and
  Milling/Exports39442057581. Deploy39442609736/job118696115467
  succeeded. The complete source gate matched all458application files;
  business files written by that verifier0. User changes0 and journal-ID
  changes0. Code rollback rotation retained1 verified copy and touched0private
  backups. Login health passed.
- Startup39442839998/job118696930885 passed: login ready339ms;
  sign-in to usable Accounts660ms on that runner. This observation does not
  establish the cause of historical intermittent connection failures.
- Final live39442839978/job118696934009 passed23/23 in2.4minutes.
  It compared57active account ledgers across3companies, with0concurrent-revision
  skips;10PKR bank comparisons;29posted JVs; dated bag report controls; WHT
  original/screen/CSV/print agreement. All3Trial Balances balanced, all3Balance
  Sheet differences were0 and unclassified balances were empty.
  Suite-originated financial, stock, reference-link and annual-assessment writes0.
- Fresh connected Chrome confirms the search is a textbox, retains
  data-master-ignore, has no supplier/master role, shows the truthful no-match
  state for an unknown reference and restores the records when cleared.
  The inspection form was closed without Save.

The final suite also verifies truthful holds. Zakat/payroll payment-component
reviews and the original7210classification review remain; P&L is unavailable
where paid Zakat cannot be evidenced without guessing a payroll allocation.
Balanced raw books do not certify the annual assessment, final profit, physical
stock valuation or missing commercial originals. Positive actual production
sources were0; disposable positive tests do not become real production evidence.

Current business acceptance remains156scopes:125passed,24partial,1not tested,
3original-source/document failures,2blocked and1intermittent. Every previously
outstanding scope is dispositioned above. The bounded software test and repair
run is finished; unconditional business-data, migration, office-device and formal
security sign-off is not issued. No remaining source fact is silently treated
as a completed test or a new financial posting.


## Final combined release and deployment-trigger repair

While this closeout was saved, the separate salary stream merged additional
source-preserving payment/reset controls. A documentation-only commit then
exposed an existing pipeline gap: production required QA for the exact latest
main SHA, but path-filtered main workflows never ran for that documentation SHA.
PR446 removes only the two main-push path filters. Every main commit now receives
both unchanged suites; PR path filters, jobs, permissions and the exact-commit
production gate remain unchanged. Parsed-YAML equivalence checks confirmed that
only the intended trigger conditions changed. This prevents an audit/documentation
merge from stranding a concurrent application release.

The combined runtime6c2a20eb873b2566187680fea12efdc8919b5cc9 passed
main Accounts39444509500(both jobs) and Milling/Exports39444509525.
Deploy39445100966/job118704796324 succeeded, matching all458application
files to that exact SHA with0verifier business writes,0changed users and
0journal-ID changes. Login health passed. Rollback rotation retained1code
copy, removed1older managed code copy and touched0private backups.
Final startup39445359353 passed. Authenticated read-only live39445359319 /
job118705699429 passed23/23 in3.1minutes against this combined runtime.
It compared58active account ledgers across3companies with0concurrent-revision
skips,10PKR bank comparisons,30posted JVs and468Post Register rows. WHT
screen/CSV/print agreed with original postings. The suite recorded0financial
writes. These are observations of the current live data; the earlier57ledger,
29JV and465register counts describe the previous accepted run, before concurrent
user activity. Source/annual/device/security qualifications above still apply.

This final addendum changes documentation only. The accepted runtime is the exact
6c2a20eb873b2566187680fea12efdc8919b5cc9 release; documentation-triggered reruns
do not change that application-code acceptance.

## Resumed remaining-work repair — 20 October evening

The owner requested continuation after the preceding qualified closeout. PR448
adds explicit Full / agreed settlement versus Pay part — keep balance to the
existing TTI/BRM Party Payments workflow. Partial intent preserves every unpaid
paisa, including differences below PKR100, across selected invoices and separately
selected B/F. It bypasses automatic discounts on the server, rejects contradictory
discount instructions, binds intent to replay identity, and saves it with payment
and journal metadata. Amendment restores the choice; legacy zero-discount edits
default to keeping the unpaid balance. The settlement choice retains the approved
small-difference concession. No historical source is rewritten. TG and specialised
salary, reimbursement, credit-card and commodity-plan engines are unchanged.

The same endpoint had a confirmed storage failure path: malformed books could
fall back to an empty store, and JSON encoding occurred after truncation. It now
refuses malformed stored data and non-finite amounts, serializes before truncation,
and checks the write/flush. Disposable tests confirm that damaged books and
serialization failures preserve the exact original bytes. This does not claim
protection against every hardware or process failure during a physical write.

Endpoint tests cover123456.78paid100000,1000.08paid1000, later final settlement,
unselected invoices, B/F-only small balances, preview without writes, stale/excess/
zero rejection, replay changes, and actual staff-authorised partial amendment
preserving the public Post ID. Native amendment and real Chromium fixture tests
cover choice visibility and preservation across payment-source changes, existing
settlement discounts and unchanged TG routing. Local B/F, payment-plan, cheque
lifecycle, bank-default and amendment controls also passed.

Final feature9f4b8ca759c842c404a0f2cc825dad29dc0dca5b passed Accounts39459519891
(both jobs) and Milling/Exports39459519847. PR448 merged as
64047f0778318d632248544040ca3aaf84e9793d. Release acceptance is recorded below.

Connected local Chrome was verified as Chrome, but direct application navigation
initially showed Sign in. The owner session subsequently became available; the
real Party Payments screen was inspected, its custom dropdown selected, and an
unsaved original-invoice preview retained0.08with no discount. The form was closed
without Post. No credential, financial sample or uploaded original was supplied
by the agent. The Security Scan preflight
resource remains unavailable as described above. Hosting quota was subsequently obtained as recorded below; the actual Windows
office agent and complete quota-to-folder reconciliation remain unverified. Existing original-data, annual
allocation, commercial-document and physical-device qualifications remain.

The broader general/utility invoice-capture/pay-later workflow and expanded expense
outstanding reporting remain the separate plan. They are not claimed as deployed
by this partial-intent repair. Business acceptance remains125of156scopes;
fixes within a composite partial scope do not certify missing original evidence.

### Evening release acceptance

- Exact-main64047f0778318d632248544040ca3aaf84e9793d passed Accounts39460125658
  (both jobs) and Milling/Exports39460125811. Deploy39460649596 /
  job118758922893 succeeded; all458application files matched that SHA, verifier
  business writes0, changed users0, journal-ID changes0. Login health passed.
  Code rollback rotation retained1copy and removed1older managed copy, with
  private backups touched0.
- Authenticated read-only live39460912044 / job118759823415 passed23/23 in
  2.4minutes. It compared58active ledgers across3companies with0concurrent skips,
  10PKR bank comparisons,30posted JVs and468Post Register rows. All3Trial
  Balances balanced, all3Balance Sheet differences were0, and no unclassified
  balances were reported. Suite financial/stock/reference-link writes0. Existing
  Zakat/payroll and original7210review holds remain; profit is not certified.
- Startup39460912141 first failed at login with a blank path, TimeoutError and
  no server timings. The independently successful live suite justified one
  retry without any code/assertion change. Attempt2/job118762054487 passed:
  login353ms, sign-in to usable Accounts950ms; approved Exports palette passed.
  This is not a proven root cause or closure of historical intermittent scope119.
- Fresh connected owner Chrome confirmed the explicit partial choice through
  the real shared custom-select/numeric controls, retained an eight-paisa
  remainder with no discount, and closed without Post. Actual3Paklink invoice
  rows and the independent B/F control were present. No invoice/payment was saved.

### Provider quota and visible storage categories

Hostinger's signed-in plan resource page for the application successfully
recalculated usage on20October evening:22.82GB used of200GB and13,572
inodes used of600,000. This is shared hosting-plan usage, not a count or size
of Accounts entries. No capacity upgrade, process stop, restore or file deletion
was performed.

The hosting File Browser's directory-size calculation separately displayed:

| Visible category | Displayed size | Meaning / limitation |
|---|---:|---|
| All domain folders |13.78GiB| Separate file-browser calculation, not the full provider quota. |
| Application domain folder |13.38GiB| Includes private recovery material and the web application. |
| Application public files |24.9MiB| Application files and their public assets. |
| Code deployment directory |16.57MiB| Separate from business recovery snapshots. |
| Application private folder |13.34GiB| Includes the following categories; not all live business data. |
| Retained server backups |11.97GiB| Visible managed snapshots plus historical recovery material. |
| Security migration recovery files |939.23MiB| Recovery sources retained; not deleted or classified as disposable. |
| Office agent jobs |404.61MiB| Directory presence does not verify the physical Windows task/destination. |
| Live Accounts JSON |2.25MiB| One current Accounts source file, not the entire database/document inventory. |
| Authentication/master store |3.93MiB| Size only; credentials/file contents were not opened. |

Recent retained automatic ZIPs displayed about464.7MiB each; older retained
11–18October ZIPs displayed about998MiB–1.32GiB. A three-hour-old generated
snapshot temporary file was present; it is below the24hour stale-temp policy
and was left alone. The file browser and provider usage counters use different
reported totals/counts and do not fully reconcile here. No unsupported inference
that all22.82GBbelongs to current operational entries is made. Complete billable
category reconciliation and physical office-PC verification remain open in155.


## Expense invoices and final continuation — 20 October, 23:15 PKT

PRs 450, 452 and 455 are deployed in commit
`4cb563245ba9ea045514651a2214fc2266df487f`.

TTI/BRM can record an original ordinary or utility invoice in full, without a
bank payment, then settle its same supplier liability through Party Payments.
The route is **Expenses → Pay Expense → Record invoice — pay later**.
**Expense invoices & balances → Pay** opens the linked payment draft. The
register filters current balances and exports the filtered CSV/print rows.
TG retains its native-currency supplier-bill workflow. Existing paid expenses
were not converted or charged again.

Unpaid corrections and cancellations retain source identity and audited
reversals. Active linked payments, stale edits, duplicate invoices, invalid
inputs, wrong company/role and raw linked-journal edits are held. The existing
explicit partial-payment choice keeps a rounded remainder payable unless the
user deliberately records an agreed discount. Pending cheques show their net
payment separately from that discount and from cleared cash.

Mill K-Electric invoices retain their billing month, original filing-document
slot, meter-period cost and exact later payment evidence. Utility reminders use
the invoice due date. Manual consumer/account references survive both invoice
capture and the original paid-utility route and appear in the monthly filing
row. Saved utility masters remain authoritative for their own references. No
missing historical consumer reference was guessed or backfilled.

The transaction register now resolves original supplier names from the linked
bill/candidate before explicit journal party fields. Connected owner Chrome
verified formerly blank headings on original Paklink freight and MAPCO clearing
bills. It also verified **Total invoice**, the accessible **Invoice date**, the
empty new invoice register, and the saved Mill K-Electric form with meter fields
and a supplier-payable preview. Bank/cheque controls and payment-only narration
were hidden in invoice mode. These forms were closed without Record/Post.

| Feature | Final feature head | Accounts QA | Milling/Exports QA |
|---|---|---|---|
| Original invoice capture and linked settlement, PR 450 | `7d95958` | 39466465714, both jobs passed | 39466465727 passed |
| Original party headings, due dates and labels, PR 452 | `f833c22` | 39468321428, both jobs passed | 39468321467 passed |
| Consumer references and net pending cheques, PR 455 | `cbca651` | 39471045447, both jobs passed | 39471045477 passed |

Final main-branch Accounts QA **39471698718** (both jobs) and Milling/Exports QA
**39471698738** passed for `4cb5632`. Deployment **39472215443**, job
**118798897030**, verified all **458 application files** against the exact
commit with **0 business-file writes**. Login health passed. Code rotation kept
one rollback copy, removed one older managed copy and touched no private data
backups.

Final live run **39472429276**, job **118799640181**, passed **23/23 in 2.6
minutes**: 58 active account ledgers, three company reports, 10 PKR bank
comparisons, 30 posted JVs and 468 register rows. Every company trial balance
balanced; every balance-sheet difference was zero; unclassified balances were
empty. Concurrent-revision skips were zero. Financial/stock/reference test
writes were zero. No production test invoice or payment was created.

Startup **39472429076**, job **118799641797**, passed: login 531 ms, sign-in to
usable Accounts 771 ms, and the approved Exports palette. Earlier intermittent
startup failures remain recorded under scope 119; this pass does not establish
their root cause.

The register still has **125 passed, 24 partial, 1 not tested, 3 failed, 2
blocked and 1 intermittent**, totaling 156 scopes. It counts business acceptance
scopes. Closing the known invoice-capture gap adds implementation and verified
runtime evidence; composite scope 78 remains partial because real original
allocation/plan confirmations and every production source variant were not
fabricated. Missing originals, classification decisions, physical office-PC
recovery verification and the other listed qualifications retain their status.
No final profit certification is inferred from balanced reports.

The formal security scan remains blocked. The
[security-scan skill](skill://Plugin_1e648473be9c8191a91ac3947151af55/security-scan/SKILL.md)
requires: “Start source review and launch scan workers only after preflight
returns `ready`.” That prerequisite remains unavailable. Routine functional,
permission and regression checks above are recorded separately.

Separate backup-policy and approved temporary-cleanup changes in PRs 451, 453
and 454 were preserved. Earlier storage figures remain timestamped observations;
this continuation makes no fresh provider-quota or physical-PC claim.

See [Expense invoice user route and validation](EXPENSE_INVOICES_2026-10-20.md).
