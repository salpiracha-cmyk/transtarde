'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const app = fs.readFileSync(path.join(__dirname, '../../exports/app.js'), 'utf8');

assert.match(app, /x\.contracts=x\.contracts\.filter\(c=>c&&c\.status!=='Cancelled'&&!c\.cancelled\)/, 'cancelled contracts must be removed from active state');
assert.match(app, /while\(used\.has\(sequence\)\)sequence\+\+/, 'contract references must reuse the first available sequence');
assert.match(app, /Cancelled and removed; reference released/, 'cancellation must leave an audit event while releasing the reference');
assert.doesNotMatch(app.slice(app.lastIndexOf('function renderContracts'), app.indexOf('function restartShipmentData', app.lastIndexOf('function renderContracts'))), /data-reopen|Request Reopen|Director/, 'Sales Contract list must not retain the Director reopen gate');
assert.doesNotMatch(app.slice(app.lastIndexOf('function saveContract'), app.indexOf('function applyNewCustomerContractDefaults', app.lastIndexOf('function saveContract'))), /approved Director reopen|reopenAuthorized/, 'Save / Print must not require Director approval');
assert.match(app, /contractStep=target;renderContractEditor\(\)/, 'all Sales Contract sections must be directly selectable');

assert.match(app, /const bagOrderDrafts=new Map\(\)/, 'Bag Order drafts must be session-only');
assert.match(app, /for\(const s of x\.shipments\)delete s\.bagOrderDraft/, 'legacy persisted Bag Order drafts must be removed during normalization');
const activeBagOrder = app.slice(app.lastIndexOf('function renderBagOrder'), app.indexOf('function purchaseOrderPrint__legacy_v5', app.lastIndexOf('function renderBagOrder')));
assert.doesNotMatch(activeBagOrder, /Reset Draft|newPODraft/, 'Reset Draft must be removed from the active Bag Order');
assert.match(activeBagOrder, /data-bo-tare/, 'missing main tare must open an input in Bag Order');
assert.match(activeBagOrder, /data-bo-master-tare/, 'missing master tare must open an input in Bag Order');
const activeIssue = app.slice(app.lastIndexOf('function issuePO'), app.indexOf('function purchaseOrderPrint__morning_base', app.lastIndexOf('function issuePO')));
assert.match(activeIssue, /tare weight greater than zero for every main bag line/, 'main tare must block PO issue');
assert.match(activeIssue, /tare weight greater than zero for every required master bag/, 'master tare must block PO issue');

const millSource = app.slice(app.indexOf('function millLocations'), app.indexOf('function mount'));
assert.match(millSource, /masterValues\('mills'\)/, 'mill locations must come from Master Data');
assert.doesNotMatch(millSource, /MILL_STORE|TTI Rice Mills|localStorage/, 'deleted or hard-coded mills must not reappear');
assert.doesNotMatch(millSource, /location-master\.php|fetch\(/, 'Loading Instructions must not write ad-hoc entries back into Master Data');

assert.match(app, /x\.openAccount=Math\.max\(0,invoice-allocated-amount\)/, 'FI allocation must immediately recalculate Open Account');
assert.match(app, /Total FI allocation cannot exceed the Customs Invoice value/, 'FI allocation must prevent over-allocation');
assert.match(app, /paymentControl=isTG\?/, 'Customs payment terms must be rendered only for TG sales');
assert.match(app, /paymentTerms:isTG\?ttDirectCustomsPaymentLabel\(code\):paymentText\(c\)/, 'direct shipment payment classification must remain internal');

assert.match(app, /function packingPackageText/, 'one detailed packing formatter must control main and master bag wording');
assert.match(app, /FURTHER PACKED IN \$\{masters\.toLocaleString\(\)\} MASTER BAGS/, 'master-bag count and composition must flow to documents');
assert.match(app, /function outputBrand[\s\S]*toUpperCase\(\)/, 'document brand names must be uppercased centrally');
assert.match(app, /function documentSpecificationHTML[\s\S]*includeSpecification===false/, 'B/L specification choice must control downstream documents');

assert.match(app, /\$\{letterhead\(c\)\}\$\{page===1\?'<h1 class="docTitle">SALES CONTRACT<\/h1>'/, 'letterhead must repeat on every Sales Contract page while the title remains first-page only');
assert.match(app, /Every FI used in Customs must have both FI Number and FI Date/, 'B/L PDF must validate FI number and date');
assert.match(app, /Enter a GD Number and Date or allocate a dated FI before creating the B\/L PDF/, 'B/L PDF must require an applicable dated GD or FI');
assert.match(app, /A GD number and GD date are mandatory for a direct contract/, 'direct final Commercial Invoice must require GD number and date');
assert.match(app, /TOTAL NET WEIGHT[\s\S]*TOTAL GROSS WEIGHT/, 'Commercial Invoice must show separate net and gross totals');
assert.match(app, /M\.TONS/, 'output quantities must use M.TONS');

console.log('PASS 2026-09-16 Export corrections regression audit');
