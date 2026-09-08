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
  expect(result.total).toBeGreaterThanOrEqual(19);
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
