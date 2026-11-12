# Stock and billing weight — decisions after deployment

Implemented in this change:
- Saved Karachi cargo weighbridge weight drives arrivals and dispatch stock, including bags. Vehicle/container tare is excluded when the source identifies a vehicle-gross reading.
- Existing Emroz loadings use their saved gross weights on report/availability reads. No container entries, supplier bills or posted journals are rewritten.
- Local and fellow-exporter SODAs offer Gross / Net billing. New UI defaults to Gross; existing agreement billing remains unchanged. Net means cargo gross less empty bag tare, not vehicle weight.
- Production outputs, Paltai, returns, openings and physical-NIL records remain recorded internal movements. They do not invent weighbridge measurements.
- Historical records without a gross measurement retain their recorded weight; the report explains this limitation.

Discuss next:
1. Existing HKM/Emroz financial agreement: confirm its gross billing amendment and which unposted bills/settlements should be recalculated. Current authorization changes its historical physical stock-out only.
2. Other existing local/fellow-exporter agreements: classify each as Gross or Net before changing historical financial amounts. Review already posted bills separately.
3. HKM bags and other deductions/additions: confirm settlement heads, evidence and whether the adjustment belongs in settlement review or payment entry; prevent duplicate bag expense/deduction.
4. Estimated Ghati: 0.25% of inward quantity, Directors only. Confirm the reporting period and reconcile permanent physical-NIL loss within that estimate without double counting. No Ghati changes in this release.
5. Historical missing gross weights: identify records requiring original weighbridge slips; do not fabricate bag tare or change opening stocks.
6. Existing physical-NIL confirmations: review any balances affected by the new gross stock basis before treating them as new shortages. No NIL reversal or new loss is posted automatically.
7. Commercial contract completion, export documents and recovery/costing conventions: these are separate from physical stock. Review any gross/net commitment and document differences before extending this policy to them.

Validation: isolated server/API and browser-DOM fixtures; no real business test entries. Chrome is unavailable for this release. GitHub QA and deployment health checks are required before reporting the release live.
