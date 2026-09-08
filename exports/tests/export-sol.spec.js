const { test, expect } = require('@playwright/test');

const STORE='transtrade_export_v2_operational';
const seedState={
  version:'browser-acceptance',
  customers:[{id:'C1',name:'TEST BUYER LLC',code:'TB',address:'Dubai, U.A.E.',country:'U.A.E.',packingDefault:'KG',nextSeq:2}],
  suppliers:[{id:'SUP1',name:'Aleem Bags'}],fi:[],audits:[],alerts:[],cancelled:[],
  lettersOfCredit:[{id:'LC1',shipmentId:'S1',contractRef:'TTI/TB/01',lcNo:'LC-TEST-001',lcDate:'2026-09-08',applicant:'TEST BUYER LLC',beneficiary:'TRANSTRADE INTERNATIONAL',currency:'USD',amount:10800,latestShipmentDate:'2026-10-01',pol:'Karachi Port, Pakistan or Port Qasim, Pakistan',pod:'Jebel Ali',paymentCode:'LC_SIGHT',partial:'ALLOWED',transshipment:'ALLOWED',documents:['COMMERCIAL INVOICE'],conditions:[],discrepancies:[]}],
  contracts:[{id:'CT1',ref:'TTI/TB/01',seller:'TTI',customerId:'C1',date:'2026-09-08',product:'IRRI-6 White Rice',broken:5,finish:'Silky Polished & Sortexed',quality:'Free from live insects, bad odour and rice fit for human consumption.',specMode:'Pakistan Origin Standard',containers:1,weightPer:27,qty:27,tolerance:5,pol:'Karachi Port, Pakistan or Port Qasim, Pakistan',shipmentDate:'2026-10-01',podPort:'Jebel Ali',podCountry:'U.A.E.',destPort:'Jebel Ali',destCountry:'U.A.E.',packingUnit:'KG',packings:[{brand:'BRAND A',type:'PP Bags',size:25,unit:'KG',tare:80,containers:1,weightPer:27,price:400,masterBag:{enabled:false,qty:0,tare:0}}],currency:'USD',incoterm:'FOB',inspection:'None',insurance:"Buyer's Account",paymentCode:'LC_SIGHT',advancePct:0,usanceDays:90,signedDeadline:'2026-09-10',paymentDeadline:'2026-09-14',issued:true,received:true,status:'Contract Received',sellerDetails:{name:'TRANSTRADE INTERNATIONAL',address:'Karachi, Pakistan',country:'Pakistan'},buyerDetails:{name:'TEST BUYER LLC',address:'Dubai, U.A.E.',country:'U.A.E.'},partyDisplay:{seller:{phone:false,email:false,taxId:false},buyer:{phone:false,email:false,taxId:false}}}],
  shipments:[{id:'S1',contractRef:'TTI/TB/01',lcId:'LC1',buyer:'TEST BUYER LLC',seller:'TTI',plannedQty:27,containers:1,status:'Contract Received',next:'BAG ORDER / Production Instructions',cancelled:false,completed:false,bagOrders:[],millActuals:[]}],
  millSync:{newExportBags:[],productionInstructions:[],exportLoading:[]},settings:{}
};

async function openShipment(page){
  await page.getByRole('button',{name:'Active Shipments'}).click();
  await page.locator('[data-ship="S1"]').click();
  await expect(page.locator('[data-workspace="contract"]')).toBeVisible();
}

async function clickPopup(page,locator){
  const eventPromise=Promise.race([
    page.waitForEvent('popup').then(p=>({popup:p})),
    page.waitForEvent('dialog').then(d=>({dialog:d.message()}))
  ]);
  await locator.click();
  const result=await eventPromise;
  if(result.dialog)throw new Error(`Expected printable output but workflow gate reported: ${result.dialog}`);
  const popup=result.popup;
  await popup.waitForLoadState('domcontentloaded').catch(()=>{});
  return popup;
}

