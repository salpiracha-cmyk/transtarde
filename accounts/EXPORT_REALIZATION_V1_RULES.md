# Transtrade V1 — Export Realization / Pakistan Receipt Rules

## 1. Source data
- FI details flow from Exports and are not retyped in Accounts.
- Commercial Invoice details flow from Exports and are not retyped in Accounts.
- Contract payment terms and advance percentage/value flow from the Sales Contract in Exports.
- TG-linked Pakistan intercompany references and explicit Pakistan→TG values remain shipment-wise traceable.
- FI allocation to Customs and bank realization are two different statuses. A FI may be allocated operationally before or after a bank receipt; neither status silently substitutes for the other.

## 2. Pakistan foreign-currency receipt principle
- A customer/TG may remit USD or another approved foreign currency.
- For TTI/BRM ordinary Pakistan realization, Accounts retains the original foreign amount and the bank's conversion details, while the ordinary bank account is credited with the actual PKR amount shown on the bank advice.
- Foreign currency is treated as a usable bank balance only when the bank credits an approved foreign-currency retention account.
- Do not add PKR, USD, AED or other currencies together without an explicit conversion event.

## 3. Bank credit advice
Each realization retains, at minimum:
- entity;
- bank account / bank advice reference;
- value / credit date;
- remitter / customer / TG source;
- original currency and foreign amount;
- bank realization / conversion rate;
- gross PKR equivalent;
- actual PKR bank credit;
- foreign-currency retention amount, if any;
- each tax/deduction/charge separately;
- FI allocations;
- invoice allocations;
- Sales Contract advance / unapplied receipt allocation;
- TG intercompany allocation where relevant;
- bank advice file/reference and Accounts approval/audit trail.

The exact bank advice amounts are authoritative for the transaction. Master rates/formulas are expected-rate controls and may raise an exception; they never replace the bank's actual deduction amount.

## 4. Multiple withholding components and tax character
Transtrade must support more than one withholding component on the same receipt. Do not combine them merely because the rates happen to be the same.

Example historical pattern supplied by management:
- Fixed Withholding Tax: 1% — fixed/non-adjustable component.
- Advance Withholding Tax: 1% — advance/adjustable component.

If both apply, the receipt stores two deduction rows, two tax characters and two accounting treatments. They remain separate in the year-end bank-certificate reconciliation.

### Historical FTR
- Code: `EXP-WHT-FTR`.
- UI description: Export Withholding Tax — FTR (Historical).
- Historical receipts/certificates retain the treatment applicable when they were posted.
- Do not retrospectively relabel historical FTR tax as current NTR advance tax.
- Current GL policy reference: `7200 Income Tax / Final or Fixed Tax Expense`, subject to auditor/statutory confirmation for the historical period.

### Fixed / non-adjustable withholding component
- Code: `EXP-WHT-FIXED`.
- UI description: Fixed Withholding Tax on Export Proceeds.
- This is a separate master row from advance withholding.
- Historical example rate is 1%, but the exact effective dates, section and legal basis are not assumed. Keep the row Historical until the relevant period is confirmed.
- Current GL policy reference: `7200 Income Tax / Final or Fixed Tax Expense`, subject to auditor/statutory confirmation.

### NTR advance / adjustable withholding
- Code: `EXP-AWT-NTR`.
- UI description: Advance Withholding Tax on Export Proceeds.
- Current policy treats the bank deduction as advance income tax / tax deducted at source recoverable rather than automatically treating it as final tax expense.
- Current GL policy reference: `1260 Advance Income Tax / Export WHT Recoverable`.
- Rate/section is not hard-coded. Maintain an effective-dated rule using applicable Finance Act/budget and auditor advice.
- Capture the tax section shown on the actual bank advice/certificate for reconciliation.

## 5. Tax calculation base is configurable
Management's present working understanding is that withholding is calculated on the invoice/export value before discounts or foreign-bank charges. This is **not locked as a legal rule in V1**.

