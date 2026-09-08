# Transtrade V1 — Shared Stock Costing Rules

## Simple rule
Mill / Exports record the physical movement and actual quantity. Accounts records the money value of that stock movement. Staff must not enter the same quantity again in Accounts.

## One stock-cost master
Local Sales and Export Sales use the same effective-dated stock-cost rates. A product cost saved once can be used by both workflows when the company, product and date match.

Existing older Local Sales cost rates remain readable for backward compatibility. New rates are saved in the shared stock-cost rate store.

## If cost is not known
Revenue/sale recognition is not given a fake stock cost. The item stays `Cost Waiting` until a valid cost source exists. Period close must show unresolved cost items.

## Automatic production stock cost
Accounts has a production-cost calculation that reads the operational data already saved by Mill. It does not add a new Mill field or ask Mill staff to enter the same data again.

The calculation uses:
- finalized Pohanch / commodity purchase value, including purchase brokerage that forms part of inventory cost;
- saved production quantity and output mix;
- saved labour bills;
- saved KE / electricity bills;
- saved processing expenses;
- saved Accounts by-product values.

Production input remains the existing Transtrade inferred input: the total stock-posting production outputs for the saved shift/day. The costing engine does not create a second physical stock movement.

Purchase stock is costed using a moving weighted average by recognized rice variety. Receipts on a date are made available before production on that same date for the costing calculation.

A production cost cannot be approved when:
- the relevant purchase bill is still provisional / not finalized;
- recorded production exceeds the available purchase stock for that variety;
- Ready Rice output is missing;
- a material by-product does not yet have an Accounts stock value;
- no labour/electricity/processing cost source has been found for the production period;
- by-product values exceed the available joint production cost.

Stone / sutli / dust output is treated as nil-value waste unless Accounts later defines another approved treatment.

## Ready Rice calculation
The working calculation is:

`final purchase stock cost + saved production costs - saved by-product values = Ready Rice stock cost`

The remaining Ready Rice cost is divided by the actual Ready Rice output kg to produce the effective Ready Rice cost per kg.

Accounts sees the purchase cost, production-cost share, by-product values and final suggested Ready Rice cost before approval. Approval also confirms that Accounts has checked the source bills.

Once approved, the generated Ready Rice cost is saved into the same shared stock-cost master used by Local Sales and Export Sales. Waiting Local/Export cost items can then pick it up automatically.

## Production overhead review
The engine uses the production costs that are actually available in Transtrade. Accounts must not approve the generated cost until all material production costs for that period have been entered and checked. Future Accounts Rent/Salary masters can feed the same engine without changing the Mill workflow or the Local/Export costing flow.

## Pakistan export sales
For recognized TTI / Pakistan-to-TG export sales, the completed shipment supplies the actual shipped quantity. Accounts applies the approved stock cost per kg and posts the matching stock value out of Raw/Purchased Stock, Finished/Ready Rice, or By-product Stock as applicable.

The physical stock quantity is not reduced again by Accounts because the operational loading already reduced it.

## TG-linked customer sales
For a TG customer sale linked to the Pakistan intercompany purchase:
- if the TG intercompany purchase was already posted directly to Cost of Sales, the customer sale reuses that cost and creates no second cost journal;
- if the TG intercompany purchase was posted into TG inventory, the customer sale moves that exact AED carrying amount from TG inventory to Cost of Sales;
- if the TG intercompany purchase is still waiting for Accounts approval/classification, the TG customer sale shows `Cost Waiting`.

This prevents double costing and prevents the TG cost from being guessed from the customer selling price.