async function changeValue(page,selector,value){
  await page.locator(selector).evaluate((el,v)=>{
    el.value=v;
    el.dispatchEvent(new Event('change',{bubbles:true}));
  },value);
  await page.waitForTimeout(30);
}

function pdfPageCount(buffer){
  return (buffer.toString('latin1').match(/\/Type\s*\/Page\b/g)||[]).length;
}

async function setState(page,state){
  await page.evaluate(([k,v])=>localStorage.setItem(k,JSON.stringify(v)),[STORE,state]);
  await page.reload();
  await expect(page.getByRole('heading',{name:/TRANSTRADE EXPORTS/})).toBeVisible();
}

test.beforeEach(async ({page})=>{
  const errors=[];
  page.on('pageerror',e=>errors.push(String(e)));
  page.on('console',m=>{if(m.type()==='error')errors.push(m.text())});
  page.on('dialog',d=>d.dismiss());
  await page.goto('http://127.0.0.1:4173/exports/index.html');
  await page.evaluate(([k,v])=>localStorage.setItem(k,JSON.stringify(v)),[STORE,seedState]);
  await page.reload();
  await expect(page.getByRole('heading',{name:/TRANSTRADE EXPORTS/})).toBeVisible();
  page.__errors=errors;
});

test('bundle runtime self-tests pass and standalone resources load',async({page})=>{
  const result=await page.evaluate(()=>window.__TT_EXPORT_TEST__.runAcceptanceSelfTests());
  expect(result.total).toBeGreaterThanOrEqual(21);
  expect(result.passed,result.results.filter(x=>!x.pass).map(x=>`${x.name}: ${x.detail}`).join('\n')).toBe(result.total);
  await expect(page.locator('#logoutTop')).toBeVisible();
  expect(page.__errors).toEqual([]);
});

test('all workspace icons repeatedly open one detail directly under their row',async({page})=>{
  await openShipment(page);
  const keys=['contract','lc','bags','production','loading','mill','customs','bl','commercial','coo','cover','output'];
  for(const key of keys){
    for(let i=0;i<10;i++){
      const target=page.locator(`[data-workspace="${key}"]`);
      if((await target.getAttribute('class')||'').includes('active')){
        const alternate=key==='contract'?'lc':'contract';
        await page.locator(`[data-workspace="${alternate}"]`).click();
      }
      await target.click();
      const detail=page.locator('#workspaceDetail');
      await expect(detail).toHaveCount(1);
      await expect(detail).toBeVisible();
      const row=target.locator('xpath=..');
      const rb=await row.boundingBox(),db=await detail.boundingBox();
      expect(rb&&db&&db.y>=rb.y+rb.height-1).toBeTruthy();
    }
  }
});

test('Bag Order multi-digit Extra typing keeps focus and PDF artwork persists for Mill sync',async({page})=>{
  await openShipment(page);
  await page.locator('[data-workspace="bags"]').click();
  await page.locator('#boSupplier').selectOption({label:'Aleem Bags'});
  const extra=page.locator('[data-bo-extra="0"]');
  await extra.click();await extra.press('Control+A');await extra.type('12');
  await expect(extra).toHaveValue('12');
  await expect(page.locator('[data-bo-extra-bags="0"]')).toHaveText('130');
  const pdf=Buffer.from('%PDF-1.4\n% approved bag artwork\n','utf8');
  await page.locator('[data-bo-art="0"]').setInputFiles({name:'approved-bag-art.pdf',mimeType:'application/pdf',buffer:pdf});
  await expect(page.locator('[data-bo-art="0"] + .fileNote')).toContainText('approved-bag-art.pdf');
  await page.waitForTimeout(100);
  const stored=await page.evaluate(k=>{const s=JSON.parse(localStorage.getItem(k));return s.shipments[0].bagOrderDraft?.lines?.[0]?.artworkData||''},STORE);
  expect(stored.startsWith('data:application/pdf;base64,')).toBeTruthy();
});

