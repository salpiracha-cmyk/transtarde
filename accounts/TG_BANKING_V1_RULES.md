# Transtrade V1 — TG Banking Rules

## Ownership of master data
- TG Master is maintained only from Directors / Super Admin.
- Accounts consumes approved TG bank identities, USD/AED operating rates and the closing/tax rate; Accounts does not maintain TG Master.

## Native bank currencies
- TG USD bank accounts remain native USD ledgers.
- TG AED bank accounts remain native AED ledgers.
- The AED reporting/closing layer sits underneath the native bank movement so both native balance and AED carrying value remain traceable.

## Approved TG Master rates
- Sell USD / receive AED: 3.6700 AED per USD unless an effective-dated TG Master row changes it.
- Buy USD / pay AED: 3.6750 AED per USD unless changed in TG Master.
- Final Accounts / Tax closing translation: 3.6700 AED per USD for now, effective-dated and editable in TG Master.
- TG year-end: 31 December.
- An actual transaction may use a different rate only when Accounts records an override explanation; both master and actual rate are retained.

## USD ↔ AED own-account conversion
- TG own-bank USD→AED and AED→USD conversions are bank transfers, not income or expense.
- No fee is assumed. A real fee must be recorded separately if a bank actually charges one.
- Native source and destination balances must move exactly by the bank amounts.
- AED carrying-value differences on the USD balance are posted to FX gain/loss where applicable.

## TG customer receipts
- Normal customer receipt should be allocated to a recognized TG Export Receivable flowing from Exports/Export Recognition.
- Accounts does not retype customer, contract or recognized invoice value when the source candidate exists.
- Receipt-bank currency must match the receivable transaction currency. Cross-currency movement is handled separately through TG bank conversion.
- Incoming bank charges may reduce the native amount credited to the bank while the gross receivable settlement remains traceable.
- Any difference between the receivable AED carrying amount and the AED value of bank credit + bank charges posts to FX gain/loss.
- Intercompany and other existing receivable recoveries may be settled with an explicit AED carrying amount.
- A genuine customer receipt before invoice/revenue recognition posts to 2510 Customer Advances / Unapplied Receipts. It is later allocated against the receivable only after the sale/invoice is recognized.

## TG payments
Accounts selects the business substance; Transtrade creates the journal:
- Direct expense/service → approved expense account.
- Supplier/service advance → 1250 Supplier / Service Advances until the supplier bill/service is recognized and allocated.
- Existing payable → approved payable account; Accounts confirms the AED carrying amount being cleared.
- Intercompany payment → Intercompany Payable; Accounts confirms the AED carrying amount being cleared.
- Fixed asset purchase → approved fixed-asset account.

## USD payment carrying value
- For a payment from a USD bank, the exact native USD amount reduces the USD balance.
- The AED credit to Bank uses the bank account's current AED carrying rate before payment.
- The expense/asset amount uses the transaction-date rate; an existing payable uses its carrying amount.
- Any resulting difference is posted to FX gain/loss.

## Bank charges
- Charges remain separate from the underlying receipt/payment.
- The bank native movement includes the actual charge where deducted from the TG account.
- Charges post to Bank & Finance Charges with native amount, currency and transaction reference retained.

## Controls
- Exact TG Bank Master account is mandatory.
- Bank account must be active in Accounts and enabled for the requested receipt/payment direction.
- Duplicate bank reference on the same TG bank is rejected.
- Payment amount plus charges cannot exceed the bank's native book balance.
- Posted transactions are journal-backed and retain user, date, bank reference, counterparty, rates and source linkage.

## 31 December AED remeasurement
- TG final accounts/tax reporting closes on 31 December in AED.
- The year-end FX routine previews supported USD monetary assets before posting.
- TG USD bank native balances remain unchanged; only their AED carrying value is adjusted to the TG Master closing/tax rate.
- Open recognized TG USD Export Receivables are remeasured to the same closing rate.
- After remeasurement, the remaining receivable carries forward at the new closing rate so a later receipt clears the correct AED carrying value without recreating the old pre-close rate.
- The routine refuses future posting and duplicate posting for the same year-end.
- The current automated scope covers TG USD banks and recognized TG USD export receivables. Foreign-currency payable/intercompany liabilities still require native-currency subledger tagging before they can be included automatically.
- This remeasurement is not by itself the final statutory/tax close or period lock; wider cutoff, liability, reconciliation and tax review remains required.
