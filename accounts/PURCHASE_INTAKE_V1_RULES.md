# Transtrade V1 — Rice & Corn Purchase Intake

## One-entry rule
The old Mill and Accounts systems were separate, so Mill entered an arrival and Accounts entered the same arrival again. Transtrade V1 must keep the Accounts checking control but remove that duplicate entry.

The flow is:

`Soda created once → Mill Arrival / Pohanch entered once → Accounts receives the same arrival → Accounts verifies and posts the final purchase bill.`

Accounts must not retype the Truck, Soda, Broker / Party, commodity, variety, arrival date, Pohanch reference, payable weight, purchase rate or Mill quality / KAT data merely to create the bill. Accounts reviews the operational data and completes only the financial fields that belong to Accounts.

## Rice and Corn / Maize
Rice and Corn / Maize use the same integrated purchase framework.

Each receipt carries an explicit commodity identity. A purchase bill:
- belongs to one company (TTI or BRM);
- belongs to one Soda;
- belongs to one commodity;
- belongs to one Broker / Payee;
- may contain multiple Pohanch / trucks from that Soda.

Rice and Corn / Maize receipts cannot be mixed into the same purchase bill.

## Accounts review
Accounts can locate an arrival by Commodity, Broker, Truck Number, Soda Number, Pohanch Number, Bill Number or date.

The review screen shows the original Mill arrival details together with current billing status. Accounts then confirms the final commodity value, permitted bill adjustments, brokerage / withholding treatment, credit days and bill reference before posting.

## Posting
The Mill receipt creates the provisional stock / GRNI position. The verified purchase bill clears GRNI and posts the final supplier / broker payable and any authorized inventory-cost difference. Physical stock quantity is not entered again by Accounts.

## History
The Soda / purchase history must retain all trucks, unloading dates, Pohanch references, payable weights, rates, KAT / deductions, bill references, final values, due dates and payment status so Accounts can review the complete Soda without searching another system.
