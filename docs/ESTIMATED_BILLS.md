# Estimated Bills

Cash Flow and Accounts → Reports → Estimated Bills share the same read-only source calculation. Amounts retain PKR, USD or AED, with the saved daily conversion rate shown separately. Missing amounts and rates are visible; a complete position cannot silently treat them as zero.

## Automatic sources

- **Commodity receipts:** issued Pohanch for rice, corn, wheat and sesame uses the saved SODA purchase rate and payable weight. A receipt already posted to accrued/unbilled purchases (2210) remains in that native balance and is not projected twice. Posting the actual commodity bill uses the existing GRNI-clearing workflow.
- **Freight:** a shipment starts estimating only after its native Mill/Ex-Mill loading allocations are completed through the report date. Completed containers use the matching saved Accounts/Directors freight agreement. Original posted bills reduce their actual container coverage. Cancelled bills restore the forecast. Missing agreements, ambiguous rates, incomplete agreement coverage or unknown actual-bill allocations remain explicit review items.
- **Bags:** dated received quantities less posted invoiced quantities use the latest comparable company/supplier/currency/packing/material bill rate. Forty- and fifty-kilogram packings are matched separately. An explicitly agreed PO rate is a labelled fallback. Unknown rates or tax treatment require review; undated receipt caches are not backdated.

## Other shipment expenses

Accounts → Expenses → Expense Invoices → Estimated bills supports any expected shipment cost, including clearing, transport, inspection, fumigation, labour and other services. Save the supplier, unique shipment/cost reference, expense description, quantity, rate and applicable tax treatment. Comparable-bill suggestions retain the source invoice and do not change the entered quantity. Optional source details link a manual estimate to the exact automatic source shown in the report; a linked manual estimate replaces that automatic forecast.

Gross forecast = supplier net + WHT. Withholding is part of gross, not an additional expense. A confirmed net quote may be grossed up using the explicitly entered WHT basis and rate. Historical WHT rates are not assumed applicable to a new transaction.

When the actual bill arrives, record it through its normal workflow. Link it to the manual estimate using **Replace with actual bill**, with an explanation. The estimate register preserves the original estimate and shows the variance. Reversed or cancelled actual bills do not silently remove the expected cost. Manual cancellation has an effective date and recorded reason.

## Book and access integrity

Reading or saving a forecast does not create a supplier invoice, financial journal, stock movement, tax claim or payment. Company ledgers, trial balances and balance sheets continue using actual postings and existing native accruals. Internal group parties remain outside consolidated cash flow. Native accrued balances are retained and overlapping unmatched forecasts require reconciliation.

Create/Edit and company rights use the existing Expenses permissions. Comparable invoice access retains its original workflow permission. Changes are revision checked, retry safe and recorded in the expense audit with the actor and before/after values. Historical report snapshots are retained until an explicit new preparation/save.

## Verification

Regression coverage includes authenticated estimate lifecycle, access limits, retry conflicts, gross/net/WHT calculations, comparable source validation, issued receipt and loading cutoffs, partial actual bills, cancellation, native accrual deduplication, report scope, PKR conversion, and browser entry. Test fixtures contain synthetic data only.
