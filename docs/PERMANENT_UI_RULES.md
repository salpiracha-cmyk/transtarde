# Transtrade Permanent UI Rules

## Header / logout rule

This rule applies across Super Admin, Milling, Exports, Accounts, Directors, staff landing pages and all future Transtrade modules.

1. Logout must always be represented by the power/off icon in the top-right header area.
2. The logout control must be visually integrated into the page header/topbar. It must not float in a separate capsule, card, overlay or detached box above/beside the header.
3. Where the page uses a dark/navy header, the user/module identity and power icon sit inside that same dark header line and are vertically centered.
4. Preferred right-side order is: date/status where applicable, user/module identity, divider, power icon.
5. The power icon should be visually restrained: transparent/inherited header background, no large white button treatment, and only a subtle divider/hover treatment.
6. Logout must never appear as a normal button/form at the bottom of a page.
7. Bottom-right page space remains clean. Do not place Logout, Updates or notifications there.
8. Notifications/updates belong in the header or another intentional top-area control.
9. Future modules must follow this rule by default; do not reintroduce floating logout UI without explicit owner approval.

## Current implementation

- Super Admin already keeps logout inside its topbar.
- Module wrapper integrates the authenticated user identity and power icon into the existing Milling/Exports header instead of the former floating capsule.
- Staff landing page uses the same header-integrated pattern.
- Accounts and Directors must inherit this pattern when their live interfaces are connected.

## Manual save only — whole project

- Timed/background autosave and timed background refresh are prohibited in every module.
- Typing, selecting, changing a field, or waiting must never commit data or rebuild/clear an active form.
- Data is committed only by the screen's final explicit action, such as **Next**, **Save**, **Confirm**, **Issue**, **Post**, **Create**, **Complete**, or the equivalent action for that workflow.
- Shared cross-module synchronization may run as a consequence of that explicit committed action, but it must not independently save a draft or replace a form being edited.
- A page may load the latest shared state once when opened. Later remote changes require an intentional user refresh/reopen action; they must not interrupt active entry.

## Committed customer master corrections — Exports

- A saved customer/notify master amendment automatically updates the party details used by active shipments and generated documents. Exports may read committed customer masters on opening, returning to the page and every 30 seconds while visible.
- This reads customer masters only. It must not reload a workspace, replace the form objects, save unfinished entries or refresh transaction data. Reconcile the master fields separately in saved storage and the open form's memory.
- Completed, closed and cancelled shipments retain saved buyer/notify snapshots. Capture those details when closing a lot and before applying a master correction to existing closed records. Different lots under one contract may have different closure snapshots.
- Generate document packs at print time so a pack prepared before a master amendment does not print a stale address.

## KCCI Certificate of Origin output

- COO preview, PDF and physical-letterpad printing use the same KCCI sheet geometry: 8.4 × 11.1 inches (213.36 × 281.94 mm), with Actual size / 100% printing and no browser headers/footers. Other documents retain A4.
- Keep exporter, consignee, membership, transport, marks, packages, description and weights within their printed boxes and columns. Wrap and fit text without hiding or truncating it.
- Position the owner's name, Proprietor designation and company separately above their respective bottom lines. Leave the bottom date blank; retain the commercial invoice number and its date under Other Information.
- Carry the Commercial Invoice description and HS code; do not add a separate GOODS OF PAKISTAN ORIGIN statement in the description column.

## Final shipment documents and office-folder copies

- Keep one merged final-document table, without generated-set ticks or a printing lock tied to lot closure. Completion gates apply only to Mark Lot Complete.
- Commercial Invoice and Packing List come from the system's issued final versions, including letterhead, footer and signature. Do not require re-uploading them.
- Show one Certificate of Origin entry for the uploaded issued original. The draft remains available in its own COO workspace but is excluded from Final Output and folder copies.
- Phytosanitary and other certificate uploads must populate the corresponding completion records. Allow an optional editable issued document/certificate reference. API category slugs must fit the 64-character storage limit regardless of document title length.
- Place ORIGINAL / COPY labels in normal flow above the invoice-reference box for both invoice and packing pages.
- The office share must be mounted on the operator's computer. On that computer select the shipment root once and grant browser folder access. Save copies to Customer / SHIPMENT #<contract sequence> / LOT #<lot sequence>, reusing existing folders.
- Copy only committed data into generated PDFs; folder saving must not persist unfinished form changes. Include all attached uploads, deduplicated by file identity. Report failures and partial copies accurately; an unavailable office share must not discard the protected online documents.

- Use the same customer / shipment / lot hierarchy for every TTI, BRM and TG route. Within the lot save balanced, committed Customs Invoice, Customs Packing List, Phytosanitary Invoice and uploaded GD in **Custom documents**. Save the reviewed Pakistan → TG settlement pack in **TG docs** for TG shipments only; omit an empty optional relationship letter. Buyer final documents and other originals stay directly in the lot folder. Reuse these subfolders on later saves.

- GD original uploads show separate GD Number and GD Date columns, automatically populated from the lot’s saved Customs/B/L Draft references (including older B/L-only records). Support multiple GD rows. Typing does not commit; Upload / Save validates each pair, uploads the file, then saves the same references into Customs and B/L with the GD fingerprint. Never require manually formatted number/date text.

- Print KCCI membership beside the membership-number label, keep package text within an inset column, and align owner name, Proprietor designation and company above their respective lines. Use the COO-specific named print page so A4 scaling cannot shift these fields.

- The saved B/L Draft goods description is the authoritative wording for Commercial Invoice, final Packing List and COO, including TG final/internal outputs and L/C shipments. Do not append old Customs or contract specifications to an amended B/L description. B/L-generated container/package controls, totals and FI/GD/L/C references stay in the documents’ dedicated fields; retain a saved HS line without duplication. Before a B/L draft is saved, use the initial Customs/L/C wording. Customs documents keep their Customs description.
- Every rendered brand label uses **"UPPERCASE NAME" Brand**, with one set of quotes and one Brand suffix. Format output only; preserve raw brand identifiers for artwork, stock and packing matching.
