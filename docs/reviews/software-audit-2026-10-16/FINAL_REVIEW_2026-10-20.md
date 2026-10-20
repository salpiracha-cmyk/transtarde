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

The deployed application is27a847e69239d5778bd835a102cfe00e528c2c88.

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
