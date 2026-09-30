# Transtrade Accounts V1 — Locked Foundation Rules

## 1. UX and accounting governance
Users enter business events in operational language. The accounting engine creates professional balanced double-entry underneath. JV remains an accountant-grade exception. UK GAAP / FRS 102 style principles are the design baseline, subject to each entity's applicable statutory, tax, payroll, disclosure and audit rules.

## 2. Legal entities
Accounts has separate books for:
- Transtrade International (TTI)
- Buksh Rice Mills (BRM)
- Trans Grains (TG)

Authorized management may have group visibility, but legal books never mix. TG transfers keep the previously approved bank-credit-advice / USD-AED / settlement workflow and are not reduced to a generic bank transfer. TTI and BRM Pakistan shipment costs remain in the shipper's own books.

## 3. Accounts V1 home structure
- Dashboard / Needs Attention
- Purchases / Sodas
- Due Date Report
- Supplier Ledgers & Payments
- Customer Ledgers & Receipts
- Expenses & Overheads
- Transport
- Freight
- Export Service Bills — Clearing / Customs Agent and Fumigation
- Cash & Bank
- Journal Voucher
- Reconciliation
- TG / Intercompany
- Reports
- Accounts Masters

Courier and third-party inspection/surveyor charges remain ordinary ledger expenses with no separate icon.

## 4. Common supplier / customer ledger engine
Supplier payments and customer receipts use one allocation engine supporting:
- full and partial settlement;
- bill-to-bill allocation;
- round/on-account payments or receipts;
- advances before bills/invoices;
- later allocation of unallocated amounts;
- cash, cheque and bank;
- third-party settlement with explicit payer/beneficiary trail;
- editable/correctable posted entries through audited adjustment/reversal history rather than silent overwrite.

Customer ledgers retain their own transaction currency only. Do not show PKR values inside a USD/EUR/AED customer ledger. Management may separately calculate an indicative PKR equivalent using an SBP indicative exchange rate.

## 5. Expenses
Main Expenses area contains Utilities, Credit Cards, Rent & Recurring, Other Purchases, Salaries/Staff and normal operating ledgers. Company-car fuel does not require individual registration numbers.

Fixed-asset capitalization and depreciation are intentionally outside V1. Year-end asset/depreciation treatment will be reviewed separately. Do not auto-depreciate or force a V1 asset register.

## 6. Purchases / Sodas
Use one `Purchases / Sodas` icon. Inside it show:
- All Commodities
- Rice
- Corn / Makai
- Sesame

Each commodity stays separately trackable while the consolidated view shows open, overdue and completed Sodas.

Every Soda has:
- auto/system Soda number;
- Soda date;
- mandatory Arrival Due Date;
- broker/supplier;
- commodity/variety/type;
- quantity range / tolerance in MT;
- rate and rate unit;
- mandatory payment terms — Cash or Credit;
- remarks / commercial conditions.

Automatic Soda settlement/closure is the normal rule. When cumulative valid arrivals reach the lower quantity limit, the Soda may show completed, but another valid bill/arrival can still be added while cumulative quantity remains within the upper range. Manual close is an exception with reason and audit trail.

## 7. Arrival Due Date and overdue Sodas
Rice Soda default Arrival Due Date = Soda Date + 8 days.
Corn / Makai default Arrival Due Date = Soda Date + 10 days.
The due date is changeable according to the Soda condition. Preserve original date and every extension in history.

If the due date passes with undelivered balance, Accounts and Directors repeatedly see `Needs Attention` until resolved. Resolution options include:
- due-date extension;
- agreed market-difference settlement;
- short close / balance cancellation;
- manual close as last-resort exception.

## 8. Arrival / Pohanch liability and disputes
Once an Arrival Pohanch has been issued, the related purchase amount is company liability/exposure and must remain visible to Directors even when disputed or late.

A late/disputed/unsettled Pohanch is placed in `On Hold / Disputed Liability`:
- included in Directors' liability/exposure;
- visible in supplier/broker reconciliation;
- visible to Accounts and Directors under Needs Attention;
- excluded from normal payment selection so it cannot be paid accidentally;
- reason and history retained.

When resolved, authorized Accounts/Directors may release, adjust/settle or close the issue while preserving audit history.

## 9. Commodity payment due dates / market Due Date Report
The Due Date Report contains BOTH Cash and Credit Sodas and is intended to pay the whole market falling due on the same date together.

- Cash Soda due date = Arrival/Pohanch Date + 2 days.
- Credit Soda due date = Arrival/Pohanch Date + agreed credit days.

