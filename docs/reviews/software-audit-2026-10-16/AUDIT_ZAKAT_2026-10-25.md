# Software audit continuation — 25 October 2026 (Pakistan)

Scope: Accounts, Milling, Exports, Directors, Master Records and Super Admin business flows. Backup/restore is excluded. Do not manufacture production payments, approvals, stock, historic documents or assessment counterparts to complete an audit.

## Confirmed defects repaired

1. Payroll Zakat warnings looked for the person/month on the bank/cash leg even when the payable leg already identified them. This incorrectly held salary-only payments and the P&L total. The owner confirms Irfan is pure salary, no Zakat. Recorded zero-Zakat salaries now resolve through the payable identity.
2. A fully funded salary voucher and a full separately linked salary payment can now recognise the separately recorded Zakat component once. The annual control, P&L and expense activity use that same payment-date evidence. Actual refunds retain their dates. Partial, unidentified and excess/repeated settlements remain source-review holds; no proportional or assumed first-paid allocation is introduced.
3. Explicit Zakat purpose/narration now routes the identified new expense amount to 7210 through Expenses, staff petty cash, additional salary payments, Bank Payment and manual JV expense lines. English/Urdu wording and word boundaries are covered. Multi-expense vouchers use individual purpose/memo; shared narration is used only for a single expense. JV green review and posting agree. No recipient name alone determines the account.

The approved opening and all original historical journals remain intact. No production correction or monetary reclassification was posted by this audit. Funds held for others, cash/bank transfers, creditor settlements and opening controls retain their heads.

## Verification

- Broad disposable local non-backup/restore run: 382 commands. 381 passed on the run; one newly added salary-test assertion assumed an empty PHP array was a JSON object. The fixture assertion was corrected and that full salary workflow passed on rerun. Earlier runtime/browser provisioning failures were retained and subsequently rerun successfully, not labelled business defects.
- Final affected-flow rerun: 14/14 passed, covering annual Zakat, group management/daily endpoints, expense/posting preview, financial reports, staff petty cash, bank entries, manual JV, salary month/group/flexible posting, JV review/retry and real Chromium date/JV controls.
- Real Chromium rerun: 32/32 passed, including monthly tax packs, costing, popup/dynamic forms, staff cash, supplier openings, layouts, payment plans, completed shipment output and document persistence.
- Existing guarded MySQL-only Daily test is not claimed as a local pass; CI owns its explicitly disposable database. Actual hosted data/restore was not used for a test.
- Main concurrently received the exact TG-internal invoice/value and intercompany cashflow correction (d9724497). It is preserved. Both new tests pass locally; internal controls remain separate from real external receivables/payables.
- Connected owner Chrome: Accounts P&L reproduced the false payroll warnings, including original/replacement Irfan sources. Console opened with no pending approvals. Directors/costing, Milling/Rice Ledger/report controls and Exports/FI controls opened. Costing for 25 October explicitly has no entered daily USD/PKR rate and asks for it; no rate was invented. No live business Save/Post/approval/stock or document action was used for testing.

Implementation/release: https://github.com/salpiracha-cmyk/transtarde/pull/573 . Final hosted CI, merge and deployment results must be read from the exact commit/workflow; this document does not predeclare them passed.

## Only matters needing original facts or owner review

- Physical Mill rice and bag quantities: the owner will supply them. Financial B/F does not establish physical stock.
- Historic production raw inputs, brand/contract allocations and actual cost/rate evidence where missing: reports must leave these unavailable.
- Missing supplier/tax/electricity scans and independent payment proof: retain the actual missing-source list; printing available documents must not mark a pack complete or filed.
- Any payroll payment whose Zakat component remains genuinely unidentified or partial after the source lookup: present the person, month, Post ID, payment and missing Zakat amount. Do not include Irfan as an assumed Zakat recipient.
- Issued Customs HS/source-document decisions and office shipment receipt remain evidence/operational acceptance, not guessed software corrections. Reassess against current originals before asking; older AMT relationship-letter implementation findings are superseded by the subsequent approved standard-template release.
- Formal security clearance is not asserted by functional/regression tests. Existing unverified clearance remains qualified.

The old 156-scope acceptance register is not inflated by counting test reruns as newly completed live business scenarios. This is the current continuation evidence, not an assertion that every real-world approval/payment variant was performed in production.
