# Partial expense payments — implementation plan, 20 October 2026

Status: requested and planned; the additional expense-bill workflow below is not yet deployed. Party restoration and duplicate-opening safeguards are tracked separately in PR425. Existing invoice/B/F payment options must remain available while this work proceeds.

## Confirmed current behavior

`accounts/supplier-settlement-ui.js` provides selected-invoice payments, an editable Amount to pay, an independent Include balance B/F tick, an allocation preview and later settlement. `api/supplier_settlements.php` preserves remaining invoice/B/F balances. Freight, transport, clearing, fumigation, inspection, packaging and other supported supplier bills use this route. Missing or misclassified master parties can hide the correct provider despite historical bills.

The present Pakistan bill-settlement rule automatically discounts a positive difference below PKR100. This conflicts with a user's intention to leave *any* unpaid balance for later unless a distinct partial-payment intent bypasses it. Do not rewrite earlier posted discounts. Existing explicit settlement-concession treatment remains separate from a partial payment.

`api/expenses_v1.php` utility/general expense actions mostly record a payment and its expense together; an editable payment amount does not establish the full supplier invoice liability. Paying part of an invoice through that form alone cannot create a correct later-invoice balance. Reimbursements already have their own outstanding and partial-settlement engine. Credit cards, salaries/remuneration, rent and TG payments have separate canonical allocation engines that must be preserved.

## Consistent choices

| Choice | User supplies | Result |
| --- | --- | --- |
| Pay selected bill(s) in full | Party, selected invoice(s), actual payment source/date/reference | Clear only the selected invoices, subject to their tax/withholding rules. |
| Pay part — keep balance | Selected invoice(s), editable actual amount, optional per-invoice allocation | Leave every unpaid rupee/paisa outstanding, including differences below PKR100; never infer a discount. |
| Pay balance B/F | Independently selected opening, actual amount | Allow B/F alone, part of B/F, or B/F plus specifically selected invoices. Show remaining opening and invoice amounts separately. |
| Settle with agreed discount | Selected invoice(s), explicit settlement/discount choice | Show cash, withholding, concession and zero/remaining balance in the accounting preview. Keep established small-rounding behavior confined to this settlement intent. |
| Pay on account / advance | Existing party, actual amount and source | Use the existing advance engine; allocate later to a selected original bill. No fabricated invoice or immediate second expense. |
| Record invoice, pay later | Original supplier invoice number/date, full total, expense/asset/tax breakdown | Record the full liability once and expose it to the same partial/full payment screen. |

Always show original invoice total, prior payments, chosen allocation, this actual payment and amount remaining. A round-number payment is just the amount actually paid, not a change to the invoice. Preserve original currency precision and dates; show no hidden write-off. Changing selection or amount updates only the preview until explicit Post.

## Coverage and implementation sequence

1. Finish and verify the urgent canonical-party/B/F correction. Ensure restored eligible service parties appear in both bill posting and payment choices. Prevent shortened duplicate creation, historical-party loss and similar-name duplicate openings.
2. Make the existing invoice-payment route discoverable from each service/expense bill's Pay action and the provider ledger. Keep the provider selected. Label the independent B/F choice clearly. Display full/partial intent and an explicit remaining-balance preview. A partial intent must bypass all automatic settlement-difference discounts in both UI and server logic, including old-client inputs.
3. Add a distinct invoice-capture/pay-later route for ordinary supplier/service and utility expenses that currently only support payment capture. Reuse the canonical payable register and settlement engine. Recognise the full expense/asset/tax liability once, then debit the existing payable for each later payment. Do not charge the expense again, reclassify historical paid expenses, create a JV for users to manage, or manufacture balances from free-text amounts.
4. Route reimbursement, rent, salary/remuneration/home, credit-card and TG cases through their existing authoritative engines. Add consistent entry links and remaining-balance labels where relevant. A payment toward a credit-card statement must not create the underlying expense again. An expense reimbursement must settle its original captured obligation.
5. Add outstanding-expense reporting by company, party, invoice, bill month, expense head, due date and paid/part-paid/unpaid status. Reconcile opening + invoices - payments - approved discounts to the party ledger; bank/cash reports use only actual cash movement.

## Acceptance evidence required

- Invoice123456.78 paid100000 leaves23456.78; later23456.78 clears the same invoice without another expense.
- Invoice1000.08 paid1000 in partial mode leaves0.08. A discount/settlement intent must be separately visible and auditable.
- B/F can be paid partially without selecting invoices; unselected invoices remain untouched. Combined payments allocate only to explicitly selected sources.
- Existing payments and allocations survive party restoration and source corrections; paid-source identity corrections require controlled source review.
- Test cash, cheque, online banking, third-party payer and eligible TG currency routes; maintain withholding and expense/GST treatment.
- Test partial then final settlement, multiple bills, concurrent balance changes, excessive/zero payments, replay/lost acknowledgement, reversal/amendment, company/role scope and cancelled-source exclusion.
- Verify the real UI using authorised reads and disposable financial fixtures. Do not create sample production bills/payments to demonstrate the feature.
