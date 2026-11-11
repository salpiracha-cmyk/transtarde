const {chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({headless:true});
 try {
  const page=await browser.newPage();
  await page.setContent('<!doctype html><p>Browser rendering ready</p>');
  if(await page.locator('p').textContent()!=='Browser rendering ready')throw Error('Rendering failed');
  if(!(await page.screenshot()).length)throw Error('Screenshot failed');
  console.log('BROWSER_TEST_RUNTIME_READY');
 } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
