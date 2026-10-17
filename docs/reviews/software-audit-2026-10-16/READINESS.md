# Transtrade migration readiness — 17 October 2026, Karachi

**Decision: NOT READY for full-data migration. The exhaustive review is unfinished.**

**Later checkpoint:** See [STATUS-2026-10-17.md](STATUS-2026-10-17.md) for PR275–278, the pending bag-ledger repair and current connection/test limits. The batch counts and coverage register below predate that continuation.

Ten repair batches, PR264–273, are merged. Each final repair head passed both full
hosted QA suites. These results establish the scenarios exercised by those suites;
they do not establish that every screen/action, original figure or output is clean.
The original audit evidence remains in REVIEW.md; later work and exact run/commit
identities are in CONTINUATION.md. CURRENT_COVERAGE.csv is the current action register.

## Repairs delivered or awaiting final deployment confirmation

- Restore the Directors launcher; preserve existing/legacy/unrepresented permissions;
  show one master Status column and readable activity dates.
- Keep the chart's approved account names and show original bank identity in pending JV decisions.
- Replace unsupported browser-lock claims with local-note wording; expose the owner-only
  server administrative audit, separate browser notes, and paginate 50 rows while searching/exporting all matches.
- Stop operational saves on purchase-Soda/FI reads; await new-mill master acknowledgement;
  restore source-owned Edit and separate Print actions after shipment-bill posting.
- Preserve expense/vehicle filters on Run, order detail rows chronologically and open
  original postings from details without losing the filtered report.
- Enforce explicit Brokerage Master Create/Edit scope; retain pre-existing flat grants;
  reject invalid dates/numbers; expose read-only editors accurately.
- Replace legacy all-mill arrival-based cost arithmetic with the canonical actual-production
  report. Keep missing actual input/costs unverified.
- Fit phone TG amounts, contain Bank Finance tables/actions and keep freight information
  separate from real error alerts.
- Separate canonical Accounts mill cash from preserved unreconciled operational history;
  disable duplicate manual receipt creation and retain original source records.
- Describe old Mill bills/payment references truthfully as unverified. Scope labour work,
  require an applicable saved agreement, preserve drafts when historical rates are missing,
  exclude undated work from guessed periods, and correct next-day billing dates across time zones.

Production deployment/health and live checks are tracked individually in CONTINUATION.md;
a merged PR is not itself proof of deployment. No real payment, approval, cancellation,
master edit, staff-permission change or data import was deliberately performed for this review.

## Blocking work

| Blocker | What must be established before migration |
|---|---|
| Mill bills → Accounts, R07 | Acknowledged source-linked review and idempotent posting, correct company/mill/party/account, amendment/reversal and settlement. Existing operational estimates must not be posted en masse. |
| Rent / salary advances / reprocessing references, R08 | Match reported payments to actual canonical postings; preserve history and avoid a second payment. Determine a permission-preserving, scoped Mill view of Accounts records. |
| Rice stock ledger, R13 | Use canonical raw/finished/by-product movements with genuine period opening, production consumption/output and reversals. Compare to independent source quantities. |
| TG pending-payment / FI integration | Restore and verify the missing pending-Accounts-payment display; reconcile unlinked bank advices and exact allocation identities. Do not equate a manually recorded TG Unallocated FI with this integration. |
| Reports/output completeness, C12/C17 | Finish aggregate source drill-down, commodity quantities and relevant print/export parity; deduplicate selected tax-document files and review preparation-process versus lot records. |
| Approved document/source mismatches, C07/C14 | Owner review of AMT's saved Customs HS versus its broken-rice description, and the relationship letter naming ASAS/Saudi Arabia while the final AMT buyer is in Gambia. No silent rewriting of approved/issued wording. |
| Master/permission consolidation, R03/R10/C04 | Replace the ineffective separate Milling permission editor; resolve competing settings and distinguish actual active status from profile/reference wording. Preserve every historical record and existing grant. |
| Full action/data/restore acceptance | Complete the untested control/output variants, reconcile original opening/current balances, allocations, stock and attachments, and execute import/rollback in a named secure disposable target. |

The proposed central Master Records groups and preliminary field-purpose tracing are
in MASTER_RECORDS_PROPOSAL.md. That is a proposal, not an exhaustive field audit or
approval to remove fields.

## Evidence limits and deferred Zakat

Actual local Chrome (extension, Person 1) was used with the signed-in production app.
Hosted browser fixtures, local function/endpoint fixtures and live Mac checks are
identified separately. Tests that could not bind local sockets were not counted as
software failures. A known accounting_desk_test.js failure remains recorded because
its missing TG integration assertion is supported by source inspection.

There are no recorded actual Mill production runs in the inspected period. Forms
and calculations were exercised with controlled positive fixtures; real recovery
and final costing still need original production records. Negative/unassigned
opening balances are not corrected merely because their signs look unexpected.

The user deferred the annual Zakat form. Retain the requested policy for the later
feature: annual calculation creates the year's payable/debit entry, payments reduce
the payable, and the paying part is that year's expense. Do not silently reclassify
or repost the existing opening balance. The existing reminder is scheduled for
18 October 2026 at 14:00 Asia/Karachi; no duplicate reminder was created here.
