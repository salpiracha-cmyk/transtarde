const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const outputDir = path.join(root, 'tmp', 'qa', 'shipment-documents');
fs.mkdirSync(outputDir, { recursive: true });

let app = fs.readFileSync(path.join(root, 'exports', 'app.js'), 'utf8');
app = app.replace(
  'mount();restoreContractCheckpoint();',
  `window.__renderShipmentDocuments=()=>{
    const buyer={id:'BUYER-QA',name:'North Star Foods L.L.C',address:'Warehouse 18, Jebel Ali Free Zone, Dubai, United Arab Emirates',notifies:[{name:'North Star Clearing L.L.C',address:'Office 210, Port Road, Jebel Ali, Dubai, United Arab Emirates'}]};
    state.customers=[buyer];
    const contract={id:'CONTRACT-QA',ref:'TTI/NS/01',seller:'TTI',customerId:buyer.id,date:'2026-09-16',product:'IRRI-6 White Rice',hsCode:'1006.30',broken:5,finish:'Well milled; silky polished; well sortexed',cropYear:'2026/2027',quality:'Pakistan IRRI-6 long grain White Rice, 5% broken, well milled, silky-polished and well sortexed, new crop 2026/2027. As per contract specifications.',additionalQuality:'Free from live insects, bad odour and rice fit for human consumption.',qty:54,containers:2,tolerance:5,shipmentDate:'2026-09-30',pol:'Port Qasim, Pakistan',podPort:'Jebel Ali',podCountry:'United Arab Emirates',packingUnit:'KG',currency:'USD',incoterm:'CFR',paymentCode:'LC_SIGHT',advancePct:0,docs:['Commercial Invoice','Packing List'],packings:[{size:25,type:'P.P. Bags',brand:'NORTH STAR',tare:80,containers:2,weightPer:27,price:410,freight:20,insurance:0,masterBag:{enabled:true,bagsPerMaster:2,quantity:1080,qty:1080,tare:120}}]};
    const shipment=makeShipment(contract);
    shipment.millActuals=[
      {number:'MSCU1234567',seal:'SL001',bags:1080,netKg:27000,tareKg:86.4,grossKg:27086.4,brand:'NORTH STAR',packing:'25 KG'},
      {number:'TGHU7654321',seal:'SL002',bags:1080,netKg:27000,tareKg:86.4,grossKg:27086.4,brand:'NORTH STAR',packing:'25 KG'}
    ];
    shipment.loadingPlan={bookingNumber:'BOOK-QA-01',shippingLine:'MAERSK',portOfLoading:'Port Qasim, Pakistan'};
    shipment.bl={...shipment.bl,blNo:'BL-QA-001',onBoardDate:'2026-09-21',vessel:'MV OCEAN STAR',voyage:'V-108',consignee:buyer.name+' — '+buyer.address,notify:buyer.notifies[0].name+' — '+buyer.notifies[0].address,originals:3,copies:5,showSealNumbers:true};
    shipment.lc={saved:true,lcNo:'LC-QA-9001',lcDate:'2026-09-10',issuingBank:'FIRST INTERNATIONAL BANK, DUBAI',documents:['Commercial Invoice','Packing List'],conditions:[]};
    shipment.customs={...shipment.customs,description:'PAKISTAN LONG GRAIN IRRI-6 WHITE RICE',fiAllocations:[{number:'FI-QA-01',date:'2026-09-12',amount:12000,currency:'USD'}],gdRefs:[{number:'GD-QA-01',date:'2026-09-20'}],bank:'MEEZAN BANK LIMITED',iban:'PK66MEZN0001020103869880'};
    return {
      commercialInvoice:commercialInvoiceDoc(shipment,contract,false),
      packingList:packingListDoc(shipment,contract,false),
      blInstructions:blDraftDoc(shipment,contract),
      coo:cooDoc(shipment,contract,false)
    };
  };`
);
assert.match(app, /window\.__renderShipmentDocuments/, 'shipment document renderer test hook was not installed');
const css = fs.readFileSync(path.join(root, 'exports', 'app.css'), 'utf8');

