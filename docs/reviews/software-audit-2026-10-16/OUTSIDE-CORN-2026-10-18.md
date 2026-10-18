# Native outside-mill Corn route — 18 October 2026

The prior combined main `aa13422` cannot create an Ex-Mill Corn Soda: RAW forces
stock delivery, then the external location is rejected. Its outside-mill Export
instruction also omits Corn commodity/packing and stamps READY Rice identity.
The new actual-auth route test fails at the original Soda endpoint, and the new
bridge identity assertion fails independently on that original tree.

Accounts now offers the route for Corn while preserving RAW product identity.
The feed and native Milling matching keep that identity, its Corn KAT profile,
legal company, location and approved source link. Rice stage behavior remains.
Export-derived outside instructions carry commodity, packing type and loose-bulk
choice; those fields are allowed in the existing Export instruction save policy.

Native outside loading uses explicit net / packed-cargo / vehicle-gross bases,
with conditional packing and vehicle tare inputs. Loose bulk uses zero bags;
packed Corn and Rice still require positive bags. Both shared-save backends
check new/corrected Corn physical weights. Untouched historical physical readings
remain, and repeated acknowledged completion makes no further save.

A new Corn liability must reconcile with a committed Export loading allocation,
shipment, lot, legal company, product, outside location and packing. Native net
weight and deductions are rechecked on the server. Container count and quantity
ceilings apply across the instruction, plus the Soda's existing 5% weight ceiling.
Soda-number aliases resolve to the canonical ID. Unchanged acknowledged retries
retain the original journal and bytes; a materially changed posted Corn lifting
is held for Accounts correction instead of rewriting the original journal.
The pre-existing Rice amendment/source-authority review remains open.

Corn supplier billing combines the selected Ex-Mill container sources and uses
their saved net weights, then applies the approved Corn KAT and agreed rate unit.
The source weight is read-only in the bill form and rechecked on posting.
It cannot be replaced by an arbitrary typed Karachi weight. Original provisional
journals remain unchanged on billing. The existing buying-brokery rules remain;
a bulk shipment cannot invent bags to satisfy a per-bag broker profile.

Validation uses disposable stores only. The real authenticated API test covers
Soda creation/retry, Corn feed, issued-allocation/company/packing/weight denials,
two zero-bag container liabilities, byte-identical replay, blocked original-journal
amendment, third-container denial, shared physical save and completion, combined
bill, source-weight tampering and duplicate billing. Actual Accounts/Milling form
functions run in JSDOM, including explicit final Save, dependent fields, net/gross
packing deductions and Rice isolation. The actual module bridge verifies Corn's
outside-mill identity. Eighteen related local CI commands passed before final
small replay/completion checks; both focused tests passed after those changes.

This is a native route correction, not whole-application acceptance. Positive
production loading/billing, all mixed allocations and historical source authority,
immutable correction workflow, independent physical-stock reconciliation, all
management/intercompany reports, full migration and formal security gates remain
open. There is no connected local Mac Chrome inspection in this turn and no live
test stock, purchase bill, payment, approval or original document modification.
Release evidence is recorded in the continuation checkpoint after CI/deployment.
