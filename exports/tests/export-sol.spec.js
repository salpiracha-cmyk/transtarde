const {test,expect}=require('@playwright/test');
const STORE='transtrade_export_v2_operational';
const BASE='http://127.0.0.1:4173/exports/index.html';
function customer(){return{id:'CUS-1',name:'TEST BUYER LLC',code:'TB',address:'Dubai, UAE',country:'UAE',nextSeq:48}}
function contract(){return{id:'CTR-1',ref:'TTI/DM/47',seller:'TTI',customerId:'CUS-1',customer:'TEST BUYER LLC',date:'2026-09-09',product:'IRRI-6 Rice',broken:5,finish:'Silky Polished & Sortexed',quality:'Free from live insects, bad odour and rice fit for human consumption.',origin:'PAKISTAN',specs:[{name:'Moisture',value:'14% Max'},{name:'Broken',value:'5% Max'}],qty:27,tolerance:5,containers:1,weightPer:27,packingUnit:'KG',packings:[{type:'PP Bags',size:25,unit:'KG',brand:'BRAND A',tare:80,containers:1,weightPer:27,price:400,masterBag:{enabled:false,qty:0,tare:0}}],currency:'USD',incoterm:'CFR',pol:'Karachi Port, Pakistan',podPort:'Jebel Ali',podCountry:'UAE',destPort:'Dubai',destCountry:'UAE',shipmentDate:'2026-10-01',inspection:'None',insurance:"Buyer's Account",paymentCode:'LC_SIGHT',paymentText:'',advancePct:0,usanceDays:0,docs:[['COMMERCIAL INVOICE',3,3],['PACKING LIST',3,3]],terms:['Partial shipment allowed.','Trans-shipment allowed.'],issued:true,received:true,status:'Contract Received',sellerDetails:{name:'TRANSTRADE INTERNATIONAL',address:'Karachi, Pakistan'},buyerDetails:{name:'TEST BUYER LLC',address:'Dubai, UAE'},partyDisplay:{seller:{phone:false,email:false,taxId:false},buyer:{phone:false,email:false,taxId:false}}}}
function shipment(){return{id:'SHP-1',contractRef:'TTI/DM/47',buyer:'TEST BUYER LLC',plannedQty:27,status:'Contract Received',cancelled:false,completed:false,bagOrders:[],millActuals:[],loading:{lots:[]}}}
function seed(overrides={}){return Object.assign({version:1,customers:[customer()],contracts:[contract()],shipments:[shipment()],fi:[],banks:[],suppliers:[{id:'SUP-1',name:'Aleem Bags'}],alerts:[],audits:[],cancelled:[],lettersOfCredit:[{id:'LC-1',shipmentId:'SHP-1',contractRef:'TTI/DM/47',lcNo:'LC-TEST-001',lcDate:'2026-09-09',accepted:true,acceptedAt:'2026-09-09T00:00:00.000Z',discrepancies:[],applicant:'TEST BUYER LLC',beneficiary:'TRANSTRADE INTERNATIONAL',currency:'USD',amount:10800,latestShipmentDate:'2026-10-01',pol:'Karachi Port, Pakistan',pod:'Jebel Ali',paymentCode:'LC_SIGHT',documents:['COMMERCIAL INVOICE','PACKING LIST'],partial:'ALLOWED',transshipment:'ALLOWED'}],millSync:{newExportBags:[],productionInstructions:[],exportLoading:[],millReturns:[]}},overrides)}
function pdfPageCount(buf){const s=buf.toString('latin1');return (s.match(/\/Type\s*\/Page\b/g)||[]).length}
async function init(page,data=seed()){await page.addInitScript(({key,value})=>localStorage.setItem(key,JSON.stringify(value)),{key:STORE,value:data});await page.goto(BASE,{waitUntil:'networkidle'});await page.waitForSelector('#app .topbar')}
async function openShipment(page){await page.locator('[data-nav="shipments"]').click();await page.locator('[data-ship="SHP-1"]').click()}
async function clickPopup(page,locator){const p=page.waitForEvent('popup');await locator.click();const popup=await p;await popup.waitForLoadState('domcontentloaded');return popup}
async function changeValue(page,selector,value){await page.locator(selector).fill(value);await page.locator(selector).press('Tab')}

