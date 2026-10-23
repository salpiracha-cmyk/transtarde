# Directors exporter agreements and costings

Owner-approved implementation, 23 October 2026. The workspace is
`/directors/exporter-trading.php`. Directors has two tiles: Exporter Sales &
Purchases and Rice & Maize Costing. The same saved records are used for Accounts
journals, Exports contributions and Group Daily; no duplicate register replaces
the existing forward bookings.

## Commercial arrangements

- PURCHASE means the fellow exporter supplies quantities for a shipment in our
  name; the settlement is payable. SALE means our contribution travels in the
  fellow exporter's name; its settlement is receivable.
- FIXED uses agreed price per MT. A fixed FOB purchase does not deduct our normal
  FOB expenses or freight again. CNF/CIF purchases deduct the applicable freight
  and insurance paid on the exporter's behalf. Separately agreed exporter-caused
  deductions/additions carry a reason and amendment history.
- SETTLEMENT uses end-buyer contract value and actual invoice quantity share.
  It does not subtract a fellow exporter's own goods cost. TG uses the original
  end-buyer contract price rather than the Pakistan Customs price. Posted advice
  realization rates, withholding/bank deductions and correspondent charges are
  allocated to their invoice, then to the contributor's quantity share.
- FOB/CNF/CIF control freight and insurance applicability. Before final actual
  review, forecasts retain the allowance, explicitly separate from posted money.
  A quantity settlement payable cannot be posted with incomplete invoice advice.
- Invoice allocation is capped at commercial invoice quantity and agreement
  quantity. Exports staff use `/exporter-loading.php`, linked from Loading
  Instructions. That scope returns contribution identities/quantities, without
  Directors' price, costing, bank or settlement data.

## Stock, books and cash flow

External goods never create physical inventory entries. Our actual dispatch
remains in the existing Mill loading workflow. Group Daily reconciles own native
loading plus the external contribution to invoice quantity; incorrect native
classification is held for review rather than silently changing original stock.
Agreements can link a sales contract before its first lot exists. Remaining external coverage offsets uncovered sales commitments, never adds to
physical stock. Export COGS excludes external quantities. Allocation is blocked
when original invoice inventory cost is already posted until Accounts reconciles
that cost. No existing SODA, invoice or opening balance is automatically deleted.

Explicit settlement posts debit/credit party controls 1230/2140 against 4100/5100;
external goods do not use inventory accounts. Part/full PKR cash/bank settlements
clear that same control. Foreign-bank conversion remains in Accounts. Posted
balances are already in Group books. Only the additional unposted forecast is
added to expected cash flow, preventing replacement actuals from counting twice.

Posted supplier shipment sections supply actual logistics charges. Bags and
other shared posted expenses can be explicitly allocated from original sources,
within each source's remaining amount. These allocations do not post the bill
again. Amended/reversed source costs invalidate review. The history-weighted FOB
comparison and above-allowance warnings are visible on review/open/refresh; a committed logistics bill also derives a Director home cost-review alert. There
is no timed transaction refresh or automatic save.

## Costing rules

IRRI-6 and maize use **Salman's COSTING latest with makai(1).xlsx**. TRP's
**COSTING 2026.xlsx** supplies other varieties only. Source prices are historical
examples and remain editable. 100% broken has a separate blank process setup.
Cash, udhaar and financed-cash columns contain costs only. No target sale price,
profit, margin or Watak input is added to this workspace. Maize accepts 40kg
maunds. CSR, B2 CSR and B3 stay distinct; B1 remains where present in TRP's recipe.
Finished rice is the residual after saleable outputs and zero-value loss; all
recoveries must total 100%. Positive by-product yield needs its price.

Packing adjustments support 50kg, 25kg, other sizes and custom packs, in USD/PKR;
they adjust included packing rather than charging it twice. THC is separate.
CNF/CIF adds freight per container divided by payload; CIF adds insurance.
Bank financing is added only to cash costing, using annual rate/day basis and
period. Selecting a PKR facility integrates its actual posted principal daily,
including repayments, with an explicitly chosen shipment allocation percentage.
An entered principal can be used as a forecast instead.

Dated WHT/bank costing assumptions are append-only versions. Saved scenarios and
history keep the rates/result used then. A scenario can explicitly override the
dated costing assumption. Existing transaction-specific accounting/tax master
rules, tax nature and actual bank advice remain authoritative; changing an export
costing assumption never rewrites historical journals or unrelated tax categories.
The owner's 1.25% WHT planning value is not a legal-rate certification.

Exact stock product mappings use dated input market prices for Daily valuation;
missing prices remain unvalued. Market value replaces the corresponding commodity
inventory book value once only when all relevant quantities are known and priced.
An optional exact Mill variety publishes the saved estimate to existing production
management costing. This does not replace approved inventory cost rates.

## Validation and release

Dedicated tests cover source recipes, cash/udhaar/finance and daily repayments,
yield and missing-price refusals, dated rules, actual invoice allocation, fixed
FOB protection, TG advice rates, company/icon permissions, CSRF, stale versions,
idempotent saves, balanced settlement/partial-payment entries and immutable
operational sources. UI tests cover dependent fields, red errors, draft retention,
packing and recovery totals. Existing Group Daily/management endpoint/UI tests
remain in CI. Desktop/mobile browser checks exercise layout and overflow.

The new feature adds no live business agreements automatically. KAS's old SODA is
retained for the owner to review/migrate after verifying the new agreement and
linked postings, as requested. No live financial sample postings are used for QA.
