# Reporting completion — 27 October 2026

Owner-approved scope: implement and deploy after verification. Export document wording/layout is frozen. Reports must not post, repair or invent business records.

- Shipment statement: actual contract price and quantity; Pakistan/TG price bridge; original receipt exchange-rate slices and proportionate deductions; USD, PKR total and PKR/MT columns.
- Include cost of goods toggle: checked shows goods cost and profit/loss; unchecked shows balance before goods cost. Trace actual production/purchase costs or explicitly labelled saved costing assumptions. Distinguish milling and processing; show recovery, by-product credit, cost/kg and cost/MT.
- ARP morning overview: group totals first, company/detail breakdowns; banks, SODA purchased/received/balance, export contracted/loaded/pending, current loadings and final-document importer receivables net of allocations.
- Reporting entry points in Accounts and Directors. Output first, deliberate refresh, print/CSV; no scheduled delivery requested.
- Review future-business costing, milling source/recovery/costs, arrivals, Pohanch and vouchers. Preserve final export outputs. Confirm internal TTI/BRM/TG balances excluded from group cash flow.

## Work status

Implementation and focused verification complete; GitHub CI/deployment pending at this checkpoint. Rebased on 501e991981624c5d1d0a744e2b6b6b2ebba0ab46, preserving the concurrent shipment B/F retirement and protected opening-stock workflow.

Validation: 40 focused regression suites passed (production/recovery and source costs, group/intercompany cash flow, receipt allocations, report permissions, costing, vouchers, and frozen Exports documents). The new report suite adds two-mode arithmetic, original multi-rate and separate-charge advice, TG deduction proportions, recovery/by-product costs, missing-source holds, ARP quantities/receivables, and immutable reads. Chromium verifies desktop/mobile, print/CSV basis, conditional fields, failed/stale requests, and GET-only report access. Local/server costing parity: 45 cases. The real-auth suite verifies exact group icon plus all-company rights, denied writes, and corrupt-source handling.

Data limits remain visible: report-date quantities use current saved contract versions; unrealised FX and selected saved recipes are explicitly provisional; unbilled estimates remain separate; original PKR-only expenses are not assigned an invented USD amount. Source holds suppress the numeric result. Reports never post, repair, or certify completeness of unallocated business records. Physical office-printer output has not been tested.

Existing sources: group_daily_core, customer_receivables_core, cashflow_party_core, directors_trading_core, production_costing_core, management_costing_core, shipment_profitability_core, fi_credit_advice_link and receipt_invoice_links. Existing source checks and permissions must remain intact.
