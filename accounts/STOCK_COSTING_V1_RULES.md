# Transtrade V1 — Shared Stock Costing Rules

## Simple rule
Mill / Exports record the physical movement and actual quantity. Accounts records the money value of that stock movement. Staff must not enter the same quantity again in Accounts.

## One stock-cost master
Local Sales and Export Sales use the same effective-dated stock-cost rates. A product cost saved once can be used by both workflows when the company, product and date match.

Existing older Local Sales cost rates remain readable for backward compatibility. New rates are saved in the shared stock-cost rate store.

## If cost is not known
Revenue/sale recognition is not given a fake stock cost. The item stays `Cost Waiting` until a valid cost source exists. Period close must show unresolved cost items.

## Pakistan export sales
For recognized TTI / Pakistan-to-TG export sales, the completed shipment supplies the actual shipped quantity. Accounts applies the approved stock cost per kg and posts the matching stock value out of Raw/Purchased Stock, Finished/Ready Rice, or By-product Stock as applicable.

The physical stock quantity is not reduced again by Accounts because the operational loading already reduced it.

## TG-linked customer sales
For a TG customer sale linked to the Pakistan intercompany purchase:
- if the TG intercompany purchase was already posted directly to Cost of Sales, the customer sale reuses that cost and creates no second cost journal;
- if the TG intercompany purchase was posted into TG inventory, the customer sale moves that exact AED carrying amount from TG inventory to Cost of Sales;
- if the TG intercompany purchase is still waiting for Accounts approval/classification, the TG customer sale shows `Cost Waiting`.

This prevents double costing and prevents the TG cost from being guessed from the customer selling price.

## Future production-cost engine
The current shared stock-cost rate is the controlled Accounts source until the full production-cost engine is completed. Later, the production-cost engine may feed these stock costs automatically from approved purchase, milling/conversion and other inventory costs without changing the Local Sales or Export Sales workflow.
