const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');

const root=path.resolve(__dirname,'../..');
const app=fs.readFileSync(path.join(root,'exports/app.js'),'utf8');
const css=fs.readFileSync(path.join(root,'exports/app.css'),'utf8');
const index=fs.readFileSync(path.join(root,'exports/index.html'),'utf8');
const block=(start,end)=>app.slice(app.indexOf(start),app.indexOf(end,app.indexOf(start)));
const dimensions=file=>{const data=fs.readFileSync(file);assert.equal(data.toString('ascii',1,4),'PNG');return[data.readUInt32BE(16),data.readUInt32BE(20)]};

assert.match(index,/app\.js\?v=20260915-commercial-docs-1/);
assert.doesNotMatch(app, /querySelector\('#cuOpen'\)/);
assert.match(app,/querySelector\('#cuOpenAccount'\)/);
assert.match(app,/loadingPorts=TT_PORTS\.slice\(0,2\)/);

const blForm=block('function renderBL(d){','function renderLoading__legacy_v3');
assert.doesNotMatch(blForm,/id="blNo"|id="blDate"|id="blFinalFile"/);
assert.match(blForm,/id="blConsignee"/);
assert.match(blForm,/id="blNotify"/);
assert.match(blForm,/id="blDesc"/);
assert.match(blForm,/blIncludeSpecification/);
assert.match(app,/TO ORDER OF<br>/);
assert.match(app,/FI NO\.:/);
assert.match(app,/FI AMOUNT:/);
assert.match(app,/customsCurrencyAmount\(fiAmount,currency\)/);
assert.match(app,/showBreakdown=fiAmount>\.005&&balance>\.005/);
assert.match(app,/uploadBLNo/);
assert.match(app,/uploadBLDate/);
assert.match(css,/contractSpecificationTable\{width:90%;margin:4px auto/);
assert.doesNotMatch(css,/approvedInvoiceReference>div\{[^}]*border-bottom/);

for(const [name,minWidth] of [['TTI_header.png',1500],['BRM_header.png',1500],['TG_header.png',1000]]){
 const [width]=dimensions(path.join(root,'exports/assets',name));assert.ok(width>=minWidth,`${name} is not the approved high-resolution asset`)
}

console.log('PASS Exports SOL 4 handover audit: Customs balance, FI output, B/L draft, page layout and approved assets');
