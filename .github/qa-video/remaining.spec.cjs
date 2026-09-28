const {test,expect}=require('@playwright/test');
const base='http://127.0.0.1:8765';
async function caption(page,title,detail,ms=1800){await page.evaluate(({title,detail})=>{document.querySelector('#qaVideoCaption')?.remove();const box=document.createElement('aside');box.id='qaVideoCaption';box.style.cssText='position:fixed;z-index:9999999;bottom:14px;left:14px;max-width:800px;background:#12364f;color:white;border:2px solid #63cda0;border-radius:12px;padding:13px 17px;font:18px/1.35 Arial;box-shadow:0 7px 28px #0007';box.innerHTML='<b>'+title+'</b><br><span style="font-size:15px">'+detail+'</span>';document.body.appendChild(box)},{title,detail});await page.waitForTimeout(ms)}
async function login(page){await page.goto(base+'/login.php');await page.locator('[name="username"]').fill('qa.video');await page.locator('[name="password"]').fill(process.env.QA_VIDEO_PASSWORD);await page.locator('button[type="submit"]').click();await expect(page).toHaveURL(/accounts\/index\.php/)}
test('Remaining Accounts: recognized export receipt and print voucher',async({page})=>{
 test.setTimeout(150000);page.setDefaultTimeout(10000);
 await login(page);
 await caption(page,'Remaining suite: export receipt','The invoice candidate and QA bank are isolated fixture records. Amounts and bank advice are illustrative.');
 await page.locator('[data-tt-area="exports"]').click();
 await page.locator('#ttDeskWork .tt-action').filter({hasText:'Bank Receipt / Credit Advice'}).click();
 await expect(page.locator('#ttExportReceiptDialog')).toBeVisible();
 await caption(page,'Choose bank first','The company account comes from the TTI master. Its identity and currency should be visible.');
 const banks=await page.locator('#erPkrBank option').allTextContents();
 expect(banks.some(x=>x.includes('QA Demonstration Bank')||x.includes('QA Video PKR'))).toBeTruthy();
 const bankValue=await page.locator('#erPkrBank option').evaluateAll(xs=>xs.find(x=>x.value)?.value||'');
 await page.locator('#erPkrBank').selectOption(bankValue);
 await page.locator('[data-payer-type="CUSTOMER"]').click();
 await expect(page.locator('#erPayer')).toBeVisible();
 await page.locator('#erPayer').selectOption({label:'Ladoo General Trading LLC'});
 await expect(page.locator('[data-er-item^="INV|"]').first()).toBeVisible();
 await caption(page,'Outstanding invoice','The recognized commercial invoice is selectable as a balance item. An unposted invoice must not appear as receivable.');
 await page.locator('[data-er-item^="INV|"]').first().check();
 await page.locator('[data-er-applied^="INV|"]').first().fill('10000');
 await page.locator('#erBankRef').fill('QA-CREDIT-ADVICE-001');
 await page.locator('#erForeign').fill('10000');
 await page.locator('#erRate').fill('280');
 await page.locator('#erBankCredit').fill('2800000');
 await caption(page,'Credit Advice entry','USD 10,000 at illustrative PKR 280. The debit and credit preview should be visible before posting.');
 await expect(page.locator('#erAccountingRows')).not.toContainText('Enter receipt amounts to see');
 await page.locator('#erPost').click();
 await expect(page.getByText('Print Receipt Voucher')).toBeVisible({timeout:15000});
 await caption(page,'Receipt posted','The system offers a printable receipt voucher and records a remaining outstanding invoice balance.');
 const data=await page.evaluate(async()=>{const r=await fetch('../api/export_receipts.php?entity=TTI');return r.json()});
 expect(data.receipts.some(x=>x.bankAdviceRef==='QA-CREDIT-ADVICE-001')).toBeTruthy();
});