test('accepted L/C is visibly carried into Customs, B/L and Commercial without overwriting Contract',async({page})=>{
  await openShipment(page);
  for(const key of ['customs','bl','commercial']){
    await page.locator(`[data-workspace="${key}"]`).click();
    await expect(page.locator('#workspaceDetail')).toContainText('Accepted L/C:');
    await expect(page.locator('#workspaceDetail')).toContainText('LC-TEST-001');
  }
  const contract=await page.evaluate(k=>JSON.parse(localStorage.getItem(k)).contracts[0],STORE);
  expect(contract.ref).toBe('TTI/TB/01');
  expect(contract.paymentCode).toBe('LC_SIGHT');
});

test('bottom-right shared controls remain hidden and notification area stays clean',async({page})=>{
  await page.evaluate(()=>{const u=document.createElement('div');u.id='ttUserBar';u.textContent='Sign out';document.body.appendChild(u);const n=document.createElement('button');n.id='ttSyncNotice';n.textContent='Updated';document.body.appendChild(n)});
  await expect(page.locator('#ttUserBar')).toBeHidden();
  await expect(page.locator('#ttSyncNotice')).toBeHidden();
  await expect(page.locator('#logoutTop')).toBeVisible();
});

test('Sales Contract and Bag PO render as real browser PDFs with locked print rules',async({page})=>{
  await openShipment(page);
  await page.locator('[data-workspace="contract"]').click();
  const contractPopup=await clickPopup(page,page.locator('#printLinkedContract'));
  await expect(contractPopup.locator('body')).toContainText('SALES CONTRACT');
  const contractPdf=await contractPopup.pdf({format:'A4',printBackground:true});
  expect(pdfPageCount(contractPdf)).toBeGreaterThan(0);
  expect(pdfPageCount(contractPdf)).toBeLessThanOrEqual(3);
  const contractHtml=await contractPopup.content();
  expect(contractHtml).not.toContain('class="footer"');
  await contractPopup.close();

  await page.locator('[data-workspace="bags"]').click();
  await page.locator('#boSupplier').selectOption({label:'Aleem Bags'});
  await page.locator('[data-bo-art="0"]').setInputFiles({name:'approved.png',mimeType:'image/png',buffer:Buffer.from('approved-art')});
  await page.waitForTimeout(100);
  const poPopup=await clickPopup(page,page.locator('#generatePO'));
  const poText=await poPopup.locator('body').innerText();
  expect(poText).toContain('APPROVED');
  expect(poText).not.toContain('TEST BUYER LLC');
  expect(poText).not.toContain('TTI/TB/01');
  const poPdf=await poPopup.pdf({format:'A4',printBackground:true});
  expect(pdfPageCount(poPdf)).toBeGreaterThan(0);
  await poPopup.close();
});

test('fresh L/C shipment completes operational chain with multiple FI + Bank, multiple GD and persistence',async({page})=>{
  test.setTimeout(60000);
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
  await expect(page.locator('#printFinalPack')).toBeEnabled();
  const finalPopup=await clickPopup(page,page.locator('#printFinalPack'));
  const finalText=await finalPopup.locator('body').innerText();
  expect(finalText.lastIndexOf('BANK COVERING LETTER')).toBeGreaterThan(finalText.lastIndexOf('EXCHANGE DRAFT'));
  const finalPdf=await finalPopup.pdf({format:'A4',printBackground:true});
  expect(pdfPageCount(finalPdf)).toBeGreaterThanOrEqual(6);
  await finalPopup.close();
  await page.locator('#completeShipment').click();

  const saved=await page.evaluate(k=>JSON.parse(localStorage.getItem(k)).shipments[0],STORE);
  expect(saved.completed).toBe(true);
  expect(saved.customs.fiAllocations.map(x=>x.fiNo)).toEqual(['FI-001','FI-002']);
  expect(saved.customs.bankAmount).toBe(4800);
  expect(saved.customs.gdRefs).toEqual(['GD-001','GD-002']);
  expect(saved.commercial.finalized).toBe(true);
  expect(saved.covering.dispatched).toBe(true);

  await page.reload();
  await page.getByRole('button',{name:'Completed'}).click();
  await expect(page.locator('[data-ship="S1"]')).toBeVisible();
});

