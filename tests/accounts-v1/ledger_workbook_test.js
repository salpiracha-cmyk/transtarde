'use strict';
const fs=require('node:fs'),vm=require('node:vm'),{execFileSync}=require('node:child_process'),os=require('node:os'),path=require('node:path');
const context={window:{},document:{querySelector:()=>null},localStorage:{getItem:()=>null},Intl,TextEncoder,Uint8Array,DataView,Blob};vm.createContext(context);vm.runInContext(fs.readFileSync('accounts/all-ledgers-ui.js','utf8'),context);
(async()=>{const file=path.join(os.tmpdir(),'ledger-'+process.pid+'.xlsx');try{const blob=context.window.TT_ALL_LEDGERS.ledgerWorkbook([['Company','TTI'],['Narration','=SUM(A1:A2)'],['Debit',123.45],['Unicode','AED · المورد']]);fs.writeFileSync(file,Buffer.from(await blob.arrayBuffer()));execFileSync('python3',['-c',`import sys,zipfile,xml.etree.ElementTree as ET
with zipfile.ZipFile(sys.argv[1]) as z:
 assert z.testzip() is None
 for name in z.namelist(): ET.fromstring(z.read(name))
 root=ET.fromstring(z.read('xl/worksheets/sheet1.xml'))
 ns={'m':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
 assert root.find('.//m:f',ns) is None
 assert '=SUM(A1:A2)' in z.read('xl/worksheets/sheet1.xml').decode()
 assert root.find('.//m:c[@r="B3"]/m:v',ns).text=='123.45'
`,file]);console.log('Real XLSX archive, XML, numeric amounts and formula-safe narration passed');}finally{fs.rmSync(file,{force:true})}})().catch(e=>{console.error(e);process.exit(1)});
