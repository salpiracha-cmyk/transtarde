# Transtrade V1 — Shared Stock Costing Rules

## Simple rule
Mill / Exports record the physical movement and actual quantity. Accounts records the money value of that stock movement. Staff must not enter the same quantity again in Accounts.

## Directors costing sheet and live fallback
The long-term daily costing sheet belongs in the Directors Module. It will hold the Directors daily Raw Rice rate, by-product rates and estimated Ready Rice cost.

Until that Directors data is available for a day, Transtrade prepares a live costing sheet automatically:
- Raw Rice uses the actual purchase cost available in Accounts for the relevant variety. If the final purchase bill is still pending, the sheet clearly says the Raw Rice value is provisional.
- Each by-product uses the latest saved Mill sale rate for that same by-product on or before the costing date.
- Milling / processing is added at **Rs 3 per kg of Raw Rice input**.
- Stone, sutli and dust remain nil-value waste unless another approved treatment is later set.
- If a by-product has never had a saved Mill sale and the Directors sheet has no rate, Transtrade does not invent a rate. The costing sheet stays Waiting for that item.

The live Ready Rice estimate is:

`Raw Rice value + (Raw Rice input kg × Rs 3 milling) - by-product value = estimated Ready Rice value`

`estimated Ready Rice value ÷ actual Ready Rice output kg = estimated Ready Rice cost per kg`

When the Directors daily costing sheet is later available, its daily figures replace the fallback figures for management costing without changing the Mill screen.

Every live costing sheet has its own sheet ID. Local Sales and Export cost records keep a link to the applicable costing sheet so the source of the Cost of Goods figure can be opened later. The same sheet can also be used by future Directors working-capital and cash-flow views.

The Rs 3/kg figure is the management milling estimate. Actual KE, labour, rent, salary and other processing bills remain separately recorded in Accounts for final accounts and audit. The management estimate must not erase those real expenses.

## One stock-cost master
Local Sales and Export Sales use the same effective-dated stock-cost rates. A product cost saved once can be used by both workflows when the company, product and date match.

Existing older Local Sales cost rates remain readable for backward compatibility. New rates are saved in the shared stock-cost rate store.

Management costing-sheet rates are marked as estimates and carry the costing-sheet ID. A final approved stock cost remains the stronger Accounts value for final reporting. Any management estimate already used before the final cost is known must remain traceable for later reconciliation rather than being silently rewritten.

## If cost is not known
Revenue/sale recognition is not given a fake final stock cost. A live management estimate may still be shown from the costing sheet. If neither an approved cost nor a usable costing sheet exists, the item stays `Cost Waiting`. Period close must show unresolved cost items.

## Automatic production stock cost
Accounts also has a final production-cost calculation that reads the operational data already saved by Mill. It does not add a new Mill field or ask Mill staff to enter the same data again.

The final Accounts calculation can use:
- finalized Pohanch / commodity purchase value, including purchase brokerage that forms part of inventory cost;
- saved production quantity and output mix;
- saved labour bills;
- saved KE / electricity bills;
- saved processing expenses;
- approved by-product values;
- eligible Mill Staff salary and Mill Rent.

Production input remains the existing Transtrade inferred input: the total stock-posting production outputs for the saved shift/day. The costing engine does not create a second physical stock movement.

Purchase stock is costed using a moving weighted average by recognized rice variety. Receipts on a date are made available before production on that same date for the costing calculation.

A final production cost cannot be approved when important source information is missing or inconsistent. Transtrade keeps the item Waiting rather than inventing a final cost.

## Production stock value transfer
After the final production cost is approved, Accounts posts the value movement for that production run.

The physical quantity remains controlled by the Mill production record. Accounts does not create another quantity movement.

The value movement is:
- Raw / Purchased Rice Inventory decreases by the finalized raw rice value used in production.
- Ready / Finished Rice Inventory increases by the final Ready Rice value.
- By-product Inventory increases by the approved by-product value.
- production/conversion costs included in inventory are absorbed from the approved conversion-cost pool;
- eligible Mill Staff salary and Mill Rent included in inventory are capitalized out of their expense accounts for the amount allocated to that production run.

A production run cannot be posted twice. If the approved production cost later changes, the original stock-value journal is not silently rewritten; a controlled stock-value adjustment is required.

## Pakistan Local and Export Sales
Local and Export sales use the physical quantity already saved by the operational module. Accounts never reduces the same quantity a second time.

The sale can carry a live management Cost of Goods estimate from its costing sheet while final Accounts costing is still being completed. The costing-sheet ID, rate and estimated amount stay with the sale for working-capital and management reporting.

## TG-linked customer sales
For a TG customer sale linked to the Pakistan intercompany purchase:
- if the TG intercompany purchase was already posted directly to Cost of Sales, the customer sale reuses that cost and creates no second cost journal;
- if the TG intercompany purchase was posted into TG inventory, the customer sale moves that exact AED carrying amount from TG inventory to Cost of Sales;
- if the TG intercompany purchase is still waiting for Accounts approval/classification, the TG customer sale shows `Cost Waiting`.

This prevents double costing and prevents the TG cost from being guessed from the customer selling price.

## Mill screen rule
The costing sheet reads existing Mill production and sale data. It must not add or change a Mill field or change what Mill staff have to do. Any future Mill-facing change requires owner approval first.