The report groups supplier/broker liabilities by actual due date and shows held/disputed liabilities separately from normal payable selection.

## 10. Rice
Rice remains Soda-driven with variety-specific KAT rules. IRRI-6 approved rules already held in the existing Rice work are not to be silently copied to other rice varieties. Draft/uncertain Rice KAT items stay configurable until explicitly confirmed.

## 11. Corn / Makai
Corn Sodas are quoted per maund. `1 maund = 40 kg`.

Purchase value:
`Net payable kg / 40 × Soda rate per maund`.

Corn KAT Master is shared/editable by authorized owner/director and Accounts:
- Moisture: up to 14% free by default (editable in KAT Master); each 1 percentage point above the configured free threshold deducts 1 kg per 100 kg of Karachi weighbridge weight.
- Damage/Fungus: up to 2% free; each 1 percentage point above 2% deducts 1 kg per 100 kg of Karachi weighbridge weight.
- Other deduction: entered as kg per 100 kg with mandatory reason.
- All Corn KAT/deductions use Karachi weighbridge weight as basis.
- Current brokerage: Rs 10 per 100 kg of Karachi weighbridge weight.

Do not finalize/change the Corn Milling UI yet. Accounts calculation rules may be built now; exact mill-side data-entry workflow will be decided later.

## 12. Sesame
Sesame has two types:

Ready Sesame:
- 1% admixture free;
- every 1 percentage point above 1% deducts 1 kg per 100 kg;
- current brokerage Rs 15 per maund.

Raw Sesame:
- 3% admixture free;
- every 1 percentage point above 3% deducts 1 kg per 100 kg;
- current brokerage Rs 10 per maund.

Other deductions are kg per 100 kg with reason. One maund = 40 kg.

## 13. Transport
Transport bills use the authoritative Exports/Milling shipment source. Search and posting share the same programme resolver, including older booking-number fields (booking number and loading programme number mean the same thing). A linked shipment does not require manual registration in Accounts. Only saved, distinct actual container numbers count as loaded containers; planned quantities are not billable. TG references remain visible in both Pakistan companies, but the Pakistan exporter must be identified before posting to its books.

One transporter invoice can cover one or several shipments. Enter the transporter, supplier bill number and BILL DATE once. Each shipment section automatically shows its customer, contract, lot, loading programme, route and existing container numbers. Enter the billed container count and rate; add or deduct shipment charges in the common bill-row format. Show SHIPMENT BILL PAYABLE below each section. “Add another shipment” colours existing shipment sections blue and opens search immediately below them without clearing entered information. Already selected shipments are excluded from search. The chosen new section appears immediately below and scrolls into view; Remove shipment is a clearly separate red button. Show one bold TOTAL BILL PAYABLE at the bottom and post one supplier liability and balanced journal for the whole invoice.

The route master suggests the route rate, never a previous invoice rate. A changed master rate requires a reason. One Loading Programme may cover multiple shipments and multiple sales contracts when their discharge port is the same. Do not reject a programme merely because contract references differ. Retain the selected contract/lot against each bill line; aggregate distinct actual containers across its shipments for programme utilization. Shipments on the same programme with missing or different discharge ports must be corrected in Exports. Partial container billing is allowed; the sum across all invoice lines and previously posted bills cannot exceed the authoritative loaded count for that programme. A transporter plus supplier invoice number is a hard duplicate within the company. Source shipment/company/programme mismatches, unidentified TG exporter and missing actual containers block posting. Keep older manually registered programme bills amendable; an explicit linked shipment always requires current source verification.

Save the shipment IDs, contract/lot references, shipment charges and each payable subtotal in the same bill source record. Supplier ledgers retain one aggregate liability, while shipment profitability uses each line’s payable amount. Corrections preserve original history and payment allocations; a corrected liability cannot fall below already settled payments. No cash POST ID is generated for a non-cash supplier bill.

Shared Transport Master: From Where, To Where, Rate. Normal and ex-mill routes can be added by authorized Accounts/owner users. Bills allow Kanta, commission, detention and other described additions/deductions. Errors must be visible, successful posting must show the bill number, and uncertain network responses must be checked against the invoice register before permitting a retry.

## 14. Freight
Freight is usually through a freight forwarder and sometimes directly through a shipping line.

Accounts Freight has Freight Agreed, Post Freight Invoice and Payment tabs. Directors may also access agreed freight. The planning agreement records:
- forwarder / shipping line;
- from Karachi Port or Port Qasim;
- destination port;
- container size — default 20', optional 40';
- currency;
- agreed rate per container;
- date agreed;
- remarks.

