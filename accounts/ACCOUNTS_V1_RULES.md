# Transtrade Accounts V1 — Locked Foundation Rules

## 1. UX principle
Users enter the business event in plain operational language. The accounting engine creates professional balanced double-entry postings underneath. Users should not need to understand debit/credit for normal operational entries. Journal Voucher remains an accountant-grade exception.

## 2. Accounting governance
No user workflow preference may force accounting treatment that conflicts with proper double-entry or applicable accounting/statutory requirements. UK GAAP / FRS 102 style principles are the design baseline, while each legal entity must comply with its own jurisdictional statutory, tax, payroll, disclosure and audit requirements.

## 3. Entity architecture
Accounts opens at an entity landing page:
- Transtrade International (TTI)
- Buksh Rice Mills (BRM)
- Trans Grains (TG)

Each entity has separate legal books. TG transactions must never be silently mixed into Pakistan statutory/auditor books. Authorized users may have a group management view, but that does not create one combined statutory ledger.

## 4. Internal Director terminology
The app may use the role/title `Director` for family-management permissions and UI. This is an internal title only and must not determine legal accounting treatment. The business/legal constitution in entity master controls statutory/accounting treatment.

## 5. Main Accounts home icons
- Expenses & Overheads
- Purchases
- Cash & Bank
- Receivables
- Payables
- Journal Voucher
- Reconciliation
- TG / Intercompany
- Reports
- Accounts Masters

## 6. Expenses & Overheads
Contains:
- Utilities & Bills
- Credit Cards
- Rent & Recurring
- Salaries & Staff
- Reimburse Someone
- Office / Mill Expense

### Utilities and cards recognition rule
Receiving a bill/statement does not itself create a ledger entry in the normal workflow. Due dates may be stored as operational reminders. The accounting entry is created when payment is being made and the actual amount/payment details are entered, except where proper accounting policy requires a legitimate accrual/recognition entry.

## 7. Credit cards
One card payment/statement may be split across business expenses and personal/family allocations. Personal portions for Salman, Talha, Abu, Tayyab etc. must not remain in company operating expense. They map to the appropriate owner/family/current/remuneration structure according to the entity's actual legal/accounting setup and approved policy.

## 8. Expense reimbursements
If a family member/staff member pays a legitimate business expense personally, the expense is recognized as a business expense and a reimbursement payable is created until settlement. This must remain separate from personal/family allocation accounts.

Example: Talha pays company-car petrol personally:
- Dr Fuel / Company Vehicle Expense
- Cr Talha — Expense Reimbursement Payable
Then on reimbursement:
- Dr Talha — Expense Reimbursement Payable
- Cr Bank / Cash

For company-car petrol/fuel, individual car numbers are not required. Use `Company Cars`.

## 9. Purchases
### Commodity purchases
Commodity purchase engine is Soda-driven and master-driven. Initial commodities:
- Rice
- Corn
- Sesame

Future commodities can be added in Commodity Master without redesign.

Commodity Master may define:
- unit of purchase
- inventory/purchase/payable mappings
- Soda behavior
- quality/specification profile
- Kat/deduction rules
- brokerage rules
- tax rules
- effective dates

Accounts must not retype operational purchase data already entered in Soda / arrivals / Pohanch. Accounts verifies the financial bill, approved deductions/taxes/brokerage and settlement.

### Other purchases
User first identifies economic nature:
- Fixed Asset
- Office / Mill Item
- Consumable
- Service / Expense

The accounting engine then applies the correct capitalization/inventory/expense treatment.

## 10. Brokerage/tax/deduction masters
Percentages and calculation rules must not be hard-coded. They are effective-dated master rules. Sensitive changes may require higher approval. Auditor-confirmed values can be inserted later without redesign.

## 11. Journal Voucher
JV is mandatory and supports multi-line debit/credit entries. Backend must refuse unbalanced journals. Posted journals are immutable; corrections are made through reversal/adjusting entries with full audit trail.

## 12. TG / Intercompany
TTI / BRM / TG books remain separate but linked. TG-linked transactions must preserve explicit traceability for:
- shipment/contract
- Pakistan entity
- TG customer/intercompany side
- intercompany price entered shipment-wise
- FI / GD
- bank allocation
- USD settlement/retention source
- due-to / due-from
- reconciliation

