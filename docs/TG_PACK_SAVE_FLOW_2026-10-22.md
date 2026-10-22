# TG pack save and print — 22 October 2026

The supplied Jazib screenshot shows both a rejected cached shared record and a complete-pack validation that marks the relationship date while actually requiring the bank covering date. Connected Chrome reproduced complete-pack rejection from the Relationship Letter tab without changing any field or saving a document.

TG pack opening now prepares the same bank defaults and dispatch date as opening its Bank Covering Letter tab. Existing dates and bank selections remain intact. Complete-pack validation opens the document with the missing field and displays red, document-specific feedback. A user-cleared bank date stays blank for correction; the relationship date is not used to satisfy it. Typing and tab changes remain local. Printing still waits for server acknowledgement and uses the reviewed snapshot.

Actor-scoped shared saves now discard unchanged echoes of hidden server records or fields from the submitted write intent. The original hidden facts are preserved exactly once, and remain excluded from the response. Altered hidden facts, duplicate identities, unauthorized new records, company/location changes and actual permission violations still reject the operation. This shared correction applies to future document saves without broadening Jazib or other staff permissions.

Regression tests fail the previous implementation. Actual authenticated controller fixtures cover stale echoes in a restricted Mill list and nested Exports collections, preserved server originals, scoped acknowledgements, duplicate denial and forged hidden changes. TG preview tests cover complete printing without visiting every tab, historical dates, the correct validation tab, clear-and-retry, red errors, draft preservation and failed-save print suppression. Full release checks and connected Chrome acceptance are required before claiming deployment. No uploaded original is rewritten by this patch.

## Live save follow-up

Chrome Save exposed a second rejection after the root document commit. The refreshed history confirms the document audit was saved, but an outgoing physical-source update failed. Shared list projection compared the TG sales entity with the TTI/BRM entity carried by its Pakistan loading source. The physical source disappeared even from Super Admin views, and its derived refresh then appeared to be a hidden-record change.

The physical loading collections now accept that entity difference only when the source names an existing shipment in the same TG contract and its entity matches that lot’s Pakistan exporter. Staff still need the physical company/workflow/location permissions. Other entity mismatches and unproven shipment references remain blocked. Tests cover Staff, Director and Super Admin read/update, both Pakistan exporters and denied entity/shipment/contract forgeries. The previously deployed source fails the new regression. Shared errors now name the failing linked workflow.
