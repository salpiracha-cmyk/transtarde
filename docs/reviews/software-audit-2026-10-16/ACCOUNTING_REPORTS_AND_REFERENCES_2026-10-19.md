# Accounting reports and Mill reference reconciliation — 19 October 2026

This continues the whole-software audit after PR347/348. PR349 implements the report/reference work below; PR352 completes the two source corrections found by the fresh live audit. It does not certify every original balance, quantity, document, migration or user-session action. The action-level coverage register remains authoritative.

## Result and accounting boundaries

| Area | Implemented behavior | Remaining acceptance |
|---|---|---|
| C09 paid Zakat | P&L and expense activity use original verified cash/bank payments less refunds. Financial openings and unpaid assessments are excluded from expense. Each original recipient/purpose line survives. Dated refunds remain in their actual period, and a current-period GL reversal stays visible when its original predates From. Ambiguous payroll, mixed cash/accruals, invalid dates and unbalanced payments hold the total instead of showing zero. | Exact canonical annual debit/credit and the original opening allocation still need reconciliation. The annual form/control was already agreed and remains in scope. |
| Zakat original books | Trial Balance and General Ledger retain original opening/payment/reversal journals. Balance Sheet remains explicitly labelled as the posted-books view when its legacy Zakat control classification is unresolved. | No fabricated balancing equity, guessed annual counterpart, new financial journal or rewritten opening was introduced. A mathematically balanced original-book view is not classification clearance. |
| C12 quantities | Commodity & Local Sales shows original purchase/export/local commitments, minimum/maximum order ranges, prior/period/cumulative receipts/loading, outstanding quantities, product/brand and movement source IDs. Closed references have no new requirement. Screen/CSV/print use the same filtered facts and preserve small weight movements. | Current saved versions are not an immutable historical-contract archive. Missing/unassigned/undated/duplicate sources remain review holds. The MacBook user-session comparison is listed separately below. |
| Financial B/F | Journals and historical receivable/payable openings never supply a physical quantity to the commodity report. | Actual Mill rice/bag opening quantities remain separate facts Salman will supply. Existing native receipts are preserved; no stock was inferred, removed or reassigned. |
| R08 Mill handoff | Accounts → Mill Bill Review → Rent / Salary References explicitly links a reported reference to one existing balanced canonical payment with exact company/person or rent mill/date/full amount/rent-month evidence. | No original operational reference is automatically certified or posted. Split/combined references require their actual allocation to be reviewed. Absent ownership or a matching canonical payment remains a source hold. |
| R08 integrity | Source/payment/link fingerprints, role/company/location/CSRF checks, one-payment-per-reference-use, replay protection and retained reviewer/link history. Changed/reversed evidence loses its verified status. | Linking/unlinking is non-monetary. It never pays an expense, accrues rent, repeats a salary advance or changes salary deductions/settlements. |
| Milling status | Explicit Check Accounts links reads the canonical original Post and current advance remainder. Checking leaves unfinished Mill entry intact. | Legacy operational estimates remain labelled separately. A reference alone never proves a payment or monthly settlement. |

## Verification

Feature head `87d1f50ecaba2da7774571399f184c12520046a6`, tree `2e4328d37d9758b95869b8304caac335e83c4f11`, passed full Accounts `39224370906` (audit `117966972446`, inventory/native/MySQL `117966972017`) and Milling/Exports `39224370910` (audit `117966971961`). Both Accounts jobs and the complete native/MySQL step passed.

The native acceptance exposed an older local-only reference Save. Rent and salary reference Save now use acknowledged server append, stable retry identities, duplicate checks and recovery; failure retains the form and cannot claim a saved reference. The disposable native flow fails/retries an actual Mill rent reference Save, confirms the single server source, links it through the native Accounts review to an existing original payment, confirms unchanged journal/payment data, and returns the verified Post to Milling while preserving an unfinished amount. Endpoint tests cover salary settlement carry, rent month allocations, invalid/mismatched/reversed sources, duplicate original payment use, stale link conflicts, retries, unlink history and original financial/stock preservation.

The quantity tests cover purchase ranges, native export and local quantities, brand/company isolation, period splits, closed requirements, missing/undated sources, duplicate receipts, financial-B/F exclusion and matching screen/CSV/print filters. Zakat tests include the original opening/payment shape, multiple recipients, refunds, unpaid accruals and ambiguity holds. No test financial or stock mutation was made in production.

## Release and deployed read-only acceptance

PR349 merged as `8fd48414667aacec255bb5367669fa1579496654`; the merged tree exactly matches the feature. Exact-main Accounts `39224874470` (audit `117968608480`, inventory/native/MySQL `117968607946`) and Milling/Exports `39224874160` (audit `117968607437`) passed. Deployment `39225191252` / job `117969639479` passed on its first attempt; it confirmed exact deployed SHA `8fd4841`, healthy login, changedUserCount 0 and journal-ID migration changeCount 0 (388 journals checked). The earlier intermittent startup and first-attempt 403 from PR347 remain recorded; this successful release does not establish their root cause.

