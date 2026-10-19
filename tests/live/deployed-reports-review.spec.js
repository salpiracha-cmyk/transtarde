const {test,expect}=require('@playwright/test');
const BASE=process.env.TRANSTRADE_BASE_URL||'https://app.transtradeinternational.com',USERNAME=process.env.TRANSTRADE_QA_USERNAME,PASSWORD=process.env.TRANSTRADE_QA_PASSWORD;
test('deployed Sales Tax, WHT source reconciliation and product availability are read-only',async({page})=>{
 test.setTimeout(180000);
 expect(USERNAME,'Existing QA username required').toBeTruthy();expect(PASSWORD,'Existing QA password required').toBeTruthy();
 await page.goto(BASE+'/login.php',{waitUntil:'domcontentloaded',timeout:90000});await page.locator('[name=username]').fill(USERNAME);await page.locator('[name=password]').fill(PASSWORD);await page.locator('button[type=submit]').click();await expect(page).toHaveURL(/\/accounts\/(?:index.php)?$/,{timeout:30000});
 const date=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Karachi',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
 const taxResponse=await page.request.get(BASE+'/api/accounts_sales_tax.php?'+new URLSearchParams({entity:'TTI',from:'2026-09-01',to:date}));expect(taxResponse.status(),'Deployed Sales Tax source must load').toBe(200);const tax=await taxResponse.json();expect(tax.ok).toBe(true);expect(Array.isArray(tax.rows)).toBe(true);expect(new Set(tax.rows.map(r=>r.shipmentId)).size,'Preparation/lot duplication').toBe(tax.rows.length);
 for(const row of tax.rows){expect(row.date>='2026-09-01'&&row.date<=date,'Report cutoff').toBe(true);const ids=(row.documents||[]).map(d=>d.id);expect(new Set(ids).size,'Duplicate original file ID').toBe(ids.length);}
 const archives=tax.rows.flatMap(row=>row.documents||[]).filter(doc=>doc.category==='shipment-archive');
 for(const doc of archives){expect(doc.id).toMatch(/^archive:[a-f0-9]{32}$/);expect(doc.name).toContain('Archived shipment document pack');expect(doc.downloadUrl).toMatch(/^\/api\/shipment_archive_file\.php\?id=[a-f0-9]{32}$/);}
 let archiveRead='no production archive';
 if(archives.length){const response=await page.request.get(BASE+archives[0].downloadUrl);expect([200,404,409],'Archive must download or explicitly identify unavailable original bytes').toContain(response.status());archiveRead=response.status();if(response.status()===200){expect(response.headers()['content-type']).toContain('application/pdf');expect((await response.body()).subarray(0,4).toString()).toBe('%PDF');}}
 console.log('LIVE_ARCHIVE_READ_OK '+JSON.stringify({listedPacks:archives.length,downloadResult:archiveRead,sourceWrites:0}));
 const whtResponse=await page.request.get(BASE+'/api/accounts_subaccounts.php?'+new URLSearchParams({entity:'TTI',from:date.slice(0,4)+'-01-01',to:date,report:'withholding'}));expect(whtResponse.status(),'Deployed WHT source must load').toBe(200);const wht=await whtResponse.json();expect(wht.ok).toBe(true);expect(Array.isArray(wht.taxEntries)).toBe(true);expect(wht.taxFilters.to).toBe(date);
 const totals=new Map();for(const row of wht.taxEntries){expect(row.entity).toBe('TTI');expect(row.date>=date.slice(0,4)+'-01-01'&&row.date<=date).toBe(true);const key=row.head+'|'+row.category,amount=totals.get(key)||{debit:0,credit:0};amount.debit+=row.debit;amount.credit+=row.credit;totals.set(key,amount);}
 for(const row of wht.taxReport){const total=totals.get(row.head+'|'+row.category);expect(!!total,'Summary source lines missing').toBe(true);expect(Math.abs(total.debit-row.debit)<0.005&&Math.abs(total.credit-row.credit)<0.005,'WHT summary/source mismatch').toBe(true);}
 const normal=await page.request.get(BASE+'/api/accounts_subaccounts.php?entity=TTI');expect(normal.status()).toBe(200);expect(Object.hasOwn(await normal.json(),'taxEntries'),'Master response must omit transaction narratives').toBe(false);
 await page.goto(BASE+'/index.php?view=masters&from=accounts',{waitUntil:'domcontentloaded'});await page.locator('[data-master=products]').click();await expect(page.locator('#masterTitle')).toContainText('Export Quality');const statuses=page.locator('#masterTableBody .tag');await expect(statuses.first()).toBeVisible();for(const label of await statuses.allTextContents())expect(['Active','Deactivated'].includes(label.trim()),'Product Status contains profile wording').toBe(true);
 console.log('PASS deployed report review '+JSON.stringify({salesTaxRows:tax.rows.length,whtSourceLines:wht.taxEntries.length,productStatuses:await statuses.count(),reportRequests:'GET only'}));
});

