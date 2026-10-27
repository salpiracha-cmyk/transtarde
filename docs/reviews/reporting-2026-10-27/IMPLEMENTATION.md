# Reporting completion — 27 October 2026

Owner-approved scope: implement and deploy after verification. Export document wording/layout is frozen. Reports must not post, repair or invent business records.

- Shipment statement: actual contract price and quantity; Pakistan/TG price bridge; original receipt exchange-rate slices and proportionate deductions; USD, PKR total and PKR/MT columns.
- Include cost of goods toggle: checked shows goods cost and profit/loss; unchecked shows balance before goods cost. Trace actual production/purchase costs or explicitly labelled saved costing assumptions. Distinguish milling and processing; show recovery, by-product credit, cost/kg and cost/MT.
- ARP morning overview: group totals first, company/detail breakdowns; banks, SODA purchased/received/balance, export contracted/loaded/pending, current loadings and final-document importer receivables net of allocations.
- Reporting entry points in Accounts and Directors. Output first, deliberate refresh, print/CSV; no scheduled delivery requested.
- Review future-business costing, milling source/recovery/costs, arrivals, Pohanch and vouchers. Preserve final export outputs. Confirm internal TTI/BRM/TG balances excluded from group cash flow.

## Work status

Implementation in progress. Base: 44a741be7f1b6f319dc6e603c25dad6d69ddcd66. No deployment or completion is claimed by this checkpoint.

Existing sources: group_daily_core, customer_receivables_core, cashflow_party_core, directors_trading_core, production_costing_core, management_costing_core, shipment_profitability_core, fi_credit_advice_link and receipt_invoice_links. Existing source checks and permissions must remain intact.
