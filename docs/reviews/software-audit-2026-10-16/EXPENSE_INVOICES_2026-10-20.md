# Original expense invoices and later settlement

TTI/BRM: Accounts → Expenses → Pay Expense → Other Expense or Utilities → Record invoice — pay later. Enter the original supplier, invoice number/date, due date and bill month. General invoices retain their categorized expense rows; utility invoices retain their saved provider/location and meter period. TG keeps its native supplier-bill route.

The invoice records the full expense and supplier payable once. Expense invoices & balances opens its linked Party Payments draft, where the user explicitly chooses partial payment or agreed settlement. Round amounts leave the remainder payable unless the user explicitly records a discount. Bank/cash fields are hidden and disabled during invoice capture.

The register filters current balances by supplier, invoice number, bill month, invoice date range, due date, expense head and payment status. CSV and print use the same filtered rows. These invoice-date filters do not claim to reconstruct historical balances. Issued cheques show their actual net payment separately from any agreed discount and remain separate from cleared payments.

Unpaid correction/cancellation requires the existing Expenses Edit and company rights, current invoice journal identity, and a recorded reason. Corrections preserve the source and public Post ID using reversals and replacements. Linked active payments hold invoice correction/cancellation until reversed through their original workflow. Raw linked-journal edits are held. Original journals and audit records remain.

Mill K-Electric invoices appear in Sales Tax & Refund by bill month with their invoice identity and exact later payment allocation/bank date/reference. The saved original scan is still required; a captured invoice is not a replacement scan. Production costs use the meter period and are not charged again on payment. Utility reminders distinguish unpaid, partial, issued and cleared states.

Validation uses disposable authenticated books and real browser fixtures: capture/preview/retry, duplicate/stale/company/permission rejection, unpaid correction and cancellation, exact partial/final amounts without a second expense, held raw edits and paid sources, utility tax/bank linkage, production-cost deduplication, cheque-aware reminders, saved-field restoration, filtered CSV round-trip, and opening the original supplier payment draft. Existing expense/card/personal-recovery and staff payment amendment tests also pass.

No production financial test entries or fabricated original documents were created. Source/document/device qualifications in the main audit remain separate from this implementation.

Manual utility consumer/account references are retained in the source and journal and shown in the monthly filing row. A saved utility uses its authoritative saved consumer reference. No historical missing reference is guessed or backfilled.
