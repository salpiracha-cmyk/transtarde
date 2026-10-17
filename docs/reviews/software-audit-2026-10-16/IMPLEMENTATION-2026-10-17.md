# Approved implementation and acceptance register — 17 October 2026

Owner has authorised implementation of the group reports and costing requirements, completion of the outstanding screen/action review and repairs. This register records implementation, not a clean-application or migration sign-off. Production figures still require real mill entry.

## Owner clarification: Maize labour

Maize labour bills and agreed rates are separate from Rice. The rate setup and charging basis are deferred for discussion with Salman. Never populate a Maize bill using a Rice agreement. Reminder created for 18 October 2026, 14:00 Asia/Karachi. Existing Rice rate identities/history remain intact.

## Implementation batches

| Area | Required behaviour | Current evidence |
|---|---|---|
| Corn purchase | Maund Soda (40 kg), Mill receipt, commission, supplier bill/payable/payment reuse canonical purchase flow | Source trace in progress; no new duplicate purchase ledger |
| Corn bulk loading | No production; zero bags; positive actual net per container; explicit weighbridge basis and packing/vehicle deductions | Working-branch implementation and controlled tests; not released |
| Corn bagged loading | Existing bagged shipment workflow; actual commodity net weight retained | Working-branch implementation; full browser scenario outstanding |
| Corn customs | Preliminary plan retained; explicit Mill loading completion switches revised customs and final outputs to actual net | Controlled bridge/document tests; all issued document/archive paths still under review |
| Rice/other commodity regression | Preserve existing bag/production behaviour and permissions | Focused bridge/export/container suites pass locally; full CI outstanding |
| Labour commodity scope | Rice and Maize volumes/rates never mix | Mixed-company/mill/commodity fixture passes; live verification outstanding |
| Arrival location | Receipt must belong to Soda location; never silently default an Indus Soda to TTI | New-save guard in working branch; historical source repair/cleanup not performed |
| Test arrivals | Remove only PH-00001/2/3 and verified test dependencies with backup and audit | Authorised; impact trace/cleanup outstanding |
| Zakat | Shared Accounts/Directors annual 1 Ramadan assessment, section completion, source detail, TTI payment expense and signed carry-forward | Approved; implementation outstanding |
| Cashflow | Shared group net trading position, actual/provisional/missing lines, no financial assets/shares, GST at end | Approved; implementation outstanding |
| Daily report | Date-selectable group report, contracts, Soda balances, stocks, receivables/payables, banks and raw LONG/SHORT | Approved; implementation outstanding |
| Costing / Watak | Editable versioned Rice/Corn scenarios, B2/B3/CSR, actual comparison, max affordable purchase rate | Existing actual recovery/cost engines located; management workbench outstanding |
| TG shipment P&L | Pakistan realization plus matched TG–Pakistan margin at linked advice FX, expenses once | Approved; implementation outstanding |
| Credit advice sales | Advice-date realized-sales reporting reconciled with existing recognition/receivable journals | Source traced; repair/report validation outstanding |
| Whole-app audit | Every screen/action traced to sources, accounting/stock and output, each marked passed/failed/blocked/not tested | Existing coverage register retained; not complete |

## Binding business calculations

- Whole group TTI/BRM/TG; legal company books remain separate. Eliminate intercompany double counting. Historical dates/FX and saved snapshots remain reproducible.
- Property entered value excluding instalments is the premium **on top of** amounts actually paid: PKR 7m premium + PKR 3m paid = PKR 10m assessed property value. Do not count the same property advance twice.
- Zakat includes group eligible net financial wealth, trading assets/properties, shares and gold from all three wives at entered valuations. Exclude personal/operating home, office, cars and motorcycles. Annual date is 1 Ramadan, Gregorian date explicitly confirmed. Final obligation and actual payment expense belong to TTI. Assessment and payment must not both expense the same amount. Carry over both underpayment and excess payment.
- Shared sections save only on explicit action. Optimistic version conflicts protect another user's changes and unfinished entry. Changes to report rows do not silently rewrite source journals.
- Cashflow includes valued trading stock/by-products, banks/cash, receivables and advances/excess paid less all incurred payables, estimated when unbilled. Missing actual invoices must not duplicate GRNI or existing bills. GST receivable appears last. Immediate cash is separately identified. Properties/shares excluded.
- Raw LONG/SHORT = physical usable raw on hand + undelivered purchases − required raw for outstanding sales after usable finished stock. Rice recovery is editable per spec; 25% silky indicative 78–80%, 5% indicative 58–62%. Corn requires no milling recovery. Never count receipts again as undelivered purchases.
- Effective realization FX = FX × (1 − 0.0125 − 0.001). WHT and bank deductions are identified separately. Freight realization receives the same treatment. Do not subtract 0.0135 from the numeric FX rate or deduct twice.
- Costing separates money currencies/units; waste affects yield without an invented sale credit. Rice output/by-product/waste proportions reconcile to 100%. Actual unavailable remains unavailable.
- TG internal shipment margin = (TG price − Pakistan price) × matched MT × original Pakistan credit-advice FX. Example USD 20 × 100 MT × 280 = PKR 560,000 internal margin, not another bank receipt. Partial receipts retain their individual FX; no duplicate group revenue or expenses.

## Release gate

No production data writes or migration sign-off have been made by this batch. Full hosted suites, deployment identity, read-only live smoke and actual Mac Chrome workflow/visual verification remain required. Discretionary fields/master removal still require Salman's decision. Deferred Maize labour rates must stay unconfigured rather than silently inherit Rice.
