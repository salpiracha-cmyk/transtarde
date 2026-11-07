# Cloud master audit — 20 October 2026

This continuation follows the PR397 checkpoint, not the older 19 October report.
Salman requested the entire audit continue. Secure in-chat browser authentication
succeeded, and the cloud session visibly identified Salman / Super Admin. No
password, OTP, cookie or private screenshot is included in this report.

## Repairs and acceptance

### PR398: company ownership row alignment

The Remove button in the company ownership row was taller and started above its
adjacent name input. A scoped CSS rule aligns the button with that input. Native
browser regression covers desktop, tablet and mobile widths, dynamic ownership
rows and removing an additional owner. Feature e7bd21aca597f4170ff4883bf2cb03c1a07ba929,
tree 4f70a6306a06a33fe6cfb2c93baccf6e9501292c, passed Accounts39377428669 and
Milling/Exports39377428797. Merge bf183b1a2d41f6d253cc09eaffd4ad3a02506f59 passed
main Accounts39377807931 and Milling/Exports39377807933; deployment39378134101
succeeded. Actual cloud Chrome then measured both name input and Remove button
at top581.03125px and height42px. No company record was saved.
Protected live39378212088/job118481034075 completed23/23, comparing53active
ledgers across3companies with0financial writes.

### PR399: purchase commodity conditionals and KAT identity

Corn selection wrongly retained Rice type and Broken percentage. These fields
now hide for Corn/Sesame and required-state follows the commodity. Switching
back retains unfinished Rice input; explicit non-Rice save clears irrelevant
broken grade while preserving existing historical non-Rice type. Managed option
choices dispatch the ordinary dependency-change event. KAT choices filter the
commodity, variety/type and purchased stage. Existing mismatched saved choices
remain visibly reviewable until an explicit source change; no source is guessed.

The server previously checked KAT identity only inside the Rice branch. It now
rejects a mismatched profile for all three commodities. The actual-auth disposable
API regression reproduced the old Corn+Rice HTTP200 defect, rejects cross-source,
wrong-stage/variety and missing profiles, accepts exact matches, and verifies
refused operations leave master and Accounts bytes unchanged. The actual editor
fixture verifies conditional inputs, historical preservation and0selection POSTs.

Initial Accounts39378535528 failed because the new DOM test ran before JSDOM was
installed. Workflow order was corrected; no application assertion was weakened.
Final c31bf4004d1bc24783d822695ce777dca0874703/tree
e33c1e896ace05bc4cda5a6c29d167d0fbfc261d passed feature Accounts39379024306 and
Milling/Exports39379024317. Merge ccb410d60cb6e94171b3b6a11b724b265b66a790 passed
main Accounts39379508076 and Milling/Exports39379508083; deployment39379845813
succeeded. Fresh cloud Chrome visibly removed both Rice-only controls after
selecting Corn. Its unsaved inspection draft was discarded by navigation.
Protected live39379942106/job118486522502 completed23/23:53active ledgers,
3companies,29posted JVs, September Brokerage WHT screen/CSV/print credit2414.06,
0financial/stock/reference-link/assessment writes. Positive production sources
were0; this result does not certify positive recovery transactions.

### PR400: location billing company, reference controls and business dates

The custom location editor omitted existing field7, Bills paid by company.
An unrelated edit could therefore submit an empty value and remove a location
from its company's expense choices. The editor now exposes and preserves that
assignment; new locations stay unassigned until selected. External/Reprocessing
Mill variants hide the irrelevant control without destroying historical values.
The helper explains why unassigned locations do not appear for company bills.
A regression exercises the actual editor save payload and PHP expense-location
projection: an existing BRM location retains BRM after unrelated notes editing
and appears only in BRM choices. No production location was changed.

