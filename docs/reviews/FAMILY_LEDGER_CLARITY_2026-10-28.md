# Family ledger clarity — 28 October 2026

Owner clarification: Salman, Talha and Tayyab are the owner's sons; “Director” is their internal application role. Their requested statements are remuneration and home expenses, plus one separate ARP expense statement. This change does not infer statutory directorship or alter authentication roles.

## Implemented

- Canonical company-scoped `FAMILY|person|REMUNERATION`, `FAMILY|person|HOME` and `FAMILY|ARP|EXPENSE` read views. Suppress overlapping family payroll/combined/legacy account choices in ordinary lookup; retain legacy direct API requests and original account identities for compatibility.
- Remuneration uses the identified person's payable/receivable movements. Net internal transfers within those balances do not manufacture another payment. Show positive balances as payable to company and negative balances as company owes family member.
- Home statements include the respective Mrs's monthly home allocation and company-borne household expense activity, retaining paid-to narration. ARP uses identified expense activity and historical ARP account movements. Personal recoverable costs do not inflate company-borne home totals. Proven payroll Zakat accrual/payment components are excluded from personal remuneration; the existing Donations/Zakat reader remains authoritative.
- Explicit owner example: HOME-classified company payments to Abdul Samad Abdul Aziz with no household assigned default to SRP. Other purposes and explicit household assignments are retained. Historical home-classified examples are grouped in the read view, without altering journals. Unclassified examples require source review.
- Shared payment helper no longer replaces an already selected cash/card source with the default bank on mount. This independently reproduces the need to select cash again. Genuine bank cheque validation remains unchanged.
- Non-family review candidates are marked, not reclassified: similar ledger names, unassigned household expenditure, Zakat purposes outside the Zakat account, and zero-value posting lines. Reports rights are required for company-wide review results.

## Validation and limits

Real PHP fixtures reconcile remuneration, home and ARP statements, opening balances, payroll Zakat exclusion, one-sided nonzero movements and immutable original books. Native Chromium covers family name selection, suppression of the redundant recipient entry, clear amounts/direction, switching statements and print output, alongside existing bank/cash/Excel and responsive flows. Cash/card mount regression checks source identity, hidden bank fields, no cheque tracking and form validity without reselection. Accounts read-scope regression preserves company and workflow access controls.

No production financial migration or balance adjustment is included. This release improves existing/future statement projection and the explicit future HOME assignment, not the historical P&L account coding of ambiguous entries. An itemized production review/reclassification remains dependent on access to the actual signed-in books; Chrome currently reports missing originating browser context. Do not claim that all live entries have been reviewed, corrected, or matched.

The pending Bags PO layout work remains separate.
