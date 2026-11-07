# Master Records consolidation proposal

17 October 2026. Proposal only; no live settings or historical records changed.
This is a confirmed initial inventory, not an exhaustive clearance of every field.

## One area, shared services

Use Super Admin → Master Records as the management entry point. Module links
should open the same authorized editor and source record, retaining the user's
module, company and action restrictions. Moving a link must not grant permission.
Operational forms remain in their modules; management settings use shared masters.

| Group | Records/settings | Current issue / required treatment |
|---|---|---|
| Companies and identity | Legal entities, registrations, banks, document identities, company currency | Reuse existing company/bank records and approval endpoints. Deactivation must preserve historical references. |
| Parties and customers | Suppliers, brokers, labour contractors, transporters, bag suppliers, freight/service providers, export customers | Reuse role-filtered central parties. Do not create another vendor list in Milling. Keep customer identity and company scope. |
| Purchase commodities | RAW/READY stages, commodities, varieties and KAT | Preserve purchase deduction rules separately from export specifications. Confirm dropdowns and saved records use the same stable IDs. |
| Export specifications | Export product profiles, HS rules, crop year, quality wording | Keep historical document snapshots. Correct new defaults only after resolving C07; do not rewrite issued invoices from current master values. |
| Mills and locations | Own and external mills, locations and company links | Use canonical mill service. Surface synchronization failures rather than treating local-only additions as successful. |
| Labour agreements | Contractor, company/mill, rate basis, threshold and dated rate versions | Current Mill `tt38labourrates` is one overwritten object. `rateByKey` does not select by work date. Add versioned effective periods and freeze the selected rate into bill lines. |
| Mill rent and processing agreements | Landlord/provider, location, rent cycle, reprocessing rate and effective periods | Existing `tt38costmaster`/`tt39rentmaster` overlap Accounts. Choose a shared agreement source; payment and liability remain canonical Accounts transactions. |
| Staff and recurring salary | Central `salary_staff`, effective dates, treatment and production-cost eligibility | Reuse existing 31-record central master. Legacy Mill salary/advance stores cannot substitute for Accounts salary journals. Preserve historical staff references and prepared months. |
| Brokerage, WHT and freight agreements | Product-based brokerage, withholding, vendor freight programmes and dates | Reuse existing Directors/Accounts services. Resolve C10 action/screen scope before exposing new menu links; preserve module-wide legacy grants without expanding granular rights. |
| Export and accounting policy | Realization charges, account mappings, settlement rules | Reserve unique account identities; C11 shows code 7200 has conflicting meanings. Review policy posting effects separately from the display repair. |
| Reference and document setup | Currencies, reference lists, document types, print quantities, templates and terms | Reuse existing permission-controlled masters. Check each generated output against its saved transaction snapshot. |

A new annual Zakat form is a future Accounts workflow, not a second party/master
list. Salman requested it later and a reminder is scheduled. Its exact balanced
entry and financial-year handling must be reviewed before implementation.

## Inputs with missing or misleading downstream treatment

| Input/control | Evidence | Classification / decision |
|---|---|---|
| Mill labour rate Effective From | `saveLabourRateMaster` stores one `effective`; `rateByKey` reads the current rates without a work-date argument. `generateLabourDraft` uses current rates for the chosen period. | Incomplete effective-date feature. The date is recorded but does not choose the applicable rate. Retain it; add dated versions and historical-period tests. Saved bills already snapshot line rates and must retain them. |
| Mill rent effective date and cycle | `saveMillCostMasters` writes both cost and rent stores; `costMaster` combines them. Local rent payments claim Paid by Accounts without a journal. | Duplicated agreement settings plus incomplete accounting handoff. Preserve values pending canonical agreement migration and reconciliation. |
| Reprocessing round-figure payments, date and reference | Local bill balance is reduced from its operational payment draft; R07/R08 show no demonstrated canonical bank/payment journal linkage. | Incomplete payment feature, not unused data. Preserve and reconcile against actual Accounts payments before migration; prevent double payment. |
| Mill manual Petty Cash Received amount/reference | `savePettyInline` adds `tt30petty` credit; `pettyBalanceValue` combines it with canonical Mill cash rows. | Competing cash input. Keep historical rows separate until matched to Accounts; Accounts is the authorized source of cash receipts. |
| Mill Users & Permissions | Operational `tt37users` differs from authenticated server user permissions. | Duplicate/incomplete authorization editor. Do not migrate its apparent grants into real permissions automatically. |
| Console Module Locks | Browser-local flags/password controls are presented as cross-module protection. | Incomplete protection feature. Do not infer server enforcement from the input or success message. |
| Export product Profile / use | Present in the central product record and rendered as Status (C04). | Reference/profile information with misleading presentation. Determine actual active-state storage before changing filtering; retain the field. |

No field in this table is approved for deletion. Remaining fields still need a
consumer trace: saved source → selection/validation/calculation → receiving
module → document/report. Absence from this initial table does not prove use.

## Acceptance before moving settings

For each group, inventory stable IDs, company and role filters, date versions,
transaction snapshots and existing consumers. Compare current and proposed
permissions for every staff member; keep the result identical. Test amendment,
deactivation, historical lookup and output in disposable fixtures. Reconcile
financial and stock references before importing any legacy local records. Keep a
mapping and rollback copy; do not delete old data merely because a new editor opens.