test('bundle runtime self-tests pass and standalone resources load',async({page})=>{await init(page);const r=await page.evaluate(()=>window.__TT_EXPORT_TEST__.runAcceptanceSelfTests());expect(r.passed).toBe(r.total);expect(r.total).toBeGreaterThanOrEqual(21);for(const p of ['app.part01.txt','app.part19.txt','assets/TG_header.png','assets/TG_footer.png','assets/TG_sign.png']){const res=await page.request.get(`http://127.0.0.1:4173/exports/${p}`);expect(res.ok(),p).toBeTruthy()}});

test('all workspace icons repeatedly open one detail directly under their row',async({page})=>{await init(page);await openShipment(page);for(const key of ['lc','bags','production','loading','mill','customs','bl','commercial','coo','cover','output']){for(let pass=0;pass<2;pass++){await page.locator(`[data-workspace="${key}"]`).click();await expect(page.locator('.workspaceDetail')).toHaveCount(1);const row=page.locator(`[data-workspace="${key}"]`).locator('xpath=ancestor::div[contains(@class,"workspaceRow")]');expect(await row.locator('xpath=following-sibling::*[1][contains(@class,"workspaceDetail")]').count()).toBe(1)}}});

test('Bag Order multi-digit Extra typing keeps focus and PDF artwork persists for Mill sync',async({page})=>{await init(page);await openShipment(page);await page.locator('[data-workspace="bags"]').click();const extra=page.locator('[data-bo-extra="0"]');await extra.fill('');await extra.type('12');await expect(extra).toHaveValue('12');await page.locator('#boSupplier').selectOption({label:'Aleem Bags'});await page.locator('[data-bo-art="0"]').setInputFiles({name:'approved.png',mimeType:'image/png',buffer:Buffer.from('approved-art')});await page.waitForTimeout(100);const popup=await clickPopup(page,page.locator('#generatePO'));const body=await popup.locator('body').innerText();expect(body).toContain('APPROVED');expect(body).not.toContain('TEST BUYER LLC');expect(body).not.toContain('TTI/DM/47');await popup.close();const saved=await page.evaluate(k=>JSON.parse(localStorage.getItem(k)),STORE);expect(saved.shipments[0].bagOrders[0].lines[0].artworkAttached).toBe(true);expect(saved.millSync.newExportBags[0].lines[0].artworkData).toContain('data:image/png')});

test('accepted L/C is visibly carried into Customs, B/L and Commercial without overwriting Contract',async({page})=>{await init(page);await openShipment(page);for(const key of ['customs','bl','commercial']){await page.locator(`[data-workspace="${key}"]`).click();await expect(page.locator('#workspaceDetail')).toContainText('LC-TEST-001')}const current=await page.evaluate(k=>JSON.parse(localStorage.getItem(k)),STORE);expect(current.contracts[0].ref).toBe('TTI/DM/47');expect(current.contracts[0].buyerDetails.name).toBe('TEST BUYER LLC')});

test('bottom-right shared controls remain hidden and notification area stays clean',async({page})=>{await init(page);await page.evaluate(()=>{const u=document.createElement('div');u.id='ttUserBar';document.body.appendChild(u);const n=document.createElement('div');n.id='ttSyncNotice';document.body.appendChild(n)});await expect(page.locator('#ttUserBar')).toBeHidden();await expect(page.locator('#ttSyncNotice')).toBeHidden();await expect(page.locator('#logoutTop')).toHaveCount(1)});

