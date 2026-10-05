const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {chromium}=require('playwright');

const root=path.resolve(__dirname,'../..');
const source=fs.readFileSync(path.join(root,'accounts/internal-bank-transfers-ui.js'),'utf8');
const browserOptions={headless:true,...(process.env.TT_PLAYWRIGHT_EXECUTABLE?{executablePath:process.env.TT_PLAYWRIGHT_EXECUTABLE}:{})};

(async()=>{
  const browser=await chromium.launch(browserOptions);
  try{
    const page=await browser.newPage();
    const errors=[];page.on('pageerror',error=>errors.push(error.message));
    let posted=null;
    await page.route('https://transfer.test/**',route=>{
      if(!route.request().url().includes('/api/internal_bank_transfers.php'))return route.fulfill({body:'<!doctype html><html><body></body></html>',contentType:'text/html'});
      if(route.request().method()==='POST'){
        posted=route.request().postDataJSON();
        return route.fulfill({json:{ok:true,transfer:{entity:'BRM',destinationEntity:'TTI',currency:'PKR',reference:'BRM-X-1',narration:'Advance'},journal:{id:'POST-2026-00001',entity:'BRM',date:'2026-10-01'},mirrorJournal:{id:'POST-2026-00002',entity:'TTI',date:'2026-10-01'}}});
      }
      return route.fulfill({json:{ok:true,entity:'BRM',sources:[{id:'brm-bank',type:'Company Account',entity:'BRM',bank:'Buksh Bank',title:'Buksh',number:'123',currency:'PKR',balance:{native:500,carryingRate:1}}],destinations:[{id:'tti-owner',type:'Proprietor / Owner Account',entity:'TTI',owner:'Abdul Razzak Paracha',bank:'Owner Bank',title:'Abdul Razzak Paracha',number:'',iban:'PK00OWNER',currency:'PKR'}],history:[]}});
    });
    await page.goto('https://transfer.test/');
    await page.evaluate(()=>{
      localStorage.setItem('tt_accounts_entity','BRM');
      window.TT_ACCOUNT_ACCESS={csrf:'fixture'};
      window.TT_BANK_PAYMENT_DETAILS={mount(){},read(){return {bankPaymentMethod:'ONLINE_BANKING',bankReference:'BRM-X-1'}}};
      window.TT_BANK_ACCOUNTS_UI={reload(){}};
    });
    await page.addScriptTag({content:source});
    await page.evaluate(()=>window.TT_INTERNAL_BANK_TRANSFERS_UI.open());
    await page.locator('#ibtSource').selectOption('brm-bank');
    assert.match(await page.locator('#ibtDestination').innerText(),/PK00OWNER · TTI/);
    await page.locator('#ibtDestination').selectOption('tti-owner');
    await page.locator('#ibtAmount').fill('100');
    await page.locator('#ibtNarration').fill('Advance');
    assert.match(await page.locator('#ibtPreview').innerText(),/matching bank receipt and payable in TTI/);
    page.once('dialog',dialog=>{assert.match(dialog.message(),/both company books/);dialog.accept()});
    await page.locator('#ibtPost').click();
    assert.equal(posted?.sourceBankId,'brm-bank');
    assert.equal(posted?.destinationBankId,'tti-owner');
    assert.match(await page.locator('#ibtDialog').innerText(),/POST-2026-00001/);
    assert.match(await page.locator('#ibtDialog').innerText(),/POST-2026-00002/);
    assert.equal(await page.locator('#ibtPrintMirror').count(),1);
    assert.deepEqual(errors,[]);
    console.log('PASS BRM-to-TTI linked personal destination and mirrored posting UI');
  }finally{await browser.close()}
})().catch(error=>{console.error(error);process.exitCode=1});
