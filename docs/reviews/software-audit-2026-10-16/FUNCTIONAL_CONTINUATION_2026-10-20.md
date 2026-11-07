# Functional continuation — 20 October 2026

This continuation adds evidence to CLOSEOUT_2026-10-20.md. It does not erase source exceptions, earlier failed attempts or independent migration/security requirements.

## New defects and repair

PR392 addresses a live salary-advance row defect: bank-payment method top 514.625px versus cheque number/date 509.625px, all 44px high. The searchable select wrapper adds 5px margin. The shared payment-details renderer now identifies its own screen-only grid, aligns its label/control columns and resets that wrapper margin. Salary/rent/expense callers retain existing payment values and behaviour; print styles are unaffected.

The new native mobile assertion failed on the first head (Accounts 39310127122/job118257236452): recorded advance history overflowed its form. The recorded-advances table receives an independent horizontal scroll wrapper. The mobile assertion stays enabled and includes offending-element diagnostics; no error or overflow is hidden by relaxing the test.

## Independent disposable checks

Actual production endpoint/UI code was exercised with synthetic, temporary stores. Passing local checks:

- Investment funding/routing/remuneration split, buying/selling, returns, amendments, reversals, replay, archived masters, cheque validation and permissions.
- Mill references: exact company/location/person/date/full amount/month/source/payment identity, read-only opening, explicit metadata linking, replay/conflict/drift/reversal holds and no second payment.
- Shared Director/Super Admin decisions with CSRF/role boundaries; concurrent approval and first-approver identity preservation; write locks and damaged-store protection.
- B/F salary advances:79/27 month recoveries, no cash payment, stale-draft review, July cutoff, replay and stop at zero. Monthly overrides, split beneficiaries, actual bank/cash totals, broker assets and September-to-October recovery.
- Bank entries, finance drawdown/repayment/reversal, subaccounts, corrections, native-currency signed reconciliation and source-date cutoffs.
- Supplier plans: company/personal bank scope, source splits, replay/correction, oldest JV allocation, advances and independent brokerage.
- Outside-mill RAW Corn: two zero-bag containers, issued instruction/weight/packing checks, immutable retry/correction, combined source-backed billing and unchanged original journals.
- Assets/property HTTP checks: durable history,1 July boundary, privacy, reasoned amendments, original Post IDs and split payments.
- Protected export asset bytes, roles, cache validation and unchanged storage; cheque-date retention and dependent fields.

The added production-renderer matrix covers42 combinations: TTI/BRM/TG × seven payment methods × 25/50 kg bags. It generates 252contract/invoice/packing/Customs/B-L outputs. Assertions cover all actual containers exactly once in packing lists,4 packing pages for 30containers, original net weights and bag totals, all saved GD references, custom terms/usance days, source identities, no missing-value output and unchanged canonical fixtures. The matrix is wired into Milling/Exports QA. DOM output checks do not replace independent PDF/physical-print or issued-original acceptance.

## Authenticated live UI observations

- Maize direct, warehouse-bulk and packed/paltai routes show separate required fields.50 kg and25 kg paltai have independent basis/quantity/rate fields; container loading remains separate. Unagreed rates remain blank. No Rice quantities or rates were copied, and nothing was added/saved/printed.
- Current TTI Mill operational salary references contain no October reference rows; the committed Accounts-link read completes and preserves the empty table. Rent references likewise show no recorded payments. There is no positive original reference to link in these screens.
- Accounts recorded advances show the existing original Post IDs, including separate existing staff B/F advances. No reference, payment, journal, master or stock entry was created during this audit.

## Release acceptance

PR392 final head ee0a0ac14b7954b4de052831c650021931de8d91/tree f09254227091604cf71b166dfe2df3f4fe1b7a42 passed feature Accounts 39310769030 and Milling 39310769023. Merge db99e11d039812c2b8881c1cccf07a7669c5f962 passed exact-main Accounts 39311314793 and Milling 39311314762. Deployment 39311828641/job118263100373 succeeded;zero user changes andzero journal-ID changes,418 journals preserved. Startup 39311975742 and full live 39311975908/job118263626658 passed 23/23 (2.5minutes),53 active account ledgers across 3 companies, no concurrent-revision skips and zero financial writes. Authenticated live form acceptance confirms all payment controls top 515.625/height 44 and the history scroll wrapper. The subsequent date correction has its separate exact-head and final acceptance below.

## Exceptions retained

Original annual Zakat financial counterpart/opening allocation; inactive FTR account mapping; AMT13 approved buyer/relationship and Customs classification; an authorised separate corrected ASAS62 historical PDF revision if wanted; real physical mill/bag openings and agreed Maize rates. Real-source and migration/office-output/security acceptance retain their scope in CURRENT_COVERAGE.csv. No historical issued document or financial original has been silently changed. Backup/storage maintenance remains with Resume check status.

## Final live check found a business-date defect

After PR392 deployment, authenticated Chrome confirms all three salary payment controls at top 515.625px/height 44px, with the history scroll wrapper present. The same live opening showed salary Date 19 October while the Milling header and current report cutoff correctly showed 20 October after Karachi midnight.

PR393 corrects UTC-truncated current-day defaults in salary/rent, donation/Zakat, legacy saving-profit and current-day payables/costing/source fallbacks. Salary month comes from that same Karachi day. Existing explicit source dates and variable-based UTC calendar arithmetic are unchanged. The actual salary/donation/bank-profit renderer regression covers six explicit midnight/month/year/leap boundaries, original manually chosen date retention and zero POSTs. Negative control with the previous salary source fails at 2026-10-19T19:00:00Z: actual 19 October versus expected 20 October. The corrected sources pass; existing salary/donation, source linkage/receipt scope and cheque-date checks pass. The accepted exact-head release evidence follows below.


## Formal security boundary

The [Security Scan skill](skill://Plugin_1e648473be9c8191a91ac3947151af55/security-scan/SKILL.md) requires: “Start source review and launch scan workers only after preflight returns `ready`.” Its required scan-prologue and core-scan supporting resources both still fail to read on 20 October, and no standard-scan/preflight tool is available. Therefore formal clearance is blocked, rather than represented as a successful scan. This does not erase the functional suites’ tested permission, CSRF, replay, concurrency and storage-integrity checks.

## PR393 exact release and live form acceptance

Final head f21071cb26779b9284bb1d9a2d5e4ae33610ef5e/tree 69dcec30ad71f8da5cf7e4c6eef857406b4b814b passed feature Accounts 39312467435 and Milling 39312467412. Merge 8219023bcaa7304d069d630ad3292be9f7a570e6 passed exact-main Accounts 39313009958 and Milling 39313010026. Deployment 39313530952/job118269012575 succeeded, with zero user changes, zero journal-ID changes and 418 journals preserved. Production login is healthy.

Authenticated live Chrome after that deployment shows new salary date 2026-10-20, month 2026-10 and payment date 2026-10-20. All three payment controls remain top 515.625px/height 44px; advance-history overflow remains auto. No Post/Save was used, and the form was closed back to Accounts Home.

Final startup 39313668669/job118269474246 passed. Final protected live 39313668463/job118269473011 passed 23/23 in 2.6 minutes on19October19:50:15Z (20October00:50:15PKT). It compared 53 active financial ledgers across 3 companies, with no concurrent-revision skips and zero financial writes. No live financial/stock/master/approval/issued-document test transaction was made. Both runtime repairs are released and accepted within their explicitly tested scopes. Business/source and independent migration/output/security exceptions above remain open; no whole-software certificate is claimed.