test('Sales Contract and Bag PO render as real browser PDFs with locked print rules',async({page})=>{await init(page);await page.locator('[data-nav="contracts"]').click();const sc=await clickPopup(page,page.locator('[data-print-contract="CTR-1"]'));const scText=await sc.locator('body').innerText();expect(scText).toContain('SALES CONTRACT');expect(scText).toContain('SELLER');expect(scText).toContain('BUYER');const pdf=await sc.pdf({format:'A4',printBackground:true});expect(pdfPageCount(pdf)).toBeLessThanOrEqual(4);await sc.close();await openShipment(page);await page.locator('[data-workspace="bags"]').click();await page.locator('#boSupplier').selectOption({label:'Aleem Bags'});await page.locator('[data-bo-art="0"]').setInputFiles({name:'approved.png',mimeType:'image/png',buffer:Buffer.from('approved-art')});await page.waitForTimeout(100);const po=await clickPopup(page,page.locator('#generatePO'));expect(await po.locator('body').innerText()).toContain('APPROVED');const poPdf=await po.pdf({format:'A4',printBackground:true});expect(pdfPageCount(poPdf)).toBeGreaterThanOrEqual(1);await po.close()});

test('fresh L/C shipment completes operational chain with multiple FI + Bank, multiple GD and persistence',async({page})=>{
  test.setTimeout(60000);
  await init(page);
  await openShipment(page);

  await page.locator('[data-workspace="bags"]').click();
  await page.locator('#boSupplier').selectOption({label:'Aleem Bags'});
  await page.locator('[data-bo-art="0"]').setInputFiles({name:'approved.png',mimeType:'image/png',buffer:Buffer.from('approved-art')});
  await page.waitForTimeout(100);
  const poPopup=await clickPopup(page,page.locator('#generatePO'));
  await poPopup.close();

  await page.locator('[data-workspace="production"]').click();
  await page.locator('#sendPI').click();
  await expect(page.locator('#workspaceDetail')).toContainText('PRODUCTION INSTRUCTIONS ALREADY SENT');

  await page.locator('[data-workspace="loading"]').click();
  await page.locator('[data-li-cont="0"]').fill('1');
  await page.locator('[data-li-cont="0"]').press('Tab');
  await page.locator('#sendLoading').click();
  await expect(page.getByRole('heading',{name:'Export Operations'})).toBeVisible();

  await page.evaluate(()=>localStorage.setItem('tt32exportsync',JSON.stringify([{shipment:'LOT-01',container:'ABCD1234567',seal:'SEAL-001',bags:1080,weight:27000,brand:'BRAND A',truck:'TUE-138',gate:'GP-001',date:'2026-09-09'}])));
  await expect.poll(()=>page.evaluate(k=>JSON.parse(localStorage.getItem(k)).shipments[0].millActuals?.length||0,STORE),{timeout:9000}).toBe(1);

  await openShipment(page);
  await page.locator('[data-workspace="customs"]').click();
  await page.locator('#addFIAllocation').click();
  await page.locator('#addFIAllocation').click();
  await page.locator('[data-fi-no="0"]').fill('FI-001');
  await page.locator('[data-fi-amount="0"]').fill('3000');
  await page.locator('[data-fi-no="1"]').fill('FI-002');
  await page.locator('[data-fi-amount="1"]').fill('3000');
  await changeValue(page,'#cuRate','400');
  await changeValue(page,'#cuBank','Meezan Bank Limited');
  await changeValue(page,'#cuIBAN','PK00TEST0000000000000000');
  await changeValue(page,'#cuBankAmount','4800');
  await changeValue(page,'#cuGD','GD-001\nGD-002');
  await page.locator('#saveCustoms').click();
  expect(await page.evaluate(k=>JSON.parse(localStorage.getItem(k)).shipments[0].customs.saved,STORE)).toBe(true);

  await page.locator('[data-workspace="bl"]').click();
  await page.locator('#blNo').fill('BL-001');
  await page.locator('#blDate').fill('2026-09-09');
  await page.locator('#blVessel').fill('MV TEST');
  await page.locator('#blVoyage').fill('V001');
  const blPopup=await clickPopup(page,page.locator('#finalizeBL'));
  await blPopup.close();

  await page.locator('[data-workspace="commercial"]').click();
  await page.locator('#comGD').fill('GD-001\nGD-002');
  const commercialPopup=await clickPopup(page,page.locator('#finalCommercial'));
  await commercialPopup.close();

  await page.locator('[data-workspace="coo"]').click();
  await page.locator('#cooRef').fill('COO-001');
  await page.locator('#saveCOO').click();

  await page.locator('[data-workspace="cover"]').click();
  await page.locator('#coverBank').fill('Meezan Bank Limited');
  await page.locator('#coverDate').fill('2026-09-09');
  await page.locator('#coverTracking').fill('DHL-123456');
  await page.locator('#dispatchCover').click();

  await page.locator('[data-workspace="output"]').click();
  const finalGate=await page.evaluate(k=>{const st=JSON.parse(localStorage.getItem(k)),s=st.shipments[0],c=st.contracts[0];return{contractReceived:!!c.received,bagOrders:s.bagOrders?.length||0,productionSent:!!s.production?.sentToMill,loadingLots:s.loading?.lots?.length||0,millActuals:s.millActuals?.length||0,customsSaved:!!s.customs?.saved,blFinalized:!!s.bl?.finalized,commercialFinalized:!!s.commercial?.finalized,cooSaved:!!s.coo?.saved,coverSaved:!!s.covering?.saved,coverDispatched:!!s.covering?.dispatched,status:s.status}},STORE);
  console.log('FINAL_GATE_STATE',JSON.stringify(finalGate));
  await expect(page.locator('#printFinalPack')).toBeEnabled();
  const finalPopup=await clickPopup(page,page.locator('#printFinalPack'));
  const finalText=await finalPopup.locator('body').innerText();
  expect(finalText.lastIndexOf('BANK COVERING LETTER')).toBeGreaterThan(finalText.lastIndexOf('EXCHANGE DRAFT'));
  const finalPdf=await finalPopup.pdf({format:'A4',printBackground:true});
  expect(pdfPageCount(finalPdf)).toBeGreaterThanOrEqual(6);
  await finalPopup.close();
  await page.locator('#completeShipment').click();
  await expect(page.getByRole('heading',{name:'Completed Shipments'})).toBeVisible();
  const persisted=await page.evaluate(k=>JSON.parse(localStorage.getItem(k)),STORE);
  expect(persisted.shipments[0].completed).toBe(true);
});

