# Historical opening balances in Shipment Contribution

The signed-in Chrome report showed five source holds, each referring to a
carry-forward Pakistan candidate and saying the sale was not recognized/posted.
The carry-forward source workflow deliberately creates or links opening
receivables against equity, with historical stock costs excluded. These are not
new revenue-recognition journals. An actual-auth regression reproduces the false
hold on original main7dece05 and passes after this change.

The report now verifies the saved published carry-forward record, exact candidate
pointer, company, contract/lot/source identity, original invoice currency/native
amount, accepted opening status and balanced active posted opening. New opening
journals must name the same historical source and reconcile the receivable/equity
amount. A shared existing opening requires its explicit original allocation link,
line/currency/native capacity and book amount. Separate historical sources may
legitimately link the same shared opening; they are not duplicate new sales.
Missing, corrupt, foreign, unbalanced, reversed or mismatched sources still hold.
Historical flags alone cannot hide a current sale or cost posting.

Verified historical openings carry a count and a clear screen/print/CSV label.
Their balances are never counted as new revenue or historical COGS. Historical
contribution remains unavailable, including a mixed row with current sale/cost
postings; the report does not invent a complete lifetime margin. Current posted
supplier-cost components remain visible. Ordinary sale/COGS/liability evidence
rules and entity/module permissions remain intact.

Seven related local report/receivable/workflow commands passed. Acceptance
includes actual registered and settled carry-forward sources, shared opening
allocations, corrupt-source variants, unchanged source bytes and matching
screen/print/CSV labels. The temporary old-code regression worktree was removed.
The existing full-suite commands run the expanded tests. Read-only live smoke now
counts historical classifications and checks the rendered label without writes.

No opening, receipt, journal, stock or historical source is repaired or reposted.
This is report classification, not independent verification of original commercial
figures, C24 stock ownership, R08 payment mappings or complete shipment costs.
The production classification count and remaining holds must be reviewed after
exact-main QA, deployment and live read-only checks.

Chrome was genuinely connected and signed in as Salman during this continuation.
After the holds were read, a separate extension panel blocked further control;
no browser or authentication bypass was attempted. Original carry-forward form
inspection and remaining original-source comparisons are therefore incomplete.
