const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const outputDir = path.join(root, 'tmp', 'qa');
fs.mkdirSync(outputDir, { recursive: true });

let app = fs.readFileSync(path.join(root, 'exports', 'app.js'), 'utf8');
app = app.replace(
  'mount();restoreContractCheckpoint();',
  'window.__renderSalesContract=(contract,customers=[])=>{state.customers=customers;return salesContractPrint(contract)};'
);
assert.match(app, /window\.__renderSalesContract/, 'Sales Contract renderer test hook was not installed');
const css = fs.readFileSync(path.join(root, 'exports', 'app.css'), 'utf8');

const customers = [{
  id: 'ASAS',
  name: 'Ali Sulaiman Al-Shehri Trading Company',
  address: 'Khaldiyah North, St 2, Bldg 7664, 32231, Dammam, Saudi Arabia',
  phone: '+966 13 8475806/7',
  fax: '+966 13 8476180'
}];
const specs = [
  ['Average Grain Length', '6.0 mm'], ['Moisture', '14% max'],
  ['Damaged / Shriveled / Yellow', '2.5% max'], ['Chalky / Immature', '5% max'],
  ['Contrasting / Other Varieties', '4% max'], ['Foreign Matter', '0.8% max'],
  ['Paddy', '0.5% max'], ['Red Kernels / Red Rice', '1% max'],
  ['Under-milled / Red-striped', '2% max']
].map(([name, value]) => ({ name, value }));
const terms = [
  'All present and/or future customs taxes and/or duties/levies on the cargo in the country of origin shall be for Seller’s account. All present and/or future customs taxes and/or duties/levies on the cargo in the country of destination shall be for Buyer’s account.',
  'Risk of weight and quality is transferred to Buyer once cargo is loaded on board the vessel from Pakistan.',
  'Ownership of cargo is transferred to Buyer upon receipt of full payment of the invoice.',
  'All other terms and conditions as per applicable GAFTA London rules, of which both parties admit full notice and knowledge. English law to apply.',
  'Should any dispute arise which cannot be amicably settled between Buyer and Seller, the dispute shall be settled by arbitration in London as per applicable GAFTA rules.',
  'Partial shipment allowed.',
  'Third-party documents are acceptable except Commercial Invoice and documents specifically required to be issued by the Seller.',
  'Insurance shall be for Seller’s account.',
  'Inspection by SGS Pakistan Private Limited at Seller’s cost.'
];
const documentNames = [
  'Commercial Invoice', 'Commercial Packing List', 'Full set clean on-board Bill of Lading',
  'Certificate of Origin', 'e-Phyto issued by Department of Plant Protection, Government of Pakistan',
  'Fumigation Certificate', 'Insurance Policy / Certificate',
  'SGS Pakistan Private Limited’s Certificates'
];
const contract = {
  id: 'SC-QA', ref: 'TG/ASAS/05', seller: 'TG', customerId: 'ASAS', date: '2026-09-16',
  buyerPoNo: '', product: 'IRRI-6 White Rice', hsCode: '1006.30', broken: 5,
  finish: 'Well milled; silky polished; well sortexed', cropYear: '2026/2027',
  quality: 'Pakistan IRRI-6 long grain White Rice, 5% broken, Well milled; silky-polished; well sortexed, new crop 2026/2027. As per below specification.',
  specMode: 'Contract Specific', specRows: specs,
  additionalQuality: 'Free from live insects, bad odour and rice fit for human consumption.',
  qty: 80.4, containers: 3, tolerance: 5, shipmentDate: '2026-09-30',
  pol: 'Port Qasim, Pakistan or Karachi Port, Pakistan', podPort: 'Jeddah', podCountry: 'Saudi Arabia',
  packingUnit: 'KG', currency: 'USD', incoterm: 'CIF', insurance: "Seller's Account",
  paymentCode: 'ADV_CAD', advancePct: 30, paymentDeadline: '2026-09-22', signedDeadline: '2026-09-18',
  inspection: 'SGS Pakistan Private Limited',
  inspectionDocumentName: 'SGS Pakistan Private Limited’s Certificates',
  terms, termsInitialized: true,
  documentsPresented: documentNames.map((name, index) => ({ sequence: index + 1, name, original: index < 3 ? 3 : 1, copies: index < 4 ? 3 : index % 2 })),
  packings: [{ size: 40, type: 'P.P. Bags', brand: 'ASAS', tare: 120, containers: 3, weightPer: 26.8, contractRate: 430, price: 400, freight: 28, insurance: 2, masterBag: { enabled: false } }]
};

