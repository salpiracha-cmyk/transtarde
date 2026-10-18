# Maize / Corn / Makai labour

Owner flow agreed 18-10-2026. Exact rates remain unconfirmed. The mentioned
Rs 6,000–6,500/container is an estimate and is not a default or agreement.

| Route | Labour charges | Stock treatment |
| --- | --- | --- |
| Direct truck to container | Loaded containers × agreed container rate | Record actual receipt and actual container dispatch for the same movement; equal net quantities leave no warehouse balance. |
| Warehouse to bulk container | Incoming bags unloaded × unloading rate; loaded containers × separate loading rate | Actual receipt enters warehouse stock; actual dispatch reduces it. |
| Warehouse to packed container | Incoming bags unloaded × unloading rate; paltai into 50 kg and/or 25 kg packing × separately agreed basis/rate; loaded containers × separate loading rate | Actual receipt, packing and dispatch remain the operational stock records. Incoming and repacked bag counts may differ. |

Milling → Labour Bill → Commodity: Maize opens the route form. Multiple actual
work references and stages can share a bill period; enter only work performed in
that period. Warehouse unloading can be billed before later packing/loading.
Every line retains route, work date and truck/receipt/shipment/container reference.
These are entered work references, not an automatic quantity match to stock IDs.

Paltai basis is explicitly selected; the agreement did not establish its rate or
whether it is per bag, weight or task. Extra charges are additions only. Blank
rates may remain in the open working form but cannot create a saved payable
source. Saving requires all rates and explicit contractor-rate confirmation.
No new default rate agreement is created; the Rice rate master is untouched.

Explicit bill Save uses the existing acknowledged Mill source save/retry flow.
Accounts reviews and posts the saved source separately through the existing
permission-controlled labour payable workflow. Stock is governed by the actual
operational records; a labour bill never creates another receipt or dispatch.
Maize work cannot use Rice autofill, and Maize bills are excluded from Rice
conversion-cost allocations and the Rice production unit-cost numerator.

Saved bill print includes the route, work date and reference. Audited source
amendments preserve work metadata, immutable originals and payment/cost holds.
Rates entered for a bill remain its own snapshot; they do not change old bills.

Validation: native-form DOM tests cover the three routes, blank rates, explicit
confirmation, independent incoming/paltai quantities, extras, draft preservation,
printing references, and zero stock/autosave writes. PHP tests cover route/basis
validation, positive agreed rates, whole bag/container counts, line totals,
shared-writer protection and idempotent Accounts posting. Existing corn stock
test also covers equal direct receipt/dispatch with zero warehouse balance.
