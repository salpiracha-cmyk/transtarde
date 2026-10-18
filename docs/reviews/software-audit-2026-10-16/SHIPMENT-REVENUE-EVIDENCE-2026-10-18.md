# Shipment revenue and stock-cost evidence — 18 October 2026

The actual authenticated report on original main6a58f70 reports Rs1,000 revenue,
Rs400 stock cost and Rs600 contribution for a source with no linked journals.
The expanded real-auth regression fails on that result before the repair. The
previous supplier-liability evidence check did not validate sale/COGS sources.

The read projection now verifies linked posting identity, legal entity, posted
status, source ownership, balanced nonnegative numeric lines and accepted
amounts. Sale revenue must agree with its recognition posting; COGS must identify
the sale and its accepted stock-cost amount. TG direct cost is accepted only
from its explicit same-source TG payable candidate and actual5100 posting.
Duplicate source/Post IDs cannot count twice. Amended/stale links, partial
reversals and active orphan costs require reconciliation. Fully reversed sale
and cost pairs stay excluded under the existing ledger visibility policy.

Unrecognized revenue or stock cost remains unavailable. Expense-only records
still show their known cost components, while total cost/contribution are held.
A valid sale awaiting stock cost no longer appears to have zero cost and full
profit. Screen, print and CSV preserve the unavailable values and source holds.
Posted source records, historical journals, source amounts, permissions and
posting/cancellation workflows are not changed or repaired by a report read.
The report remains provisional: this does not certify all shipment overheads,
current original business mappings or intercompany elimination.

The real-auth disposable endpoint regression verifies valid sale/COGS and TG
direct costs; missing, foreign, draft, mismatched, duplicate and amended links;
partial/full reversals; unknown cost; module/company denial; corrupt-source
failure; and byte-identical Accounts data on reads. Actual report UI tests cover
unknowns/source holds in screen, print and CSV. Seven related local commands
passed. Full QA and exact deployment/live evidence are recorded in the next
continuation checkpoint. Expanded read-only live smoke checks all3companies
and the actual rendered held/unavailable state without financial actions.

Current Sales Tax source/endpoint/output tests also pass: canonical lots and
identity-based duplicate download handling are already repaired. Their old C17
duplicate diagnosis is historical. Generated issued CI/PL archive integration
and original document/source contradictions remain separate open gates; no
issued file or office archive has been rewritten.
