const fs=require('node:fs'),assert=require('node:assert/strict');
const {JSDOM}=require('jsdom');
(async()=>{
  const dom=new JSDOM('<main><div><label>Date</label><input id="original" type="date" value="2026-10-16"></div></main>',{runScripts:'outside-only',url:'https://fixture.test'});
  const w=dom.window;
  const theme=fs.readFileSync('brand-theme.js','utf8');
  w.eval(theme.slice(theme.indexOf('/* Owner date rule:')));
  await new Promise(resolve=>setTimeout(resolve,10));
  const html=fs.readFileSync('milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html','utf8');
  const source=html.split('\n').find(line=>line.startsWith('function setTodayTextDefaults()'));
  w.today=()=> '2026-10-16';w.eval(source);
  // This is the real fallback bag-receipt form: its first nested label is Date.
  // Scanning every descendant previously turned sibling checkboxes into dates.
  const receipt=html.match(/<div id="bagReceiveCard"[\s\S]*?(?=<div id="bagTransferCard"|<\/section>)/)?.[0];
  assert.ok(receipt,'Native bag-receipt form must be present');
  w.document.body.insertAdjacentHTML('beforeend',receipt);
  const inspected=w.document.getElementById('bagInspected'),keepOpen=w.document.getElementById('bagKeepReceiptOpen');
  inspected.checked=true;keepOpen.checked=true;
  const native=w.document.getElementById('original');
  const visible=w.document.querySelector('.tt-date-control input[aria-label]');
  assert.equal(visible.type,'text');
  w.setTodayTextDefaults();
  assert.equal(inspected.type,'checkbox','Panel date defaults must preserve the inspection checkbox');
  assert.equal(keepOpen.type,'checkbox','Panel date defaults must preserve the PO-open checkbox');
  assert.equal(inspected.checked,true);assert.equal(keepOpen.checked,true);
  assert.equal(visible.type,'text','Opening a Mill panel must not convert the masked control into a native date');
  visible.value='23-09-2026';visible.dispatchEvent(new w.Event('input',{bubbles:true}));
  assert.equal(native.value,'2026-09-23','Typed displayed date must reach the stored ISO field');
  w.setTodayTextDefaults();
  assert.equal(visible.value,'23-09-2026','Reopening must preserve the chosen date');
  assert.equal(native.value,'2026-09-23');
  await new Promise(resolve=>setTimeout(resolve,10));
  w.close();console.log('PASS Mill panel defaults preserve masked dates, selected ISO date and native receipt checkboxes');
})().catch(error=>{console.error(error);process.exitCode=1;});