Read-only startup `39225378894` / job `117970259627` passed: sign-in to usable Accounts 1,115 ms; Accounts 1,339 ms, Masters 951 ms, Milling 1,058 ms and Exports 945 ms. These are hosted measurements, not a MacBook session certification.

Initial live smoke `39225379157` / job `117970260750` passed 23/23 in 2.5 minutes with 0 financial/stock/reference-link writes. It compared 10 PKR banks and 50 active ledgers in 3 companies with 0 revision skips, read 35 archive packs and sampled 200/PDF, retained 8 unavailable shipment contributions and 5 historical openings, and reconciled two September OTHER WHT rows through screen/CSV/print (credit 2,414.06). Recovery remains 0 actual production source runs. The new report review read 6 quantity rows and 0 Mill references; it exposed 13 quantity holds across company responses and one Zakat payment review, investigated below. These were not certified as resolved by the first smoke pass.

## Original-source follow-up and corrections

Read-only diagnostic PR350 / run `39226595053` / job `117974271846` used a locked original Accounts snapshot; it remained stable and identified the active operational backend as file storage. The 13 quantity warnings comprise one repeated outside-loading company warning plus 10 held PS-RICE-26003 liftings, not 13 independent defects. The Zakat warning refers to original Post POST-2026-00105, a balanced SALARY_ADVANCE with only 1230/1110 asset/cash accounts and no Zakat component. It must not be treated as an unallocated salary/Zakat settlement.

PR351 / run `39227395500` / job `117976916807` compared the original outside loads. All 10 containers have exactly matching native/canonical dates and weights (25/26 September 2026), and their unique original native Soda 784441754 records entity TTI and purchase bridge PS-RICE-26003. The unfiltered group reader already verifies all 10 receipts. The company report lost that native parent relationship when filtering rows with no repeated entity. No stock ownership assumption is needed.

PR352 excludes a balanced asset-only salary advance from Zakat payment-allocation checks, while retaining holds for actual Zakat/payroll components. It scopes an outside load through its unique explicitly owned native parent, retaining every original row unchanged. Unknown/duplicate parents, other-company access and mismatched original dates/weights remain blocked; financial B/F alone establishes neither ownership nor quantity. This does not reassign or resolve the separate C24 warehouse arrivals.

Source-fix head `a29948056dca6e2936592b3bf70e9f0cb4eaf948`, tree `4a889db54081f3c2bb81a8567dc4fab67ccfe56f`, passed full Accounts `39228123052` (audit `117979313611`, inventory/native/MySQL `117979314027`) and Milling/Exports `39228123021` (audit `117979313082`). It merged as `a5f32947d3107fe8d506e75c1b631626b3e5c027` with the exact same tree.

Exact-main Accounts `39228524991` (audit `117980603114`, inventory/native/MySQL `117980603523`) and Milling/Exports `39228524742` (audit `117980602130`) passed. Deployment `39228998035` / job `117982128916` passed on the first attempt, confirmed exact SHA `a5f3294`, healthy login, changedUserCount 0 and journal-ID changeCount 0 (392 journals checked). Production acceptance remains read-only.

Startup `39229101981` / job `117982466173` passed: usable Accounts 1,205 ms; Accounts 1,655 ms, Masters 1,046 ms, Milling 1,003 ms and Exports 1,012 ms. Fresh live smoke `39229102040` / job `117982466410` passed **23/23 in 2.6 minutes**. It read six quantity rows in three companies with **0 quantity holds and 0 Zakat payment reviews**; the **one remaining Balance Sheet classification review** is retained. It found 0 Mill references in the reviewed TTI response and made 0 reference-link, financial or stock writes. It again compared 50 active ledgers in three companies with 0 concurrent-revision skips. Eight shipment contributions remain unavailable, with five historical opening rows. No original cost or revenue is invented.

## Still open in the wider audit

- Canonical annual Zakat monetary counterpart and original opening/control classification. The earlier traced opening 852,747 / payments 103,350 / balance 749,397 remains historical evidence, not a newly certified current balance.
- C24: the original three Pohanch identities and Indus-versus-TTI grouping of 119,500 kg. Financial B/F and future physical opening quantities do not authorise reassignment of those existing receipts.
- Original issued CI/PL versus dated archive indexing/output, historical AMT13 buyer-letter/HS decisions and preserved old packing output.
- Positive actual recovery/cost/complete shipment overheads and evidenced TG eliminations. Missing source cost remains unavailable.
- Remaining original-source, role/master/action and output variants; formal security and independent migration/source-to-target/rollback acceptance; oversized offline acceptance. Ordinary CI is not formal clearance.
- The MacBook Chrome user-session review. This chat still exposes no connected local Browser control. Hosted disposable/live read-only browser checks are recorded separately and do not replace that review.

Coverage after this continuation: 146 action rows — 112 passed, 21 partial, 4 failed, 6 not tested, 2 blocked and 1 intermittent. The 34 open/partial rows are action-level acceptance items, not 34 distinct defects. No overall 100% claim is made.