test('shipment contribution retains posted evidence and unavailable costs without writes',async({page,context})=>{
 test.setTimeout(180000);const attemptedWrites=[];
 await context.route('**/*',route=>{
  const request=route.request(),url=new URL(request.url());
  if(!['GET','HEAD'].includes(request.method())&&!(url.origin===BASE&&url.pathname==='/login.php'&&request.method()==='POST')){
   if(url.pathname!=='/api/session_activity.php')attemptedWrites.push(url.pathname);
   return route.abort();
  }return route.continue();
 });
 await page.goto(BASE+'/login.php',{waitUntil:'domcontentloaded',timeout:90000});await page.locator('[name=username]').fill(USERNAME);await page.locator('[name=password]').fill(PASSWORD);await page.locator('button[type=submit]').click();await expect(page).toHaveURL(/\/accounts\/(?:index.php)?$/,{timeout:30000});
 const counts={companies:0,rows:0,heldSources:0,unavailableRows:0,historicalOpeningRows:0,historicalOpenings:0};let current;
 for(const entity of ['TTI','BRM','TG']){
  const response=await page.request.get(BASE+'/api/shipment_profitability.php?entity='+entity);expect(response.status()).toBe(200);const result=await response.json();expect(result.ok).toBe(true);expect(Array.isArray(result.sourceHolds)).toBe(true);
  counts.companies++;counts.rows+=result.rows.length;counts.heldSources+=result.sourceHolds.length;if(entity==='TTI')current=result;
  for(const row of result.rows){
   if(row.historicalOpeningCount){counts.historicalOpeningRows++;counts.historicalOpenings+=row.historicalOpeningCount;expect(row.totalCost).toBeNull();expect(row.grossContribution).toBeNull();}
   for(const field of ['revenue','stockCost'])if(row[field]!==null){expect(Number.isFinite(row[field])).toBe(true);expect(row[field==='revenue'?'revenueVerified':'stockCostVerified']).toBe(true);}
   if(result.sourceHolds.length||row.revenue===null||row.stockCost===null){counts.unavailableRows++;expect(row.totalCost).toBeNull();expect(row.grossContribution).toBeNull();}
   else{const cost=['stockCost','transport','freight','clearing','fumigation','inspection','otherPostedCost'].reduce((sum,key)=>sum+row[key],0);expect(Math.abs(row.totalCost-cost)).toBeLessThan(.005);expect(Math.abs(row.grossContribution-row.revenue+row.totalCost)).toBeLessThan(.005);}
  }
 }
 await page.evaluate(()=>localStorage.setItem('tt_accounts_entity','TTI'));await page.waitForFunction(()=>typeof window.TT_REPORTS?.open==='function');await page.evaluate(()=>window.TT_REPORTS.open('shipment'));await expect(page.locator('#ttReportsPanel h2')).toContainText('Shipment Contribution');
 if(current.rows.some(row=>row.historicalOpeningCount)&&!current.sourceHolds.length)await expect(page.locator('#ttReportsBody')).toContainText('Historical opening balance');
 if(current.sourceHolds.length)await expect(page.locator('#ttReportsBody [role=alert]')).toBeVisible();
 if(current.rows.some(row=>row.revenue===null||row.stockCost===null))await expect(page.locator('#ttReportsBody')).toContainText('Unavailable');
 if(current.rows.some(row=>row.totalCost===null))await expect(page.locator('#ttReportsBody')).toContainText('Review required');
 expect(attemptedWrites).toEqual([]);console.log('LIVE_SHIPMENT_EVIDENCE_OK '+JSON.stringify({...counts,financialWrites:0}));
});

test('annual Zakat control is available read-only and preserves company journals',async({page,context})=>{
 test.setTimeout(180000);const attemptedWrites=[];
 await context.route('**/*',route=>{const r=route.request(),url=new URL(r.url());if(!['GET','HEAD'].includes(r.method())&&!(url.origin===BASE&&url.pathname==='/login.php'&&r.method()==='POST')){if(url.pathname!=='/api/session_activity.php')attemptedWrites.push(url.pathname);return route.abort();}return route.continue();});
 await page.goto(BASE+'/login.php',{waitUntil:'domcontentloaded',timeout:90000});await page.locator('[name=username]').fill(USERNAME);await page.locator('[name=password]').fill(PASSWORD);await page.locator('button[type=submit]').click();await expect(page).toHaveURL(/\/accounts\/(?:index.php)?$/,{timeout:30000});
 expect(await page.evaluate(()=>window.TT_ACCOUNT_ACCESS.canGroupZakat),'Existing read-only group permission exposes Annual Zakat').toBe(true);
 await page.goto(BASE+'/accounts/group-management.php?kind=ZAKAT',{waitUntil:'domcontentloaded'});await expect(page.locator('#gmSummary')).toContainText('TTI Annual Zakat Control');await expect(page.locator('#gmSummary')).toContainText('Actual payments');await expect(page.locator('#gmSave')).toBeDisabled();expect(await page.locator('#gmKind option').allTextContents()).toHaveLength(1);
 expect(attemptedWrites).toEqual([]);console.log('LIVE_ANNUAL_ZAKAT_CONTROL_READ_ONLY_OK '+JSON.stringify({financialWrites:0,stockWrites:0,assessmentWrites:0}));
});
