const fs = require('fs');
const assert = require('assert');

const appPath = fs.existsSync(__dirname + '/../exports/app.js')
  ? __dirname + '/../exports/app.js'
  : __dirname + '/../../exports-clean-audit/release/app.js';
const app = fs.readFileSync(appPath, 'utf8');
const auth = fs.readFileSync(__dirname + '/../auth_store.php', 'utf8');
const admin = fs.readFileSync(__dirname + '/../admin/app.js', 'utf8');
const customers = fs.readFileSync(__dirname + '/../customer-master.js', 'utf8');

for (const token of [
  'Manual save only', 'function applyBuyerTemplate(customerId)',
  'Final Place of Delivery', 'How many bags in one master bag?',
  'Extra Bags (%)', 'Master Bag Tare', 'CNF/CFR value per MT',
  'Freight per MT', 'Insurance per MT', 'FOB value per MT',
  'SHOW ALL SEPARATELY ON CONTRACT',
  'Sequence</th><th>Document Name</th><th>Original</th><th>Copies',
  'SAVE / PRINT', 'Importer details to show',
  'BY ORDER AND FOR ACCOUNT OF', 'FI selection',
  'Amount to utilise for this lot',
  'This FI is already allocated to this lot', 'descriptionSource',
  'Custom Invoice</button>', 'Custom Packing List</button>',
  'Phytosanitary Invoice</button>', 'function exportDocumentMaster(c)',
  'function exportMasterTerms(c)', "masterValues('export_documents')",
  "masterValues('export_terms')"
]) assert(app.includes(token), `missing Export V3.2 correction: ${token}`);

assert(!app.includes('Final Destination / Place</label>'));
assert(!app.includes('Destination Country</label>'));
assert(!app.includes('Bags Required</th>'));
assert(!app.includes("invoiceNo:`CI-${c.ref"));
assert(!app.includes('id="saveContract">SAVE CONTRACT'));

for (const token of ['export_documents', 'export_terms']) {
  assert(auth.includes(`'${token}'`), `missing shared ${token} master`);
  assert(admin.includes(`id: "${token}"`), `missing Super Admin ${token} editor`);
}
for (const token of ['Master Data', 'Export Master Data', 'Customers & Notify Parties', 'Products & Quality', 'Documents Presented', 'Other Terms', 'Other Parties', 'Mills & Locations', 'Companies', 'Banks & Accounts']) {
  assert(customers.includes(token), `missing Master Data interface: ${token}`);
}

console.log('PASS Export V3.2 corrections, shared masters and Master Data interface');