test('partial loading consumes remaining balance across two locations without restarting contract quantity',async({page})=>{
  const state=JSON.parse(JSON.stringify(seedState));
  state.lettersOfCredit=[];
  state.contracts[0].paymentCode='ADV_SCAN';
  state.contracts[0].qty=54;
  state.contracts[0].containers=2;
  state.contracts[0].packings[0].containers=2;
  state.shipments[0].lcId='';
  state.shipments[0].plannedQty=54;
  state.shipments[0].containers=2;
  state.shipments[0].production={sentToMill:true,dryOn:'No',craftPaper:'No',dpp:'Yes',inspection:'None',qualityNotes:state.contracts[0].quality};
  state.shipments[0].bagOrders=[{poNo:'PO-TEST',lines:[{packingIndex:0,brand:'BRAND A',tare:80,artworkAttached:true,masterBag:{enabled:false,qty:0,tare:0}}]}];
  await setState(page,state);

  await openShipment(page);
  await page.locator('[data-workspace="loading"]').click();
  await page.locator('[data-li-cont="0"]').fill('1');
  await page.locator('[data-li-cont="0"]').press('Tab');
  await page.locator('#sendLoading').click();

  await openShipment(page);
  await page.locator('[data-workspace="loading"]').click();
  await page.locator('[data-li-name="0"]').fill('External Rice Mill');
  await page.locator('[data-li-type="0"]').selectOption('Ex-Mill');
  await page.locator('[data-li-cont="0"]').fill('1');
  await page.locator('[data-li-cont="0"]').press('Tab');
  await page.locator('#sendLoading').click();

  const result=await page.evaluate(k=>{const st=JSON.parse(localStorage.getItem(k)),s=st.shipments[0];return{lots:s.loading.lots.map(x=>({qty:x.totalMT,location:x.allocations[0].name})),sent:st.millSync.exportLoading.length}},STORE);
  expect(result.lots).toEqual([{qty:27,location:'TTI Rice Mills'},{qty:27,location:'External Rice Mill'}]);
  expect(result.sent).toBe(2);
});

test('tare mismatch stays Contract-controlled and normal non-L/C flow has no L/C leakage',async({page})=>{
  const state=JSON.parse(JSON.stringify(seedState));
  state.lettersOfCredit=[];
  state.contracts[0].paymentCode='ADV_SCAN';
  state.shipments[0].lcId='';
  state.shipments[0].bagOrders=[{poNo:'PO-TEST',lines:[{packingIndex:0,brand:'BRAND A',tare:90,artworkAttached:true,masterBag:{enabled:false,qty:0,tare:0}}]}];
  state.shipments[0].millActuals=[{number:'ABCD123456-7',seal:'SEAL-001',brand:'BRAND A',packSize:25,unit:'KG',bags:1080,netKg:27000,location:'TTI Rice Mills',truck:'TUE-138',gatePass:'GP-001',loadedDate:'2026-09-09',source:'Milling'}];
  await setState(page,state);
  await openShipment(page);
  await page.locator('[data-workspace="customs"]').click();
  await expect(page.locator('#workspaceDetail')).toContainText('DIFFERENT — CONTRACT WINS');
  await expect(page.locator('[data-cu-tare="0"]')).toHaveValue('80');
  await expect(page.locator('#workspaceDetail')).not.toContainText('Accepted L/C:');
  await page.locator('[data-workspace="cover"]').click();
  await expect(page.locator('#printExchange')).toHaveCount(0);
});
