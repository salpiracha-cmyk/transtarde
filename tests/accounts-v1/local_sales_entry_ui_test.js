'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),{JSDOM}=require('jsdom');
(async()=>{
 const dom=new JSDOM('<header>Mill</header><section id="millLanding"></section><section id="local"></section>',{url:'https://fixture.test/module.php?id=milling&view=local-sales&embedded=1',runScripts:'outside-only'}),w=dom.window,opened=[];
 w.openPanel=id=>opened.push(id);w.eval(fs.readFileSync('milling/local-sales-entry.js','utf8'));
 assert.deepEqual(opened,['millLanding']);w.openPanel('home');assert.equal(opened.at(-1),'local','Choosing a Mill opens its canonical Local Sales panel');
 w.goHome();assert.equal(opened.at(-1),'millLanding');w.openPanel('local');assert.equal(opened.at(-1),'local');
 assert.equal(w.getComputedStyle(w.document.querySelector('header')).display,'none');w.close();
 const page=fs.readFileSync('accounts/local-sales.php','utf8'),module=fs.readFileSync('module.php','utf8'),desk=fs.readFileSync('accounts/accounts-accounting-desk.js','utf8');
 assert.match(page,/module\.php\?id=milling&amp;view=local-sales/);assert.match(page,/tt_user_can_module_action\(\$user,'Accounts','customer','View'\)/);assert.match(page,/tt_user_can_module_action\(\$user,'Mill','local','View'\)/);
 assert.match(module,/\$localSalesView&&\(!tt_user_can_module_action/);assert.match(desk,/special:'local-sales'/);assert.doesNotMatch(page,/invoice|exportSales|transtrade_export_v3_operational/i);
 const review=new JSDOM('<div id="ws-receivables"><div class="panelHead"></div></div>',{url:'https://fixture.test/accounts/local-sales.php',runScripts:'outside-only'}),v=review.window;
 v.TT_ACCOUNT_ACCESS={localSalesMirror:true,entity:'TTI'};v.localStorage.setItem('tt_accounts_entity','BRM');let url;
 v.fetch=async u=>{url=u;return {ok:true,json:async()=>({ok:true,summary:{exportSales:9000,localSales:100,byProduct:{}},pending:[]})}};
 v.eval(fs.readFileSync('accounts/local-sales-control-ui.js','utf8'));await v.TT_LOCAL_SALES_CONTROL_UI.mount();
 assert.match(url,/entity=TTI/,'Dedicated page is bound to its authorized company, not another tab');assert.doesNotMatch(v.document.body.textContent,/Pakistan Export Sales|Local \/ Export|Sales by Product/);v.close();
 console.log('PASS shared original Mill entry, scoped permissions, Accounts reviews without export clutter, stable company across tabs');
})().catch(e=>{console.error(e);process.exitCode=1;});
