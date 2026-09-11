# Transtrade Accounts V1 — Supplier Payables / Payment Planning Rules

## Purpose
Supplier payment planning must reflect how Transtrade actually clears the rice/commodity market: announce payment up to a chosen due date, see the cumulative funds required, and then drill from date to broker to Soda to truck/Pohanch.

## Due-date basis
- Commodity payment terms originate from the Soda.
- Each truck/Pohanch retains its own unloading/receiving date.
- Due date = truck unloading date + approved Soda credit days.
- A bill containing multiple trucks therefore may contain multiple truck-level due dates.
- Credit days must ultimately prefill from the live Soda master. Until that master is fully connected, Accounts confirms credit days in the hidden Payment Terms disclosure on the commodity bill.

## Day-wise payment ladder
The normal Payables planning table shows:
- Due Date
- Due That Day
- Previous Eligible Unpaid
- Total Needed to Clear Through Date
- Held / Issue Amount

Accounts may enter Funds Available. Transtrade then shows:
- latest due date that can be fully cleared;
- funds remaining for the next due date;
- shortfall to fully clear through that next date.

Transtrade calculates and suggests; it never silently chooses which broker/truck to partially pay.

## Drill-down
Due Date → Broker → Soda → Truck/Pohanch → Bill / settlement history.

## Held / issue and unposted trucks
- A posted payable can be placed on Hold / Issue with a reason. It remains visible but is excluded from the amount Accounts is expected to clear.
- A received truck whose commodity bill is not yet posted remains visible under Received Trucks with Bill Not Posted. It is excluded from posted supplier-payable totals until the bill is verified.
- Nothing disappears simply because it is not yet eligible for payment.

## Supplier settlement
The main Payables screen remains uncluttered. Actual settlement is opened through Prepare Payment / Settlement.

### Normal Bank / Cash Payment
Accounts selects exact truck/Pohanch balances and may pay fully or partially. Transtrade posts the supplier/broker liability components against the selected company bank/cash source. No payment is auto-selected.

### Supplier / Broker Advance
A payment made before the supplier bill is available is recorded as Supplier / Broker Advance, not as a fake supplier-payment clearing entry.

If the advance is explicitly linked to a Soda or truck, retain that link. If it is only a general broker advance, do not guess which Soda should consume it.

### Apply Existing Advance
When the payable is later posted, Accounts can apply the available advance. Applying an advance is a clearing entry:
- Debit the actual supplier/broker liability being settled.
- Credit Supplier / Broker Advances.

It is not another bank/cash payment.

## Third-party direct payments
A direct third-party payment must never create a fictitious Transtrade bank movement.

Examples:
- customer who already owes Transtrade pays supplier: reduce supplier liability / create supplier advance and reduce that customer receivable;
- customer pays supplier before its sale is recognized: reduce supplier liability / create supplier advance and create Customer Advance / Unapplied Receipt;
- unrelated third party pays supplier on our behalf: reduce supplier liability / create supplier advance and create Due to Third Party / Settlement Payable;
- configured family/staff person pays on behalf: use that person's reimbursement payable;
- another legal group entity pays: use Intercompany, not ordinary Third Party Settlement.

## Shams / Bunty example
If Bunty pays Shams before Shams truck bills are posted, Transtrade records an available Shams supplier advance with the source shown as Bunty. Once Shams bills are posted:
- a Soda-specific advance may only be applied to that Soda;
- a general Shams advance remains unallocated until Accounts confirms where to apply it;
- after application, the Payables ladder shows only the remaining cash required.

The Shams drill-down must retain the original advance, payer, date, proof/reference, Soda/truck allocations, amount applied and remaining advance/balance.

## Audit controls
- Posted settlement entries are not silently edited.
- Payment and advance allocations retain broker, Soda, truck/Pohanch, bill and source references.
- Partial settlements remain traceable.
- Accounts is the posting authority for financial settlements.