(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1400, height: 1100 } });
  await page.route('http://tt.local/exports/assets/**', async route => {
    const filename = path.basename(new URL(route.request().url()).pathname);
    await route.fulfill({ path: path.join(root, 'exports', 'assets', filename), contentType: 'image/png' });
  });
  await page.setContent('<!doctype html><html><head><meta charset="utf-8"><base href="http://tt.local/exports/"></head><body><div id="app"></div><div id="printRoot"></div></body></html>');
  await page.addStyleTag({ content: css });
  await page.evaluate(() => { window.TT_MODULE_ACCESS = { user: 'Browser QA', masters: { banks: [{ id: 'BANK-QA', values: ['', 'TG TRANS GRAINS FOODSTUFF TRADING L.L.C', '', 'TRANS GRAINS FOODSTUFF TRADING L.L.C', 'EMIRATES NBD', 'DUBAI MAIN BRANCH', 'UNITED ARAB EMIRATES', 'USD', 'QA-ACCOUNT-001', 'AE00QA000000000000001', 'EBILAEAD', '', '', 'Active'] }] } }; });
  await page.addScriptTag({ content: app });
  await page.evaluate(({ contract, customers }) => {
    const root = document.getElementById('printRoot');
    root.setAttribute('aria-hidden', 'false');
    root.innerHTML = window.__renderSalesContract(contract, customers);
    root.style.display = 'block';
  }, { contract, customers });
  await page.waitForTimeout(250);

  const report = await page.evaluate(() => {
    const mm = 96 / 25.4;
    const pages = [...document.querySelectorAll('.salesContractPhysicalPage')];
    return pages.map((node, index) => {
      const pageRect = node.getBoundingClientRect();
      const body = node.querySelector('.salesContractPageBody');
      const bodyRect = body.getBoundingClientRect();
      const children = [...body.children];
      const contentBottom = children.length ? Math.max(...children.map(child => child.getBoundingClientRect().bottom)) : bodyRect.top;
      const protectedBottom = pageRect.bottom - 32 * mm;
      return {
        page: index + 1,
        contentOverflowPx: Math.max(0, contentBottom - protectedBottom),
        unusedBodyMm: Math.max(0, (protectedBottom - contentBottom) / mm),
        titleCount: node.querySelectorAll(':scope > .docTitle').length,
        letterheadCount: node.querySelectorAll(':scope > .docLetterhead, :scope > .docLetterheadText').length,
        pageStampCount: node.querySelectorAll(':scope > .salesContractPageStamp').length,
        finalSignatureCount: node.querySelectorAll('.contractSignatureGrid').length,
        validityCount: [...node.querySelectorAll('.docSection')].filter(x => x.textContent.trim() === 'VALIDITY').length,
        termsHeadings: [...node.querySelectorAll('.docSection')].filter(x => x.textContent.includes('OTHER TERMS AND CONDITIONS')).length,
        documentHeadings: [...node.querySelectorAll('.docSection')].filter(x => x.textContent.includes('DOCUMENTS TO BE PRESENTED')).length,
        text: node.innerText
      };
    });
  });

  await page.screenshot({ path: path.join(outputDir, 'sales-contract-browser.png'), fullPage: true });
  await page.pdf({ path: path.join(outputDir, 'sales-contract-browser.pdf'), format: 'A4', printBackground: true, preferCSSPageSize: true, margin: { top: '0', right: '0', bottom: '0', left: '0' } });
  fs.writeFileSync(path.join(outputDir, 'sales-contract-browser-report.json'), JSON.stringify(report.map(({ text, ...row }) => row), null, 2));
  console.log(JSON.stringify(report.map(({ text, ...row }) => row)));

  assert.ok(report.length >= 2 && report.length <= 3, `representative contract must use 2-3 pages, got ${report.length}`);
  assert.equal(report.reduce((sum, row) => sum + row.titleCount, 0), 1, 'title must appear on page one only');
  assert.ok(report[0].letterheadCount >= 1, 'page one must retain the seller letterhead');
  assert.ok(report.slice(1).every(row => row.letterheadCount === 0), 'continuation pages must start with the reference/date box, without repeating the letterhead');
  assert.equal(report.at(-1).pageStampCount, 0, 'final page must not have the separate page stamp');
  assert.equal(report.at(-1).finalSignatureCount, 1, 'final page must have exactly one Seller/Buyer signature block');
  assert.equal(report.at(-1).validityCount, 1, 'validity must remain with final signatures');
  for (const row of report) {
    assert.ok(row.contentOverflowPx < 1, `page ${row.page} content enters the protected bottom area by ${row.contentOverflowPx.toFixed(1)}px`);
    assert.ok(row.termsHeadings <= 1, `page ${row.page} repeats the terms heading/table`);
    assert.ok(row.documentHeadings <= 1, `page ${row.page} repeats the documents heading/table`);
  }
  for (const row of report.slice(0, -1)) {
    assert.ok(row.unusedBodyMm <= 35, `page ${row.page} has a ${row.unusedBodyMm.toFixed(1)}mm avoidable content gap`);
    assert.equal(row.pageStampCount, 1, `non-final page ${row.page} must have one page stamp`);
  }
  const allText = report.map(row => row.text).join('\n');
  for (const text of [...terms, ...documentNames]) assert.ok(allText.includes(text), `missing contract output: ${text}`);

  await browser.close();
  console.log('PASS Sales Contract real-browser pagination and PDF QA');
})().catch(error => { console.error(error); process.exit(1); });
