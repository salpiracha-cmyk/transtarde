# Transtrade Accounts V1 — Banking & Cash Rules

## 1. Shared bank identity master
Bank identity is maintained once in the shared Super Admin `Banks & Accounts` master. Accounts must not create a duplicate bank identity master.

The shared master owns details such as legal/account type, linked company, account title, bank, branch, country, currency, account number, IBAN, SWIFT/BIC, purpose, document/module visibility and master status.

## 2. Accounts operational settings
Accounts adds only operational controls to an approved company bank account:
- Active
- Allow Payments
- Allow Receipts
- Include in Payment Planning Funds
- Visible to Mill
- Reconciliation Enabled
- Accounts display name / notes

Completing the shared bank master does **not** automatically activate the account in Accounts. Accounts must explicitly enable operational use.

## 3. Company vs personal accounts
Only `Company Account` master records may appear as company Bank & Cash resources. Personal/family bank accounts must never be treated as company cash, company bank balance or supplier-payment capacity.

A family/staff member paying on behalf of the business is handled through the approved reimbursement / third-party settlement workflow, not by turning that person's bank into a company bank account.

## 4. Exact bank-account tagging
Every real company-bank receipt, payment or transfer must retain the exact shared Bank Master ID on the posted accounting journal. `Bank Accounts (1110)` remains the control account; the Bank Master ID is the bank subledger/dimension.

Do not post a real bank movement merely to generic `1110` without identifying the actual bank account.

Older generic bank entries that lack a Bank Master ID are shown separately as `Unassigned Old Bank Entries` until migration/reconciliation allocates them correctly.

## 5. Currency separation
Bank balances are shown and controlled by native currency. PKR, USD, AED and other currencies must never be added together as one cash figure.

For non-PKR banks, native-currency movement requires an explicit native bank amount (`bankDebit` / `bankCredit`) in addition to the functional/reporting amount when applicable.

Do not infer USD/AED movement from a PKR journal value.

## 6. Pakistan supplier-market payments
TTI / BRM commodity supplier and broker payables are currently PKR market payables.

The normal Supplier Payment / Supplier Advance workflow may use:
- an approved operational **PKR company bank account**, or
- approved PKR Cash / Petty Cash.

USD or other foreign-currency bank accounts are excluded from the normal PKR supplier-payment dropdown and from PKR supplier-payment planning funds. A future explicit FX / foreign-currency settlement workflow must be used if a foreign-currency bank is ever legitimately used to settle a PKR payable.

## 7. Payment-planning liquidity
Accounts may mark selected same-currency company bank/cash accounts as `Include in Payment Planning Funds`.

For TTI / BRM supplier planning, Transtrade automatically totals only eligible positive PKR book balances and may pre-fill that amount into the Supplier Payment Planning ladder. Accounts can overwrite the proposed funds amount for the actual market-payment decision.

Foreign-currency balances never create artificial PKR payment capacity.

## 8. No fake opening balances
Do not enter a fake operational bank receipt merely to make a dashboard opening balance look right.

Legacy/opening bank balances must later be migrated through a controlled opening-balance process that preserves the Trial Balance, legal entity, exact Bank Master ID, currency and audit trail.

## 9. Mill visibility
`Visible to Mill` is an Accounts operational flag. Where Mill workflows need a company-bank choice (for example Local Sale payment information), Mill should see only approved visible accounts, normally displayed as Bank Name + last 5 account digits. Mill users still do not post accounting entries.

## 10. Reconciliation
Each operational bank/cash account may be reconciliation-enabled. Bank reconciliation must compare the exact Bank Master subledger to the applicable bank statement and keep unreconciled movements visible until resolved.

