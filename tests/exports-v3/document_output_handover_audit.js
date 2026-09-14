'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const app = fs.readFileSync(path.join(__dirname, '../../exports/app.js'), 'utf8');
const css = fs.readFileSync(path.join(__dirname, '../../exports/app.css'), 'utf8');

function finalSlice(start, end, limit = 30000) {
  const at = app.lastIndexOf(start);
  assert.ok(at >= 0, 'Missing final renderer: ' + start);
  const stop = end ? app.indexOf(end, at + start.length) : -1;
  return app.slice(at, stop > at ? stop : at + limit);
}

const customs = finalSlice('function freshCustomsInvoiceDocument', 'function freshCustomPackingDocument');
assert.match(customs, /CUSTOMS INVOICE/);
assert.match(customs, /NUMBER AND KIND OF PACKAGES/);
assert.match(customs, /TOTAL NET WEIGHT|NET WEIGHT/);
assert.match(customs, /TARE WEIGHT/);
assert.match(customs, /GROSS WEIGHT/);
assert.match(customs, /\.toFixed\(3\)} MT/);
assert.match(customs, /\.toFixed\(3\)} KG/);
assert.match(customs, /PAYMENT BREAKDOWN/);
assert.match(customs, /BANK NAME/);
assert.match(customs, /IBAN/);
assert.doesNotMatch(customs, /ILLUSTRATIVE PREVIEW|SAMPLE DATA/);

const packing = finalSlice('function freshCustomPackingDocument', 'phytoInvoiceDoc=function');
assert.match(packing, /CUSTOM PACKING LIST/);
assert.match(packing, /TOTAL NET WEIGHT/);
assert.match(packing, /TARE WEIGHT/);
assert.match(packing, /TOTAL GROSS WEIGHT/);
assert.doesNotMatch(packing, /DRAWEE[^\n]*NAME &amp; ADDRESS/);

const phyto = finalSlice('phytoInvoiceDoc=function', 'packingListDoc=function', 1000);
assert.match(phyto, /freshCustomsInvoiceDocument\(s,c,'PHYTOSANITARY INVOICE'\)/);

const bl = finalSlice('blDraftDoc=function', 'function ttContractPackingPriceLabel');
assert.match(bl, /oceanBlPage/);
assert.match(bl, /OCEAN BILL OF LADING/);
assert.match(bl, /DRAFT FOR APPROVAL/);
assert.match(bl, /CONTAINER NO\./);
assert.match(bl, /NON-NEGOTIABLE COPIES/);
assert.doesNotMatch(bl, /pageDoc\(|letterhead\(|footerArt\(|signature\(/);
assert.doesNotMatch(bl, /ILLUSTRATIVE|\* Port of loading/);

const price = finalSlice('contractPriceHTML=function', 'const ttIssuePOBeforeHandover');
for (const label of ['TOTAL FOB VALUE', 'TOTAL FREIGHT', 'TOTAL INSURANCE', 'TOTAL CONTRACT VALUE']) {
  assert.match(price, new RegExp(label));
}
assert.match(price, /approvedContractPriceGrid/);
assert.match(price, /amountWords/);
assert.match(price, /For \$\{num\(p\.size\)\}/);

const po = finalSlice('purchaseOrderPrint=function', 'window.addEventListener');
assert.match(po, /TOTAL ORDER/);
assert.match(po, /P\.O\. NUMBER MUST BE MENTIONED ON THE DELIVERY ORDER/);
assert.match(po, /SALES TAX INVOICE/);
assert.match(po, /masters\.push/);
assert.match(po, /serial\+\+/);
assert.match(app, /A Bag Purchase Order already exists for this contract/);
assert.match(app, /Update Same PO/);

for (const renderer of ['commercialInvoiceDoc=function', 'function cooDoc', 'function coveringDoc']) {
  assert.ok(app.includes(renderer), renderer + ' must remain available after reconciliation');
}
assert.match(css, /Document Output Section handover/);
assert.match(css, /\.oceanBlPage/);
assert.match(css, /\.approvedCustomsGoods/);
assert.match(css, /\.approvedContractPriceGrid/);

console.log('Document Output Section handover audit passed.');
