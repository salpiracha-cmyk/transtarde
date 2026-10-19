# Transtrade migration readiness — 19 October 2026

**Decision: not ready for full-data migration or whole-audit sign-off.**

This replaces the 17 October readiness summary, whose implementation/deferred-Zakat wording was stale. Historical observations and failures remain in REVIEW.md and CONTINUATION.md. CURRENT_COVERAGE.csv records154 action checks:121 passed,20 partial,4 failed,6 not tested,2 blocked and1 intermittent. The33 open/partial action checks are not33 separate proven code defects.

## Current implemented scope

- Director now automatically receives all business modules, companies, locations, actions and Master permissions, below Super Admin. Its matrices are hidden; staff custom scopes and owner security/recovery controls remain protected. PR358/359 are live and accepted, including Tayyab's actual role display without a user Save.
- Financial customer/supplier/service/bag B/F posts to the appropriate original financial accounts and ledgers without creating physical rice or bags. Completed historical shipments do not become new production requirements. Salman's separate actual Mill physical opening schedule is still outstanding; no stock has been inferred from financial B/F.
- The annual Zakat form and yearly assessment-control debit already exist in Accounts/Directors. Verified payments/refunds drive paid expense reporting and the payable control. The canonical annual monetary counterpart and legacy opening Balance Sheet classification remain unresolved; do not ask Salman to explain the form again or describe it as deferred. Pooled friends' collections remain a separate distribution liability, not TTI income or own annual expense.
- Mill source-linked liabilities, controlled bill review, correction and supplier settlement have tested canonical routes. Operational rent/salary references can explicitly reconcile to existing payments without another posting. Original-source matching is still required; empty current references do not certify every historical payment.
- Source-linked commodity quantities, protected native stock ledgers, source-preserving unbilled Ex-Mill correction, native outside Corn loading/billing, acknowledged bag transfers and scoped company/mill views are implemented. These do not establish actual physical stock ownership or a completed billed cancellation workflow.
- Durable private home JV decision notices remain until the recipient closes X. Bank Receipt uses Received into bank / Received from. Screen/print/CSV consistency, source errors, opening amendments, date cutoffs, document layout and other repairs have individual release evidence in CONTINUATION.md.
- PR362 fixes native Brokerage WHT categories and generic Export/Savings/Services reversals without changing journal bytes. Full feature/main suites and production deployment passed for `6f44e5651b0c564e9016ff0db6e57a2533a962a2`; final protected read-only acceptance is recorded in WHT_SOURCE_REVIEW_2026-10-19.md. Its wider original tax/output action remains partial.

## Remaining gates

| Gate | What remains |
|---|---|
| C24 actual warehouse ownership | Original Pohanch/unloading locations for PH00001/ABC123/Soda26002, PH00002/ABC345/Soda26001 and PH00003/TK123/Soda26002; resolve119500kg Indus-versus-TTI grouping without reassignment by assumption. Financial B/F and future physical openings do not answer this existing-source discrepancy. |
| C09 monetary annual Zakat/opening classification | Review the canonical balanced annual entry and original legacy opening schedule. The annual control and paid-expense repair do not authorise a guessed equity/counterpart journal or historical reclassification. |
| Issued document/source decisions | AMT13 original HS/100%-broken description and ASAS Saudi relationship letter versus Gambia buyer; preserved ASAS62 uploaded packing label overlap. New generated layout acceptance does not permit replacing an issued original. |
| Original output completeness | Complete actual multi-file tax/issued CI/PL/archive, WHT original statement/category, supplier scans and TG/direct/L/C/multi-GD variants.35 archive listings and one verified downloaded pack do not certify every file. |
| Current stock, production and costing | Independently reconcile native ownership, positive production, recovery, overhead and actual shipment contributions. Earlier protected checks had0actual production and8unavailable contributions; disposable positives are not real results. Separately insert physical openings only from Salman's supplied facts. |
| Remaining action variants | Final user-session review and any unexercised master/downstream, asset/share/property, finance, payment-plan, amendment/cancellation, mixed allocation and approval variants. Do not approve a real pending request or post a hypothetical transaction for an audit. |
| Independent migration/recovery | Original-file restore/rollback rehearsal passed separately:705files/308JSON were hash-checked in a private disposable copy. Independent full source-to-target counts, balances, allocations, stock/attachments, configured MySQL/browser recovery, oversized offline acceptance and rollback still require an identified secure rehearsal dataset/target. No production restore is an audit test. |
| Formal security and startup | Mandatory formal scan preflight resource/helpers were unavailable; no formal scan started and functional QA does not confer clearance. Retain original intermittent login/renderer timeouts and failed attempts; a successful unchanged retry does not prove their root cause. |

## Evidence boundaries

Salman asked work to continue while closing the MacBook. Local Chrome is not assumed available. Source review, disposable tests and hosted protected read-only checks can continue; they do not replace the final original-source/user-session acceptance. Earlier connected Mac Chrome evidence remains dated evidence.

Each release's exact tree, feature/main QA, deployment, startup and read-only result belongs in its linked continuation report. A merged PR alone is not deployed acceptance. Ordinary fixture passes are not independent migration, formal security or exhaustive UI clearance. No production financial, stock, annual assessment, approval, source-link, original-document or restore test mutation is permitted. Annual Zakat is already implemented; no duplicate reminder or automation is needed.
