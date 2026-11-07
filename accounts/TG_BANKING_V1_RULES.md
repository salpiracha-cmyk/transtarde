# Transtrade V1 — TG Banking Rules

## Ownership of master data
- Company bank identities and each directional exchange rate are maintained in Super Admin → Companies → the company concerned. The Exchange rates section appears for companies outside Pakistan and offers the currencies of their active bank accounts.
- Enter A → B as units of B for one unit of A, and B → A separately as units of A for one unit of B. They need not be mathematical reciprocals. Accounts reads the approved company rates; TG Master retains closing/tax and historical rate sets.

## Native bank currencies
- TG USD bank accounts remain native USD ledgers.
- TG AED bank accounts remain native AED ledgers.
- The AED reporting/closing layer sits underneath the native bank movement so both native balance and AED carrying value remain traceable.

## Approved TG Master rates
- Operational USD → AED and AED → USD rates are entered as a directional pair under the TG company. USD → AED is AED per USD; AED → USD is USD per AED. The transfer form displays its AED per USD equivalent in the reverse direction for its existing journal calculation.
- Historical TG Master rates remain a fallback only where no company pair has been saved.
- Final Accounts / Tax closing translation: 3.6700 AED per USD for now, effective-dated and editable in TG Master.
- TG year-end: 31 December.
- An actual transaction may use a different rate only when Accounts records an override explanation; both master and actual rate are retained.

## USD ↔ AED own-account conversion
- TG own-bank USD→AED and AED→USD conversions are bank transfers, not income or expense.
- No fee is assumed. A real fee must be recorded separately if a bank actually charges one.
- Native source and destination balances must move exactly by the bank amounts.
- AED carrying-value differences on the USD balance are posted to FX gain/loss where applicable.

## TG customer receipts
- Normal customer receipt is allocated to a recognized TG Export Receivable flowing from Exports / Export Recognition.
- Accounts does not retype customer, contract or recognized invoice value when the source candidate exists.
- Receipt-bank currency must match the receivable transaction currency. Cross-currency movement is handled separately through TG bank conversion.
- Customer receipt equals the amount credited by the bank. Incoming charges and VAT are separate debits from the same selected bank account, each in that account's native USD or AED currency.
- USD charges and VAT are translated at the receipt's accounting rate into AED for the Bank & Finance Charges and input VAT ledgers. They do not reduce the customer advance or receivable settlement.
- Any difference between the receivable AED carrying amount and the AED value of the customer receipt posts to FX gain/loss.
- If the contract advance arrives short, record the actual amount and leave the difference outstanding. The final commercial invoice deducts the actual customer advance, increasing its balance payable by the shortfall while preserving the invoice's goods value.
- A genuine customer receipt before invoice/revenue recognition posts to 2510 Customer Advances / Unapplied Receipts and is allocated later after recognition.

## TG liabilities
- A supplier/service bill is recorded in Accounts only when Accounts is the true source of that business event and no other Transtrade module already owns it.
- The supplier/service liability stores native USD/AED amount, AED recognition/carrying rate, supplier, invoice/reference, due date, expense/asset classification and payable account.
- Duplicate supplier + invoice/reference is rejected.
- TG↔Pakistan intercompany payable is source-driven from the TG-linked Export transaction and its explicit Pakistan internal value. Accounts must not re-enter or infer that value.
- For the TG intercompany side, Accounts confirms only recognition date, AED conversion rate and cost classification: Purchased Commodity Inventory, Finished Goods Inventory or Commodity Cost of Sales.
- The Pakistan intercompany receivable and TG intercompany payable retain the same source shipment/reference for reconciliation.

## TG payments
Accounts selects the business substance; Transtrade creates the journal:
- Pay Open TG Liability → select the exact supplier/service or intercompany liability; the system reads its payable account and AED carrying value automatically.
- Partial liability payment is allowed; payment above the selected liability outstanding is blocked.
- Payment-bank currency must match the liability currency. Convert funds between TG USD/AED accounts first when needed.
- Direct expense/service → approved expense account.
- Supplier/service advance → 1250 Supplier / Service Advances until later allocated.
- Fixed asset purchase → approved fixed-asset account.

## USD payment carrying value
- For a payment from a USD bank, the exact native USD amount reduces the USD balance.
- The AED credit to Bank uses that USD bank's current AED carrying rate before payment.
- The selected liability is reduced using its current AED carrying rate; a direct expense/asset uses the transaction-date rate.
- Any resulting difference posts separately to FX gain/loss.

## Bank charges
- Charges remain separate from the underlying receipt/payment.
- On a receipt, the native bank ledger shows the full customer credit and a separate charge-plus-VAT debit from the same account; both movements remain visible.
- Charges post to Bank & Finance Charges with native amount, currency and transaction reference retained.

## Controls
- Exact TG Bank Master account is mandatory.
- Bank account must be active in Accounts and enabled for the requested receipt/payment direction.
- Bank reference is optional. If supplied, duplicates on the same TG bank are rejected. If blank, the system uses its generated TG bank transaction ID as the reference.
- Payment amount plus charges cannot exceed the bank's native book balance.
- Posted transactions are journal-backed and retain user, date, bank reference, counterparty, rates and source linkage.

## 31 December AED remeasurement
- TG final accounts/tax reporting closes on 31 December in AED.
- The year-end FX routine previews supported USD monetary assets and liabilities before posting.
- Supported assets: active TG USD bank balances and open recognized TG USD Export Receivables.
- Supported liabilities: open TG USD supplier/service liabilities and recognized TG↔Pakistan USD intercompany payables.
- Native USD balances do not change during remeasurement; only AED carrying values are adjusted to the TG Master closing/tax rate.
- Asset increases create FX gains; liability increases create FX losses, with the correct opposite treatment for decreases.
- After remeasurement, open receivables and liabilities carry forward at the new closing rate so later settlements clear the post-close AED carrying value.
- A revaluation journal is still posted when individual asset/liability adjustments are required even if their net FX effect is zero.
- Future posting and duplicate posting for the same year-end are refused.
- This remeasurement is not by itself the final statutory/tax close or period lock; wider cutoff, reconciliation, tax and closing review remains required.