## Connected Chrome continuation — 19 October 2026

This supersedes the earlier connection limitation above. The Browser extension now identifies Chrome and exposes the owner's signed-in Transtrade session (Salman / Super Admin). The existing Accounts tab contained an open Bank Entry form; it was inspected but left untouched. Review navigation used a separate temporary tab and the native Console-to-Mill route.

Read-only native UI observations:

- Accounts → Reports → Annual Zakat opens the annual assessment/control, its incomplete-section gate, six section controls, yearly-debit pending status and actual-payments expense wording. No section was saved. This confirms availability, not completion of the annual monetary posting or its counterpart.
- Commodity & Local Sales renders original ordered/moved/outstanding quantities, product/brand filters and financial-only B/F rows with no physical quantities. PS-RICE-26003 displays its ten original lifting references and moved quantity without the earlier false parent-ownership hold.
- P&L displays actual paid Zakat expense of PKR 103,350 for the selected 1 July–19 October period. Balance Sheet retains the explicit 7210 opening/unpaid classification review; no balancing equity or reclassification was assumed.
- Expenses → Mill Bill Review → Rent / Salary References opens the existing-payment reconciliation screen and reports no explicitly owned saved references. This is empty-state/navigation acceptance, not a new positive live link or payment test.
- Mill → TTI Rice Mills → Labour / Mill Expenses → Maize opens the separate Maize form. Direct route shows container loading; warehouse bulk adds incoming-bag unloading; packed route additionally separates 50 kg and 25 kg paltai with explicitly selected rate bases. All rates remain blank, contractor confirmation is unchecked, and Rice auto-fill is absent. No work line or bill was saved. This supplements PR318/339 implementation evidence with connected Chrome route inspection.
- Captured warning/error logs were empty on the reviewed Accounts and Milling tabs. This is scoped to these views, not full application clearance.

One newly confirmed UI inconsistency remains: Accounts Mill Bill Review still says “Maize labour remains deferred” and its bill-type filter lists Rice but not Maize, despite the released native Maize flow. Record this for reconciliation with the actual review/posting implementation; do not describe the entire Maize flow as unimplemented or use this text as permission to create live bills.

No financial, stock, assessment, source-reference-link or master save/post action was performed. The remaining source/accounting/document and formal acceptance gates above remain open. This scoped Chrome continuation does not certify every role, company, workflow variant or printed original, and the coverage totals have not been promoted on that basis.


## Owner follow-up — approval notices, Director JV and receipt direction

Owner requested completion plus three defects on 19 October. Connected Chrome confirmed Tayyab is active Director with all four modules and every displayed screen/action/master checkbox selected. No user grant, password or security setting was changed during inspection.

Changes prepared in `audit/approval-director-receipts-20261019`:

- Private JV notifications now read unacknowledged recipient decisions on Accounts home, Console dashboard or Directors home. Approval and rejection both remain until X. Leaving home hides without acknowledging; returning restores the same decision. A fresh browser/session reads outstanding decisions without advancing past them. X saves a recipient-specific acknowledgement under the existing Accounts lock/CSRF/entity checks. A View-only recipient can close their own message; foreign users/companies cannot. A failed acknowledgement keeps the message. Notification reads do not update transaction forms or inactivity timestamps.
- Directors with the existing JV Approve and selected-company Approve actions can directly post and complete their own pending JV. Ordinary preparers still require another approver. Title alone does not bypass the selected matrices. This corrects the proven JV restriction; it is not certification of every possible restriction Tayyab may encounter.
- Bank Receipt identifies Received into bank and Received from, retaining Pay from bank for outgoing modes. No mode change creates an entry.
- Mill review separately filters Rice and Maize/Corn/Makai; original commodity is retained even on a blocked/missing-source review, and unknown commodity is not labelled Rice. Screen, CSV and print share the filter. Existing posting, correction and settlement safeguards remain.

Zakat consortium treatment is distinct from the owner's own assessment/expense. Where TTI actually recognises/controls the collection and owes distribution, existing named-liability Bank Receipt can debit the receiving bank and credit a `Zakat collected for distribution` subaccount under Trade & Other Payables (2100). Paying that collected fund debits the same liability and credits the bank. This does not change 7210 expense or duplicate the annual assessment. If Salman personally holds the fund outside TTI, its bank and distribution liability belong in the separate fund records; do not invent a TTI bank asset. A due-from Salman entry would require an actual TTI-controlled claim/obligation, not merely the use of a personal bank account. No fund account or collection was created in production.

Targeted UI, actual endpoint, actual-auth, journal concurrency/idempotency and consortium receipt/distribution checks passed locally. The real-auth test caught and fixed the shared company guard initially treating a recipient acknowledgement as a financial write; the exception is confined to this action and still requires View plus recipient ownership. Consortium fixture receipts/distributions leave the entire P&L unchanged and leave the correctly signed remaining fund liability. Full feature/main/deployment evidence is to be recorded after release. Existing source/document/migration/formal-sign-off exceptions remain open; inspection is not an overall 100% claim.