(async () => {
  const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {}) });
  const page = await browser.newPage({ viewport: { width: 1500, height: 1100 } });
  await page.route('http://tt.local/exports/assets/**', async route => {
    const filename = path.basename(new URL(route.request().url()).pathname);
    await route.fulfill({ path: path.join(root, 'exports', 'assets', filename), contentType: 'image/png' });
  });
  await page.setContent('<!doctype html><html><head><meta charset="utf-8"><base href="http://tt.local/exports/"></head><body><div id="app"></div><div id="printRoot"></div></body></html>');
  await page.addStyleTag({ content: css });
  await page.evaluate(() => { window.TT_MODULE_ACCESS = { user: 'Browser QA', masters: {} }; });
  await page.addScriptTag({ content: app });
  const documents = await page.evaluate(() => window.__renderShipmentDocuments());

  const required = {
    commercialInvoice: ['COMMERCIAL INVOICE','BUYER — NAME AND ADDRESS','North Star Foods L.L.C','Warehouse 18','VESSEL','VOYAGE','B/L NUMBER','B/L DATE','L/C NUMBER','L/C DATE','L/C ISSUING BANK','PORT OF LOADING','PORT OF DISCHARGE','PAYMENT TERMS','FI-QA-01','GD-QA-01','1,080 MASTER BAGS','2 BAGS × 25 KG IN ONE MASTER BAG'],
    packingList: ['PACKING LIST','North Star Foods L.L.C','Warehouse 18','North Star Clearing L.L.C','Office 210','BL-QA-001','21-09-2026','MSCU1234567','SL001','TGHU7654321','SL002','COUNTRY OF ORIGIN: PAKISTAN','HS CODE: 1006.30','1,080 MASTER BAGS','GROSS WEIGHT','TARE','NET WEIGHT','M.TONS'],
    blInstructions: ['OCEAN BILL OF LADING','North Star Foods L.L.C','Warehouse 18','North Star Clearing L.L.C','Office 210','MSCU1234567','SL001','TGHU7654321','SL002','"NORTH STAR" BRAND','PAKISTAN LONG GRAIN IRRI-6 WHITE RICE','HS CODE: 1006.30','1,080 MASTER BAGS','2 BAGS × 25 KG IN ONE MASTER BAG'],
    coo: ['North Star Foods L.L.C','36453','BY SEA','BL-QA-001','GOODS OF PAKISTAN ORIGIN','54.173 M.TONS','54.000 M.TONS']
  };

  const report = {};
  for (const [name, html] of Object.entries(documents)) {
    await page.evaluate(html => {
      const node = document.getElementById('printRoot');
      node.setAttribute('aria-hidden', 'false');
      node.style.display = 'block';
      node.innerHTML = html;
    }, html);
    await page.waitForTimeout(100);
    report[name] = await page.evaluate(() => {
      const pages = [...document.querySelectorAll('#printRoot .docPage')];
      return {
        pages: pages.length,
        text: document.getElementById('printRoot').innerText,
        horizontalOverflow: pages.map(node => Math.max(0, node.scrollWidth - node.clientWidth)),
        verticalOverflow: pages.map(node => Math.max(0, node.scrollHeight - node.clientHeight)),
        blBackgrounds: [...document.querySelectorAll('.oceanBlHeader, .oceanBlGoodsTitle, .oceanBlContainers thead th, .oceanBlContainers tfoot th')].map(node => getComputedStyle(node).backgroundColor)
      };
    });
    for (const text of required[name]) assert.ok(report[name].text.toUpperCase().includes(text.toUpperCase()), `${name} is missing ${text}`);
    if(name==='commercialInvoice') assert.ok(!/(^|\n)QUANTITY($|\n)/i.test(report[name].text), 'single-line Commercial Invoice must not show a Quantity column');
    if(name==='blInstructions') assert.equal((report[name].text.match(/"NORTH STAR" BRAND/gi)||[]).length,1,'B/L brand must appear once, in Marks');
    assert.ok(report[name].horizontalOverflow.every(value => value < 2), `${name} has content outside its page width`);
    assert.ok(report[name].verticalOverflow.every(value => value < 2), `${name} has content outside its page height`);
    if (name === 'blInstructions') assert.ok(report[name].blBackgrounds.every(value => value === 'rgb(255, 255, 255)' || value === 'rgba(0, 0, 0, 0)'), 'B/L Draft must remain plain white without coloured bands');
    await page.screenshot({ path: path.join(outputDir, `${name}.png`), fullPage: true });
    if(name!=='coo') await page.pdf({ path: path.join(outputDir, `${name}.pdf`), format: 'A4', printBackground: true, preferCSSPageSize: true, margin: { top: '0', right: '0', bottom: '0', left: '0' } });
  }
  fs.writeFileSync(path.join(outputDir, 'report.json'), JSON.stringify(Object.fromEntries(Object.entries(report).map(([name, value]) => [name, { ...value, text: undefined }])), null, 2));
  await browser.close();
  require('node:child_process').execFileSync(process.execPath, [path.join(__dirname, 'document_revision_20260919_browser_qa.js')], { stdio: 'inherit', env: process.env });
  console.log('PASS real-browser Commercial Invoice, Packing List and B/L output QA');
})().catch(error => { console.error(error); process.exit(1); });