No silent price inference or hidden netting.

## 13. Local Sales — controlled exception
Local Sales and Export Sales are separate accounting/reporting streams. Local Sales are not treated as Transtrade's core business activity and require special monitoring.

Every Local Sale must carry a mandatory Product / By-product dimension so Local Sales can be reported by B2, CSR, Powder or any other approved by-product/product without creating an overcrowded Chart of Accounts.

Mill staff may enter Local Sale operational details and payment intimations, but they do not post accounting entries. Local Sale accounting and Local Sale payment accounting require Accounts approval. Pending Mill entries have zero ledger effect until Accounts approves them.

A Local Sale must also identify the actual Selling Entity (TTI or BRM) from the approved entity master. The mill/location must never silently determine the legal seller.

Transtrade internal Local Sales control for the 30 June financial year:
- monitor recognized Pakistan Local Sales separately from recognized Pakistan Export Sales;
- calculate Local Sales / Export Sales as a percentage;
- exclude TG customer turnover and Pakistan/TG intercompany revenue from the denominator;
- raise a prominent warning at 4.8%;
- raise a critical alarm at 5.0% and above;
- show remaining rupee headroom to 5%;
- show a by-product-wise Local Sales breakdown;
- show the projected ratio before Accounts approves a pending Local Sale;
- never block Accounts from recording/posting an actual completed sale solely because the ratio is above 5%.

Accounting must reflect actual transactions even when a management/compliance limit has been breached. A 5% breach therefore creates alerts, exception reporting, owner/director visibility and a closing exception; it does not suppress accounting recognition. Any prospective business restriction should be handled before the transaction occurs through operational approval controls, not by preventing Accounts from recording a completed sale.

The 5% ceiling remains a Transtrade internal control pending auditor/legal confirmation of the precise statutory basis and scope. The policy is configurable so an auditor-confirmed entity-specific denominator can replace the management scope without redesign.

## 14. Third-party settlements
A payment made directly by a third party to a Transtrade supplier or service provider must never be represented as if money moved through a Transtrade bank or cash account.

Accounts uses a distinct `Third Party Settlement` workflow and records:
- legal entity;
- third party / payer;
- supplier or service provider receiving the money;
- linked supplier bill, Soda, service invoice or expense;
- amount and settlement date;
- settlement reason / relationship;
- source party ledger or settlement basis;
- documentary reference / proof;
- Accounts approver and audit trail.

Treatment depends on why the third party paid:

1. If the third party already owes Transtrade money and pays a Transtrade supplier/service provider on Transtrade's behalf, the third party receivable is reduced. If the supplier liability already exists: Dr Supplier / Service Payable; Cr Third Party Receivable. If no payable has yet been recognized under the normal workflow: Dr the correct Expense / Asset / Inventory account; Cr Third Party Receivable.

2. If the third party does not owe Transtrade and simply pays on Transtrade's behalf, Transtrade becomes liable to that third party. If the supplier liability already exists: Dr Supplier / Service Payable; Cr Due to Third Party. If no payable has yet been recognized: Dr the correct Expense / Asset / Inventory account; Cr Due to Third Party. When Transtrade later reimburses the third party: Dr Due to Third Party; Cr Bank / Cash.

3. If the payer is another legal entity in the group, use the appropriate intercompany due-to / due-from ledger rather than an ordinary third-party payable.

The system must not net unrelated party balances silently. A third-party settlement must retain the original payer, beneficiary, linked transaction and settlement reason so Accounts can trace both sides.

## 15. Migration intent
Legacy SQL Server data can be used to migrate/clean:
- Chart of Accounts hierarchy
- subsidiary/party ledgers
- bank/cash accounts
- opening/current balances
- outstanding receivables/payables
- historical transactions where appropriate

Legacy passwords are never migrated.

## 16. Current implementation status on `accounts-v1-foundation`
The branch contains the Accounts V1 app-style UI, authenticated Accounts entry point, secure JSON-backed Accounts API, balanced immutable journals, reversals, non-ledger reminders, receipt-linked commodity bills, export recognition review, and Local Sales control/approval monitoring. Operational source modules remain separated from posting authority: source staff record business facts; Accounts approves controlled financial events; Transtrade creates the accounting entry.