Reference list labels are readable. Currency full name is conditional, unfinished
text survives switching, and an existing option cannot be retargeted to another
list by an unrelated rename. Salary Master and added buying/selling Brokery row
dates now use Asia/Karachi rather than UTC. Six frozen midnight/month/year/leap
boundary cases cover new defaults; historical dates remain unchanged. All three
new actual-source fixtures pass locally and in feature Accounts39380377498;
Milling/Exports39380377483 passed. Final feature09466c6c71ca163e91f8d2f87d3e8b2d37390cc0,
tree371c18b117071b9400cd80cc62ec6cd63094b2af, merged as
5b4e51143fcd6d198b21203b8adf4018d673d8a4. Exact-main Accounts39380804854 (including inventory runtime) and
Milling/Exports39380804924 passed. Deployment39381157024/job118490332464 installed exact5b4e5114 and passed
provider/login health. Changed users0; post-ID migration no-op, changeCount0,
418journals retained. Actual refreshed cloud Chrome shows the new optional
billing-company selector with Not assigned, readable reference names, disabled
existing Party roles list with no currency-only field, a new Currencies option
with its full-name field, and new Salary Master date2026-10-20. All inspection
forms closed without Save. Startup39381252670 passed. Final protected live39381252645/job118490627358 completed successfully:
23/23 in2.1minutes,53active ledgers across3companies,29posted JVs, Brokerage WHT
source/screen/CSV/print credit2414.06,0financial writes. No positive production
source was available. The refreshed Control Centre also showed0pending approvals.

## Master and unused-field review

Create editors for all ten visible master families were opened and inspected:
Companies, Export Customers, Business Parties, Export Quality & Specs, Purchase
Commodities & KAT, Mills & Locations, Currency & Reference, Export Documents,
Export Other Terms, and Salary & Staff. Conditional selection and an added
specification row were inspected without Save. The original create/amend/every
dependent-document scope moves from not tested to partial because actual editor
coverage now has isolated save/API/linkage regressions. It remains broader than
these tested paths. Coverage156:124passed,23partial,3failed,3not tested,2blocked,
1intermittent;32scopes still need some acceptance, not32confirmed defects.

Export Customer fields16–21 (Contacts JSON, Consignees JSON, Additional notify
parties JSON, Additional document addresses JSON, Default currency and Default
payment/customer instructions) are stored by the generic master, but the current
canonical api/export_customers.php customer reader and customer-master.js map
only0–15. The canonical update preserves the tail. These advanced inputs have
no demonstrated consumer in that path. Retain stored values pending a concrete
choice to simplify those inputs or implement their intended linkage. Primary
notify parties field9 is consumed and must not be included in a removal proposal.
No customer master or financial/document default was changed by this finding.

## Remaining source and environment requirements

The source decisions in CLOSEOUT_2026-10-20.md still apply: approved annual Zakat
counterpart and7210opening allocation, inactive FTR mapping, AMT13 legal wording
and Customs HS, historical ASAS62 original versus separately authorised revision,
and real physical Mill/bag openings. Current generated packing layout is fixed;
the older uploaded issued PDF remains preserved. Existing Yar Azam79000/Jazib27000
opening advances with1000monthly recovery were already verified; no duplicate
opening was created.

The earlier Maize discussion explicitly left actual rates unavailable. The only
figure was an estimated6000–6500/container for direct truck-to-container labour;
it is not an approved rate/effective date. Unloading and25/50kg paltai rates were
also unavailable. Do not promote demo or estimate values into company masters.

Positive production/recovery, mixed/billed Corn replacement, real finance/property/
share transactions, supplier scans and every actual approval/cancellation need
approved sources or an identified staging dataset. Opening empty forms and
passing isolated fixtures cannot certify absent business transactions. No live
financial/stock/approval/restore test write occurred in this pass.

Office PC installed agent/version, scheduled-task history, backup destination,
Daily/Weekly/Monthly archives and independent full ZIP verification remain
unseen. A cloud browser does not provide office OS/task access. Independent
migration/control totals/rollback and physical printing remain open. Formal
security preflight remains unavailable; no formal security clearance is claimed.

The cloud runtime intermittently prevented observation after native credential
delivery and a dirty Cancel. Documented explicit navigation of the same tab
restored the signed-in session. This tooling condition is not evidence of an
application login failure or a site bot block. Our unsaved drafts were discarded;
no user-entered transaction or production record was saved/deleted.
