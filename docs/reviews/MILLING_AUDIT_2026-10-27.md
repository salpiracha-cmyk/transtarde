# Milling audit — 27 October 2026

Baseline: `61012face220c8f88b24f9091ade14bce5f86b85` (current main / PR #605). Existing work from Software QA Planning was inspected before changes. The connected MacBook Chrome session worked and its tabs were enumerated. Production was inspected through authenticated UI; all save/failure/retry transactions ran in disposable application copies. No test financial or stock records were added to production.

## Confirmed defects corrected

- Arrival/Pohanch, Used Bags, operational petty expenses, Local Sales and legacy exporter actions could clear or announce success while only staging a browser collection. Final actions now commit one record against the latest server version, retain failed entries, reject stale amendments and recover uncertain acknowledgements with the same identity. Accounts bridges wait for the confirmed source.
- Embedded Local Sales did not expose its state to the existing Accounts hooks. Hooks now install; payment and loading identities are retained through retry. Its Back action also referenced an element after removing it. Rendering no longer stages saves.
- PALTAI staged stock and labour separately and offered invented source identities. It now uses saved physical stock; server validation enforces scope, conservation and availability. One immutable movement and its labour source are committed together, including lost-acknowledgement recovery.
- Ready-stock opening rows were displayed under a prefixed brand instead of their saved stock identity.
- Date defaults converted unrelated fallback bag receipt checkboxes into date inputs.
- The unified loading report used a purchase-product label where a uniquely linked loading instruction provided the actual brand.
- The active export gate-pass override labelled net commodity weight as weighbridge weight. It now prints separate weighbridge, net and packing weights and restores a meaningful document filename.
- Arrival, Pohanch, labour/reprocessing bills, Rice Ledger and Directors dispatch print dates now use the existing DD-MM-YYYY display formatter; storage/filter values stay ISO.
- The narrow Milling header overflowed and clipped its logout control.
- The previously unresolved Director bag-decision test omitted the actual duplicate-truck approval dependency; the fixture now loads it.

## Coverage matrix

“Live” means read-only inspection of actual available records/empty states. “Fixture” means isolated browser, PHP/HTTP or JavaScript coverage, never a production test transaction.

| Panel / path | Verified coverage |
|---|---|
| Mill selection / home | Live navigation and scopes; desktop/mobile layout; fixture reprocessing selection |
| Arrival List | Live form/date/list; fixture create, duplicate request, failure/retry; server one-time approval and linked-history controls |
| Arrival / Pohanch | Live selectors/search; native fixture purchase feed, save/failure/retry, source identity and print; withdrawal/dependency tests |
| Exports Specifications | Live issued historical instructions and completed status; identity/authority regressions |
| Production | Live actual-input, output/recovery and allocation form; fixture actual production save and lost acknowledgement, bag allocation and recovery/cost tests |
| PALTAI | Native fixture source availability, balanced transfer, scoped stock, atomic labour, replay and immutable history |
| New Export Bags | Live original PO/line, receipt and transfer forms; fixture acceptance/quarantine, receipt, return, transfer, supplier-bill and approval tests |
| Used Bags | Live balances/forms; native inward/outward failure/retry/reload; original-source scope tests |
| Local party sales | Live new-form/empty state; native fixture Soda, payment, loading, gate pass, close/reload and failure retention; bank/company bridge checks |
| Sale to exporter | Live agreement selection/empty state; native legacy sale, loading, close/reload; Directors agreement endpoint/UI tests |
| Ex-Mill | Live completed AL-Harmain lot and its ten containers; unified report includes the six Indus containers; correction, instruction, identity and scope tests |
| Export Loading | Live instruction states; partial-save gate lock, ISO container validation, completion and failed-save tests |
| Petty Cash | Live Accounts-posted balance, operational separation, three expense forms and read-only receipts/ledger; native fixture expense save |
| Labour / Mill Expenses | All six task forms live: labour, KE, rent, salary, other processing, production cost. Native fixture source save/amend/replacement, Accounts liability and partial/full payment handoff |
| Reprocessing bill | Disposable configured mill form, dates/estimates/history and print; source save/permission tests |
| Stock | Live opening identities and cancelled Physically Nil confirmation; fixture stock calculation, review, permissions and reconciliation |
| Reports | All six live: Rice Ledger, Production, Recovery, Export Bags, Used Bags, Unified Loading. Date/scope/filter/source/CSV/print regressions; live September loading totals |
| Settings / Masters | Historical local panel inspected in fixture; active header M opens authenticated Console Master Records |
| Users / Permissions | Live disabled historical drafts and actual Console route; staff/entity/role scope tests |
| Directors / Super Admin | Live matching approval routes and empty queues; shared request endpoint, actor and permission tests |
| Accounts | Live Bill Review and Rent/Salary reference routes; disposable liability, source amendment and payment integration |
| PR #605 reports | Live Directors links open ARP morning report and Shipment P&L; missing inputs remain explicit review items. Neither report was rebuilt |
| Exports integration | Live Console link, 16 Milling updates and completed shipment source history; isolated loading/correction/handoff tests |

## Validation evidence

- Original 71 focused tests passed, including the repaired Director bag-decision fixture. Expanded set: 105 test files, with dependency/async fixture updates and successful reruns of the four initially failing fixtures.
- Native operational browser test now covers failed saves, retry/reload, local and exporter sales, Pohanch print dates and the runtime export gate weight override.
- Native source-handoff test covers operational bill to Accounts liability, amendment/replacement and partial/full payment, production acknowledgement recovery and bag movements.
- 36 screen states captured at both 1440 and 390 px (72 captures): no page errors or document-level horizontal overflow. Screenshots were visually reviewed; tables retain their own horizontal scroll where needed.
- Eight print-preview PDFs were generated and rendered for visual review: daily arrivals, Pohanch, export gate pass, labour bill, reprocessing bill, Rice Ledger, daily production and daily recovery. Local-sale gate pass was also opened and checked in the native flow.
- PHP syntax and whitespace checks pass. GitHub workflow results and deployment are recorded in the pull request / Actions runs for the exact released commit.

## Boundaries and business review

- Live queues with no records cannot establish every populated business state; those paths are covered by fixtures and scoped endpoint tests. This is a measured coverage record, not a claim to exhaust every possible transaction combination.
- Owner clarification: customers may buy any variety. For TG/AMT/13, Jazib originally entered IRRI-6 in error and later corrected the sales contract to PK386. No customer-to-variety restriction is appropriate. Preserve the records; checking whether an addendum or corrected contract was issued is deferred to the later Exports audit, as requested.
- PR #605 reports correctly show missing purchase-cost/FX/advice inputs as needing review. No values were invented to make the reports appear complete.
- Print verification used browser PDF output; no physical printer, printer driver, paper feed or Office PC delivery was exercised. Production/recovery print layout was viewed with the available empty source state; populated calculation/filter behavior is covered by report tests.
- Pohanch print-list tracking is session-local and does not amend a financial/stock source merely because a print window opens; existing historical printed flags are preserved.
