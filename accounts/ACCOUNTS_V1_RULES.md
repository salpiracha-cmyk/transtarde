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

## 13. Migration intent
Legacy SQL Server data can be used to migrate/clean:
- Chart of Accounts hierarchy
- subsidiary/party ledgers
- bank/cash accounts
- opening/current balances
- outstanding receivables/payables
- historical transactions where appropriate

Legacy passwords are never migrated.

## 14. Current implementation status on `accounts-v1-foundation`
The branch contains the Accounts V1 app-style UI, an authenticated Accounts entry point, and a secure JSON-backed Accounts API supporting balanced immutable journals, reversals and non-ledger reminders. Operational auto-posting remains intentionally blocked until account mappings and posting rules are defined in Masters.