Forwarders should be asked to print the actual export B/L number. Accounts search/matching supports actual B/L, invoice no., job/reference, Loading Programme and any existing container number. Container numbers are search keys sourced from existing Exports/Milling data; Accounts does not re-enter them.

Freight invoices support flexible charge lines. A line may be per container, per B/L or fixed and may use USD/PKR/other approved currency with the invoice exchange rate.

Each charge line separates:
- Billed rate/amount;
- Accepted/Agreed rate/amount;
- Disputed difference.

Supplier liability is created on the Accepted/Agreed amount only. Disputed differences remain in a Freight Dispute/Reconciliation statement by B/L/invoice and may remain open for months or more than a year. Later settlements/waivers are retained in history.

LOLO and similar charges are not hard-coded to one vendor. They may be charged by yard, transporter or forwarder. The system should warn when the same shipment/charge type appears elsewhere so Accounts can review possible duplicate charging.

Actual Freight used in shipment profitability comes from Freight ledger/entries only, never from the Sales Contract.

## 15. Clearing / Customs Agent
Keep simple. Hard duplicate control:
`Clearing Agent + Invoice/Bill No.`

Search/matching can use GD/Shipping Bill No., job no., shipment/reference, destination and container count. No special `Less Advance` bill logic: all advances, round/on-account payments and allocations use the normal supplier ledger engine.

## 16. Fumigation
No Loading Programme requirement. Hard duplicate control:
`Fumigator + Invoice No.`

Search/secondary matching uses the natural invoice details: customer reference, Phyto/certificate no., Phyto date, container quantity, commodity, destination and service type. Legitimate corrigendum/certificate charges remain possible. Normal supplier ledger settlement applies.

## 17. Export Sales / Receivables
Export sale data comes from Exports and should not be retyped in Accounts. TTI shipper posts to TTI; BRM shipper posts to BRM.

Customer ledger remains exclusively in its invoice/transaction currency. Management may show a separate indicative PKR equivalent using SBP rates.

Freight and other shipment costs are linked from their actual expense ledgers/entries to the invoice/lot/shipment for profitability.

## 18. Local Sales
Local sales flow from Milling to Accounts. Payment terms may be Cash or Credit. Cash is due the same day; Credit due date = sale/invoice date + agreed credit days. Local sales in this workflow have no Sales Tax or WHT treatment.

Existing Local Sales control/approval monitoring remains in force, including the separate internal management threshold controls already implemented.

## 19. Bank / Cash / transfers
Bank, cash, cheque, PDC, bounced/cancelled/stopped cheque, bank transfer and reconciliation workflows remain available. Inter-account and inter-group/inter-entity transfers require narration/reason stating why funds were moved. TG-specific bank-credit-advice / FX treatment remains separate from generic transfers.

For supplier post-dated cheques, issue posts the selected payable to Issued Cheques Awaiting Bank Clearance (2180) and leaves the bank balance untouched. Bank clearance posts 2180 to the selected bank. Cancelling or stopping before clearance restores the original payable. A cheque returned after clearance reverses the bank clearance and restores the payable with linked, dated journal entries. The cheque number is unique per entity and bank, and a resolved cheque cannot be cleared or reversed again.

For local customer post-dated cheques, issue moves the selected Local Soda receivable to Customer Cheques in Hand (1130), without increasing the bank book. Clearance debits the chosen company bank and credits 1130. Cancellation or bounce restores the receivable, with a separate bank reversal if the cheque had cleared. This path requires an existing open Local Soda receivable and cannot create a customer advance.

Freight Agreed is planning data tied to the selected Export shipment and contract. Exports loading instructions may fill empty shipping-line and loading-programme fields from it; a user-entered value remains authoritative. TG contracts search both TTI and BRM Pakistan-exporter books. If both have a matching agreement, the exporter must be identified before applying either one. The agreed USD rate is shown as a reference and never posts to the ledger. Freight invoice posting uses the carrier's actual exchange rate.

## 20. Journal Voucher
JV is mandatory and supports multi-line debit/credit entries. Total debit must equal total credit. JV must not become a shortcut around normal operational workflows. Corrections use reversal/amendment history rather than silent deletion.

## 21. Masters and permissions
Avoid duplicate masters across modules. Canonical shared party records may carry multiple roles. Shared masters include parties, banks/accounts where approved, Transport Master, commodity/KAT rules, mills/locations, forwarders, clearing agents and fumigators. Legal entity identity and core protected configuration remain owner/Super Admin controlled.

