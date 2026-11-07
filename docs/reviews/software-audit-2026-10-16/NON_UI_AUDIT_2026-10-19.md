# Non-UI audit continuation — 19 October 2026

**Later 19 October continuation:** PR349 adds paid-basis Zakat expense reporting, original commodity quantities and explicit Mill rent/salary reference reconciliation; PR352 corrects the salary-advance and native-parent source holds found by the live review. See `ACCOUNTING_REPORTS_AND_REFERENCES_2026-10-19.md` for its exact validation/release and remaining gates; it supersedes the implementation-state wording below for C09/C12/R08.

This checkpoint incorporates Salman's financial B/F clarification and the already agreed annual Zakat scope. It supersedes earlier notes calling the annual form deferred. It is not a declaration that every workflow, financial classification, original document or stock balance has passed. The action-level coverage register remains authoritative.

## Implemented and checked in this continuation

PR347 separates historical financial balances from physical stock, adds the annual Zakat control detail and native navigation, repairs financial-report source reads, and scopes Local Sales responses. Release evidence will be recorded below after exact-main acceptance.

| Area | Actual change | Acceptance boundary |
|---|---|---|
| Historical bag bills | Financial-only form and API; no stock treatment or opening-stock input; positive/invalid stock payloads rejected for pre-July and July–September historical bills | Original bag counts, per-bag/per-kg calculations, original GST, recoverable GST and creditor balances retained; ordinary current stock purchase routes are unaffected |
| Existing bag opening | Exact existing creditor/GST allocation retains the original journal without a second posting | A separately posted stock amount is not copied into the historical invoice; legacy stock-bearing bill amendment is held for explicit source reconciliation |
| Completed contracts | Completed joins closed/cancelled in Daily commitment treatment; Milling specifications show a historical reference instead of a new production balance | No historical production/loading quantity is invented, and actual rice/bag stock sources remain unchanged; current contracts keep their open requirement |
| Annual Zakat | Accounts Reports and Directors links use the exact Zakat icon and all-company View scope; focused annual workspace | Control ledger shows prior signed carry, completed annual debit, original payment/refund Post IDs and payable/excess; screen, print and CSV share its figures |
| Zakat limits | Incomplete sections have no final annual control debit; payment ambiguity blocks the next year's carry but does not erase a completed annual assessment | This assessment control is not a new monetary journal. Canonical annual debit/credit and legacy P&L classification remain C09 |
| Financial report source | Shared file lock; missing, empty, truncated, scalar/list or invalid journal sources fail explicitly | Reports no longer turn an unavailable/damaged source into a successful zero balance. No balancing entry or source initialization is performed |
| Local Sales company access | Server filters pending rows by the selected authorised company and owning icon | A real-auth regression reproduced a BRM pending-row/turnover disclosure to a TTI-only caller before the fix |
| Local Sales group access | Group numbers are withheld unless every policy company is viewable, including preflight/error/posting-metadata responses | Original group calculation/posting policy is retained; restricted figures are labelled rather than displayed as zero. Production roles are unchanged |

## Financial B/F trace and physical-stock boundary

| B/F source | Financial destination | Physical result |
|---|---|---|
| External buyer opening | Customer/export receivable 1210 and the exact buyer/invoice currency | No new rice receipt, production, loading, revenue or COGS |
| Pakistan → TG opening | Pakistan intercompany receivable 1240 and matching TG payable 2500 with explicit source identity | No physical movement or external customer turnover created by the internal balance |
| Supplier/broker opening | Original party's configured control, including 2110 supplier / 2120 broker | No invented purchase arrival or quantity |
| Historical bag bill | 2140 supplier payable; recoverable original GST in 1230; opening clearing only for the new financial balance | Original invoice bag quantity is reference only; no 1340 stock is created by this financial path |
| Historical shipment service bill | Exact role/source-based creditor, payment allocation and original invoice | No bag or rice stock, and no duplicate paid expense when settling an opening liability |
| Mill rice/bag physical opening | Separate actual company, mill, product/packing and quantity facts still to be supplied by Salman | Nothing has been inserted or inferred from financial B/F |

Published foreign invoice balances can be registered in invoice currency pending a supported book valuation. Registration is not falsely labelled a converted journal; no historical exchange rate is invented. Existing booked openings use their exact source allocation/capacity. Genuine prior receipts, unloading and loading are retained rather than erased because an invoice is historical.

The actual carry-forward fixture posts/pays all seven supported expense categories in Pakistan and TG, checks exact opening links/settlement, rejects duplicates/overpayments and compares unchanged Export/physical-source bytes. Supplier opening settlement, privileged opening JV/native-bank linkage, actual bag inventory projection and 103 inventory-reconciliation assertions also passed locally in this continuation.

## Open findings — still require work or original evidence

