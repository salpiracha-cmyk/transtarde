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
- each deduction/charge separately;
- FI allocations;
- invoice allocations;
- Sales Contract advance / unapplied receipt allocation;
- TG intercompany allocation where relevant;
- bank advice file/reference and Accounts approval/audit trail.

The exact bank advice amounts are authoritative for the transaction. Master rates/formulas are expected-rate controls and may raise an exception; they never replace the bank's actual deduction amount.

## 4. FTR vs NTR income-tax treatment
Historical and current regimes are separate master rows and separate receipt-tax codes.

### Historical FTR
- Code: `EXP-WHT-FTR`.
- UI description: Export Withholding Tax — FTR (Historical).
- Historical receipts/certificates retain the treatment applicable when they were posted.
- Do not retrospectively relabel historical FTR tax as current NTR advance tax.
- Current GL policy reference: `7200 Income Tax / Final Tax Expense`, subject to auditor/statutory confirmation for the historical period.

### Current NTR
- Code: `EXP-AWT-NTR`.
- UI description: Advance Withholding Tax on Export Proceeds.
- Current policy treats the bank deduction as advance income tax / tax deducted at source recoverable rather than automatically treating it as final tax expense.
- Current GL policy reference: `1260 Advance Income Tax / Export WHT Recoverable`.
- Rate/section is not hard-coded. Maintain an effective-dated rule using applicable Finance Act/budget and auditor advice.
- Capture the tax section shown on the actual bank advice/certificate for reconciliation.

## 5. Export Development Surcharge and other realization charges
`EXP-EDS` remains in the shared master even when not currently charged. Current default status is inactive/currently not charged. If a later budget/policy makes it applicable, create/activate the correct effective-dated row rather than rewriting old history.

Other seeded charge categories:
- `EXP-BANK-FDBC` — FDBC / collection / negotiation fee;
- `EXP-BANK-SVC` — export service / handling / processing charge;
- `EXP-FED-BANK` — FED / indirect tax on bank charges;
- `EXP-COURIER` — courier / document handling;
- `EXP-OTHER` — approved fallback for genuine one-off realization charges. Repeated charges should receive their own master row.

A bank may either deduct charges from realization or credit gross proceeds and debit charges separately. Transtrade must support both without altering the actual bank evidence.

## 6. Shared master governance
- Super Admin and authorized Accounts users edit the same `Export Realization Taxes & Charges` master.
- Changes are effective-dated.
- Prefer Historical/Inactive status to deleting an old rule that has posted transactions/certificates.
- No annual percentage is embedded in transaction code.

## 7. Bank tax certificate reconciliation
For TTI/BRM, every material `EXP-WHT-FTR` or `EXP-AWT-NTR` receipt deduction remains available for bank-certificate matching.

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

One certificate may cover many receipt deductions. Matching does not edit a posted journal; it is a separate reconciliation link.

Year-end tax-certificate status cannot be considered complete while material receipt tax remains unmatched or certificate differences remain unexplained.

## 8. Allocation rules
- One bank receipt may settle multiple FI records and/or invoices.
- One FI/invoice may be realized by multiple bank receipts.
- Receipt before invoice is Customer Advance / Unapplied Export Receipt until allocated.
- TG→TTI/BRM payment for an intercompany amount clears Intercompany Receivable, not Export Customer Receivable.
- Realization-rate differences from the receivable's carrying amount post to FX gain/loss; they do not rewrite the Commercial Invoice.

## 9. Approval
Export/operations source data does not itself post the bank receipt. Accounts reviews the credit advice, allocations, deductions and bank account. Accounts approval triggers the accounting posting and preserves the source/audit trail.