Accounts permissions are entity-specific and may allow TTI only, BRM only, TG only, approved combinations or all. Sensitive changes require reason and audit trail.

## 22. Reports / management
V1 reporting includes General Ledger, Trial Balance, P&L, Balance Sheet, supplier/customer ledgers, bank/cash, JV register, purchase/sales/expense reports, ageing, Soda/Arrival/Pohanch/KAT/brokerage reports, market Due Date Report, Transport Register, Freight Register and Freight Dispute Statement.

Needs Attention should prominently show overdue Sodas, late arrivals, held/disputed liabilities, freight disputes and other actionable exceptions.

## 23. Deferred from V1
- mandatory month-end close / forced period lock;
- automatic fixed-asset capitalization/depreciation workflow;
- mandatory document scanning/attachments — upload may exist but remains optional;
- Crystal/opening-balance migration until after functional QA is complete.

System audit history remains mandatory even when document scans are not used.

## 24. Migration order
Finish Accounts build, test with dummy data, correct workflows/calculations/permissions/reports, confirm TTI/BRM/TG separation, then determine cut-off and migrate required Crystal/opening balances. Reconcile the migration before production go-live.

## 25. Third-party settlements
A payment made directly by a third party to a Transtrade supplier/service provider must not be represented as money moving through a Transtrade bank/cash account. Record payer, beneficiary, linked transaction, amount/date, settlement reason and the correct third-party receivable/payable or intercompany account.

## 26. Accounts navigation, supplier payments and ledger statements
These rules apply to TTI, BRM and TG within each company’s legal books and approved currencies.

Non-commodity bill areas expose Post Bill and Payment. Freight exposes Freight Agreed, Post Freight Invoice and Payment. One supplier, invoice number and bill date may cover several selected shipments. Each shipment retains its additions, deductions, GD, programme, contract/lot and amount. Previous shipment panels are blue; remove buttons are red; selected shipments are excluded. Accounts posts one invoice liability. Shipment profitability uses each subtotal; unallocated later dispute adjustments remain visible.

Choose supplier first to show unpaid/part-paid invoices with bill date, total, paid and outstanding. Tick a subset or use all bills. Enter a payment amount or use full selected outstanding. Allocate oldest invoice date first, partially paying the last invoice. Revalidate balances under the exclusive storage lock. Payments post once with retry-safe request IDs. Bank, Cash and Third Party expose relevant source fields. Third-party settlement creates no company bank/cash movement. Bags and Other Purchases registers refer to the same journal and original invoice IDs. Commodity truck/Pohanch payments remain unchanged. For Pakistan bills, the user may explicitly tick Record excess as supplier advance; the journal settles the bills and records the surplus as an available advance. TG excess remains a separate explicit supplier-advance transaction. Never silently overpay an invoice.

TG settlements retain original currency and AED carrying values, each liability’s carrying rate and existing realised exchange difference rules. Select one payee and currency; intercompany FI/GD settlement retains its dedicated source workflow. TG cash is AED; foreign-currency bills use a matching bank or explicit third-party settlement. TG transaction reference remains optional.

Ledgers has Supplier/Broker, Customer, Bank/Cash and Other Account. Registers & Corrections contains Post ID, JV and bill/invoice registers. Financial reports remain Reports; global search remains without duplicate ledger search/audit icons. Till Date shows all history through the selected date. Date Range includes opening balance before start, period debit/credit and closing balance. Statements show date, Post ID/JV, bill/invoice reference, account, narration/party, debit, credit and running balance. Print and real XLSX download use the displayed statement, excluding unsubmitted filter edits. Journal/voucher details open from each entry.

## 27. Current implementation
Accounts development and its QA workflow are maintained on `main`. The old `accounts-v1-foundation` branch is historical and is not a deployment or QA trigger. Changes must pass the Accounts QA checks before a production deployment. Confirm live behaviour and TTI/BRM/TG separation with test data before production go-live.


### Transport entry and shared selectors — 30 September 2026

- Transport entry has aligned Loading Programme, Containers and Rate fields. There is no shipment remarks / rate override reason field, and changing the suggested Route Master rate does not require a separate reason. Bill narration remains optional; additions and deductions retain their descriptions. Historical remarks and rates are preserved.
- Shared master, JV and expense suggestion lists must not be reassigned when unchanged. Unrelated totals or screen updates must preserve the focused field and its open choices. Searchable selections must reopen on a click even when the field is already focused.