| Gate | What remains | Why it cannot be marked passed |
|---|---|---|
| C09 monetary Zakat / P&L | Review the canonical annual journal mapping and the original opening schedule; correct paid-expense versus payable presentation consistently across statements | The earlier original-source trace found 1 July Dr3400 / Cr7210 852,747 and nine payments103,350, leaving credit749,397 at that trace. This is not a fresh certification of the current balance. Those originals have not been rewritten. The new annual control is not a claim that the negative P&L expense is fixed |
| C12 commodity quantities | Complete source-linked brand/product ordered, received, delivered and outstanding reporting | The existing financial report is still a journal activity filter; Daily commitments and physical ledgers do not certify this separate detailed report |
| R08 Mill rent/salary references | Canonical source matching, staff/rent identity, original accrual/payment and settlement linkage | Operational reference stores do not prove Accounts payment; do not auto-post them or manufacture a second payment |
| C24 stock ownership | Compare the saved Pohanch/unloading originals for PH00001/ABC123/Soda26002, PH00002/ABC345/Soda26001 and PH00003/TK123/Soda26002; resolve Indus versus TTI grouping of 119,500 kg | Salman's later physical opening figures are a separate input, not permission to reassign existing native arrivals |
| Actual recovery/cost and shipment margin | Positive native production/cost sources, complete shipment overheads and evidenced TG elimination | Earlier live source had zero production runs and all eight shipment contributions unavailable; estimates are not actual results |
| Historical documents | Native individual issued CI/PL indexing and full original-output acceptance; AMT13 HS/buyer-letter decision; preserved older packing label overlap | Dated archive packs are protected originals, not a certification of the current issued invoice. Do not replace issued originals just for layout |
| Recovery/migration/security acceptance | Independent full source-to-target dataset/control totals and rollback; oversized offline acceptance; formal security preflight | Ordinary file/MySQL/browser fixture passes are not formal migration or security clearance. Missing formal scan tooling remains recorded |
| Startup | Retain the earlier intermittent failure and compare fresh read-only diagnostics | A later successful run does not prove a root cause or erase the earlier failure |

These are audit findings and acceptance gates, not hidden waiting tasks. No overall 100% or perfect-state claim is made.

## Chrome close-out order

1. Open historical customer and bag B/F details; compare account/party ledger balances, original quantities, outstanding amounts and the absence of physical stock controls. Preserve all issued originals.
2. Open Annual Zakat through Accounts Reports and Directors; inspect each annual section, carry, yearly control debit, payment drill-down, print and CSV. Keep C09 monetary entry review separate and visible.
3. Compare the actual Pohanch/unloading source for C24 and distinguish those native movements from the separate physical opening schedule Salman will provide.
4. Review completed specifications as references and open an active instruction to confirm its required/produced/balance display remains appropriate.
5. Inspect Local Sales in the selected company, role-scoped access and original approval/receipt/cost paths; do not make a real test sale or payment.
6. Finish Sales Tax original multi-file output, WHT filtered print/CSV, positive supplier-scan output, and exact issued CI/PL versus archive comparison.
7. Finish asset/stockbroker/property, Bank Entry/finance/reconciliation and supplier-plan amendment/cancellation/output variants using real review or disposable positive fixtures as appropriate.
8. Finish Exports payment terms, FI/L/C failed upload/cancel, TG/direct/multi-GD outputs, and remaining changed-master acknowledgement variants.
9. Finish Milling local sales, used-bag outward, mixed Corn allocations, billed corrections and reprocessing positive paths; compare actual current source identities.
10. Finish role/action/master variants and Directors–Super Admin decision parity. Do not approve a real pending request solely as a test.

No live financial, physical opening, stock, approval, role, issued original or restore mutation is authorised as an audit test. Chrome being unavailable does not block the source and disposable acceptance work above; it does prevent calling this final user-session UI close-out complete.

## Release evidence

Feature head `39d162e8d794134373cca68105bbc54a596c2b95`, tree `98c639c8a831a6e759cb1606ed6087a702494c3a`, passed full Accounts `39210748300` (audit `117923340873`, inventory/browser `117923340599`) and Milling/Exports `39210748299` (audit `117923340523`). The inventory job explicitly passed the new completed-instruction and Local Sales UI cases, original carry-forward registration/settlement, actual guarded MySQL Daily and 138 MySQL inventory assertions, and the native Daily browser/CSV/print path.

PR347 merged as `f64dba4e574af4e1011187f82cfff9eaf78bfea8`; its exact tree matches the feature. Exact-main Accounts `39211102034` (audit `117924442354`, inventory/browser `117924442583`) and Milling/Exports `39211102005` (audit `117924442189`) both passed. Production deployment `39211396285` uploaded exact `f64dba4` in its first attempt (job `117925379175`) but its public login health probe returned HTTP403 eight times and failed. No health assertion was weakened. A normal failed-job retry succeeded (job `117926689072`), confirmed the exact release and healthy login, changedUserCount0 and journal-ID migration changeCount0. The 403 cause is unproven and the failed attempt remains part of the evidence.

Startup `39211896883` / job `117926925276` passed: usable Accounts671ms after sign-in; Accounts1132ms, Masters956ms, Milling860ms and Exports716ms. The earlier intermittent failure and first-attempt403 are retained. Authenticated read-only live smoke `39211896763` / job `117926924703` passed **22/22 in 2.2 minutes**, including the new Annual Zakat native form/read-only control, financial-only historical bag controls, invoice kg/per-bag calculations and reconciled service groups. It compared10PKR banks and50active account ledgers across3companies with0concurrent-revision skips and0financial writes. The annual check also recorded0assessment/stock writes. Maize three routes, blank agreed rates and review delivery passed twice without Save.

Production still has8shipment rows with0source holds,5verified historical openings and8unavailable contributions. No original sales/cost or lifetime margin is invented. Recovery still has0production source runs. The run listed35dated archive packs and sampled200/PDF; this does not certify all35pack contents or individual issued CI/PL linkage. The September OTHER WHT filter reconciled its two source/screen/CSV/print rows, credit2414.06, with0financial writes; other category/reversal variants remain separate acceptance.

The user-session Mac Chrome audit remains for when that session is available. These hosted read-only checks do not replace the original source/ownership and role/output close-out listed above. No live financial/opening/stock/approval/role/original-document/restore test mutation was performed.
