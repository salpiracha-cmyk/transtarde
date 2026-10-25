# Chart and subsidiary classification repairs

The account setup screen used different posting-status and amendment-date rules
from financial reports. Native pending-bill Mill liabilities were missing from
its inventory/GRNI totals and Word chart. These read paths now use the shared
effective cancellation dates and posted/bill-pending predicate, with validated
dates and a current-day default cutoff for Setup. Reads do not rewrite books.

Native withholding credits and their deposits can carry category evidence
without the same saved subsidiary ID. Named tax balances and the corresponding
ledger filter now resolve missing Pakistan withholding IDs from the existing
source classifier. Explicit saved IDs, unknown categories and TG identities are
not inferred or changed. Payroll party lookup accepts its saved person field;
private asset-control lines remain anonymous.

Used subsidiary accounts cannot be moved across posting heads through a master
edit. Their posted descendants and historical balances remain attached to the
original head. Renaming, archiving and movement of unused accounts remain
available. Financial reclassification uses the original correction workflow.

New customer advances use 2510; 2160 remains a labelled legacy control.
Other third-party obligations use 2520, distinct from indentor payable 2170.
Income/final/fixed tax expense uses 7300, distinct from Donations 7200.
The old donation tax mapping is rejected for new credit-advice deductions rather
than silently migrating historical tax or donation entries. VAT and advance/
third-party parent groups are explicit. No production data migration or journal
is included in this release.

Validation covers native Mill/Word parity; withholding head/subsidiary/ledger
reconciliation; preserved explicit, foreign and unknown source identities;
canonical payroll party resolution; used-tree rejection with unchanged books;
and unchanged ordinary rename/archive/JV/payment/source contracts. Financial
source corrections and owner accounting decisions are tracked privately and
are not part of this software patch.
