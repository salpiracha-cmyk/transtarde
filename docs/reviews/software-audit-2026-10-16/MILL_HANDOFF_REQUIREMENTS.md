# Mill liabilities and payment references — unresolved migration gate

This is a source-traced implementation boundary, not a completed feature or permission to post historical estimates.

## Current entry-to-output gaps

| Mill entry | Saved source | Actual downstream behavior | Required acceptance |
|---|---|---|---|
| Labour Bill | tt38labourbills | postAccountsLiability appends tt38accountsfeed; no canonical payable consumer found | Correct active contractor, company/mill, bill period and agreed tasks reach an Accounts review; acknowledged posting, liability, settlement and source drill-down |
| K-Electric Bill | tt38kebills | Same unconsumed feed; no payable/payment created | Actual provider identity, bill reference, received date, reading dates and amount retained; eligible cost period agrees with costing |
| Other processing | tt37processingexpenses | Operational costs are not proof of Accounts expense/liability | Distinguish paid canonical costs from estimates, with source and Post ID, avoiding duplicate inclusion |
| Rent payment reference | tt39rentpayments | Operational estimate uses reported amounts and legacy master; no Accounts settlement | Match existing rent master/accrual/payment before adding anything; preserve reference history |
| Salary advance reference | tt39salaryadvances | Separate from canonical Salary Master and posted advances | Match existing canonical staff/advance first; never manufacture a second payment |
| Reprocessing | tt38reprocessbills | Operational record/feed does not establish posted balance | Source job, agreed scope, actual quantities, party, stock and Accounts treatment must reconcile |

Actual Mac Chrome checks on17 October found no saved processing bills, rent references or salary reference rows in the selected TTI Rice Mills screen. This does not establish absence in every mill/company or authoritative Accounts store. Labour contractor choices were empty. AUTO-FILL WORK correctly refused to infer an agreement; no save was performed. The Salary Cost — Accounts launcher actually opened Operational Salary References — Unreconciled; its launcher wording remains misleading.

## Existing canonical routes to preserve

- `api/rent_salary_v2.php` and `api/salary_month_workflow.php` already own canonical salary/rent period and payment treatment. They must remain the authority instead of importing operational reported payments as new disbursements.
- `api/expenses_v1.php` owns utility payments, including Mill electricity reading periods and production-cost eligibility. A new unpaid bill workflow must distinguish accrual from payment; invoking a payment endpoint for a bill would falsely spend cash.
- `api/other_purchases.php` supports credit purchases but its current operating categories do not establish this Mill workflow. Its existence is not evidence that Mill records already arrive there.
- `api/payables_planning.php` projects commodity and supplier bills with settlements. A new source must be represented consistently in its payable projection and payment allocation; an isolated journal is insufficient.
- Existing chart identities include5200 Milling / Conversion Cost,2190 Labour Contractors Payable,2140 Supplier Payables & Expenses and6300 Rent & Occupancy. These are actual configured identities, not approval of a new posting policy. Retain existing production-cost rules and avoid counting an accrued source and its payment as two expenses.

## Required behavior before this gate can pass

1. Submit only through an explicit action, with an immutable source key including company, mill, source collection and original record ID. No read-triggered posts or historical bulk posting.
2. Server reads and validates the actual committed source and party master; does not trust client amount, legal entity, mill or chosen accounting account. Reject invalid/ambiguous identities, unverified dates and stale source fingerprints.
3. An idempotent Accounts review records source revision, actual reviewer and decision. Surface any Director-required approval through the same authoritative endpoint in Directors and Super Admin, preserving staff permissions.
4. A successful acknowledged post creates the balanced canonical liability, source index, audit and original Post ID link atomically under the Accounts lock. A lost response can be retried safely without duplicate posting. Do not claim posted merely because an operational feed row exists.
5. Payments use canonical bank/cash validation and allocations. Partial payment leaves the correct party/source balance. Reported operational references are reconciled to existing payments, not treated as proof of payment.
6. Amendment/cancellation preserves the original record and links its reversal/replacement; reject changes that conflict with settled amounts until an explicit correction workflow resolves the allocations. Read and print never rewrite the source.
7. Costing/reporting uses the same source identity and period and distinguishes actual posted, provisional, missing and unverified amounts. Each source is counted once.
8. Disposable acceptance covers correct and wrong company/mill/party, inactive party, duplicate requests, changed source, lost acknowledgement, partial/full settlement, approval/rejection, reversal after settlement, and matching on-screen/print/export figures. Real legacy figures and mappings require independent reconciliation afterward.

Until these conditions are implemented and verified, R07/R08 remain failed gates even if general QA passes and operational labels accurately say unverified.
