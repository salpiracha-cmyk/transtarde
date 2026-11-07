# Carry-forward shipments — 1 July 2026 opening

Super Admin and authorised Directors create historical shipment information under Exports → Carry-forward Shipments. Accounts opens the same register from Export Receipts & Payments (TG: Supplier Payments). Existing entity and bill-icon permissions apply.

1. Save the shipment information. This creates a draft only, with no monetary or inventory posting.
2. Review the full invoice values, pre-July settlements, carrying rates, FI/GD references and bills. TG Pack records both the Pakistan → TG invoice and the separate TG → customer invoice. The Pakistan receivable and TG payable must agree in their transaction currency.
3. Choose **Still to bring forward** for a genuinely missing opening amount, or **Already in opening balances** and select its exact opening Post ID / line. Existing opening allocations cannot exceed the original native balance. Bringing forward posts missing balances against 3400; it never reposts export revenue, rice purchases, stock movements or historical cost of sales.
4. Use **Bring forward & make available to Accounts**. Financial opening information is then locked. Existing unposted operational export candidates are reused when the references match, rather than creating a second receivable/payable.
5. Enter bills received after July, with the company bearing the cost. **Save bill information** does not post. **Post bill** registers the expense and payable. **Pay this bill** opens the existing Supplier Payment form with the company, supplier and bill selected. Pakistan settles the recorded PKR liability; TG uses its native-currency liability and existing AED valuation/FX flow.

Rice is reference-only: record the existing Bank Payment Post IDs and supplier/payment details. No rice bill or rice payment is created by this register.

FI/GD references and supporting PDF/PNG/JPEG files remain attached to the shipment. Document reads are authenticated and scoped to the document company. The Accounts summary shows actual Pakistan credit-advice realization dates; a 100% pre-July advance retains its original receipt date for export-performance classification. Invoice/shipment dates are retained independently of reporting dates.

The register and links are retained permanently. Retiring the temporary entry icon must never delete shipment, opening, bill, payment or audit records.

Validation: `tests/accounts-v1/carry_forward_http_test.py` runs actual endpoints in disposable books. It posts and pays all seven non-rice bill categories in Pakistan and TG; checks exact opening links, retries, overpayment rejection, permission/entity restrictions, direct/TG credit-advice sources and unchanged rice/stock. With `TT_QA_BROWSER=1`, it also exercises the one-page form and Supplier Payment deep link in Chromium.