Controls:
- Calculation Base is an editable master field.
- Rate / Formula is an editable master field.
- Actual deducted PKR amount remains editable on the receipt and is what is posted.
- A calculated amount may be shown as a suggestion/check only.
- Accounts may correct the figure to the bank/tax evidence before posting.
- If the legal basis is later confirmed differently, add/update the effective-dated rule or patch the calculation helper without rewriting historical receipts.

## 6. Export Development Surcharge and other realization charges
`EXP-EDS` remains in the shared master even when not currently charged. Current default status is inactive/currently not charged. If a later budget/policy makes it applicable, create/activate the correct effective-dated row rather than rewriting old history.

Other seeded charge categories:
- `EXP-BANK-SHORTFALL` — confirmed foreign/correspondent banking shortfall between invoice/TG transfer and final amount received;
- `EXP-BANK-FDBC` — FDBC / collection / negotiation fee;
- `EXP-BANK-SVC` — export service / handling / processing charge;
- `EXP-FED-BANK` — FED / indirect tax on bank charges;
- `EXP-COURIER` — courier / document handling;
- `EXP-OTHER` — approved fallback for genuine one-off realization charges. Repeated charges should receive their own master row.

A bank may either deduct charges from realization or credit gross proceeds and debit charges separately. Transtrade must support both without altering the actual bank evidence.

## 7. Foreign banking shortfall
A small difference between:
- Commercial Invoice and final foreign payment; or
- TG amount sent and amount finally received by TTI/BRM

may be posted to `6810 Export Realization / Foreign Banking Charges` **only after Accounts explicitly confirms that the difference is a genuine foreign/correspondent bank charge**.

The system must not silently write off the difference simply because it is small. If Accounts does not confirm the banking-charge explanation, the invoice/intercompany difference remains outstanding for review.

When confirmed, the adjustment must retain the linked receipt, invoice/TG reference, foreign shortfall amount, PKR equivalent/rate and approver so the receivable/intercompany balance can be settled traceably.

## 8. Shared master governance
- Super Admin and authorized Accounts users edit the same `Export Realization Taxes & Charges` master.
- `+ Add Tax / Deduction / Charge` allows any additional withholding, levy, surcharge or bank deduction to be created when policy changes.
- Changes are effective-dated.
- Prefer Historical/Inactive status to deleting an old rule that has posted transactions/certificates.
- No annual percentage is embedded in transaction code.

## 9. Bank tax certificate reconciliation
For TTI/BRM, every material `EXP-WHT-FTR`, `EXP-WHT-FIXED` and `EXP-AWT-NTR` receipt deduction remains available for bank-certificate matching.

Certificate register stores:
- financial year (1 July to 30 June);
- bank / bank account;
- certificate number/date and covered period;
- tax type;
- tax section exactly as printed;
- certificate amount;
- receipt-tax amount matched;
- difference/status;
- certificate file/reference.

Fixed and advance withholding cannot be combined during matching. One certificate may cover many receipt deductions of the same tax type. Matching does not edit a posted journal; it is a separate reconciliation link.

Year-end tax-certificate status cannot be considered complete while material receipt tax remains unmatched or certificate differences remain unexplained.

## 10. Allocation rules
- One bank receipt may settle multiple FI records and/or invoices.
- One FI/invoice may be realized by multiple bank receipts.
- Receipt before invoice is Customer Advance / Unapplied Export Receipt until allocated.
- TG→TTI/BRM payment for an intercompany amount clears Intercompany Receivable, not Export Customer Receivable.
- Realization-rate differences from the receivable's carrying amount post to FX gain/loss; they do not rewrite the Commercial Invoice.

## 11. Approval
Export/operations source data does not itself post the bank receipt. Accounts reviews the credit advice, allocations, taxes/deductions, bank charges and bank account. Accounts approval triggers the accounting posting and preserves the source/audit trail.