test('partial loading consumes remaining balance across two locations without restarting contract quantity',async({page})=>{const d=seed();d.contracts[0].qty=54;d.contracts[0].containers=2;d.contracts[0].packings[0].containers=2;d.shipments[0].plannedQty=54;await init(page,d);await openShipment(page);await page.locator('[data-workspace="loading"]').click();await page.locator('#addLoadSource').click();await page.locator('[data-li-cont="0"]').fill('1');await page.locator('[data-li-cont="1"]').fill('1');await page.locator('[data-li-source="1"]').fill('External Mill A');await page.locator('[data-li-cont="0"]').press('Tab');await page.locator('#sendLoading').click();const saved=await page.evaluate(k=>JSON.parse(localStorage.getItem(k)),STORE);expect(saved.shipments[0].loading.lots).toHaveLength(2);expect(saved.shipments[0].loading.lots.reduce((a,x)=>a+x.qtyMT,0)).toBeCloseTo(54,3);expect(saved.shipments[0].loading.lots[0].qtyMT).toBeCloseTo(27,3);expect(saved.shipments[0].loading.lots[1].qtyMT).toBeCloseTo(27,3);expect(saved.shipments[0].loading.lots[1].location).toBe('External Mill A')});

test('tare mismatch stays Contract-controlled and normal non-L/C flow has no L/C leakage',async({page})=>{const d=seed({lettersOfCredit:[]});d.shipments[0].lcId='';await init(page,d);await openShipment(page);await page.locator('[data-workspace="bags"]').click();await page.locator('[data-bo-tare="0"]').fill('95');await page.locator('#boSupplier').selectOption({label:'Aleem Bags'});await page.locator('[data-bo-art="0"]').setInputFiles({name:'approved.png',mimeType:'image/png',buffer:Buffer.from('approved-art')});await page.waitForTimeout(100);const po=await clickPopup(page,page.locator('#generatePO'));await po.close();await page.locator('[data-workspace="customs"]').click();await expect(page.locator('#workspaceDetail')).toContainText('Contract tare wins');expect(await page.locator('#workspaceDetail').innerText()).not.toContain('Accepted L/C:')});
