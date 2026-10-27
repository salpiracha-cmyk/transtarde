/* Read-only Rice Ledger; all quantities come from the canonical server stock calculation. */
(function(){
 'use strict';
 let sequence=0,committed=null;
 const el=id=>document.getElementById(id),esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const displayDate=v=>window.TT_DATE?.display(v)||v;
 const num=v=>Number(v||0).toLocaleString(undefined,{maximumFractionDigits:3});
 function filters(){return {entity:stockEntityForView(),mill:currentMill?.name||'',from:el('riceLedgerMode').value==='range'?el('ledgerFrom').value:'',to:el('ledgerTo').value,product:el('riceLedgerProduct').value,movement:el('riceLedgerMovement').value};}
 const key=f=>JSON.stringify(f);
 function table(d){
  let out='<p>'+esc(d.scope.entity)+' · '+esc(d.scope.millName)+' · '+esc(displayDate(d.filters.from)||'Beginning')+' to '+esc(displayDate(d.filters.to)||'Latest')+' · '+esc(d.filters.movement)+' · '+esc(d.filters.product||'All products')+'</p><p>'+esc(d.note)+'</p>';
  out+='<table><thead><tr><th>Product</th><th>Opening Kg</th><th>Inward Kg</th><th>Outward Kg</th><th>Closing Kg</th><th>Status</th></tr></thead><tbody>';
  out+=d.summaries.map(s=>'<tr>'+[s.product,num(s.openingKg),num(s.inwardKg),num(s.outwardKg),num(s.closingKg),s.status].map(v=>'<td>'+esc(v)+'</td>').join('')+'</tr>').join('')||'<tr><td colspan="6">No stock movements recorded for this scope.</td></tr>';
  out+='</tbody></table><h4>Dated movements</h4><table><thead><tr><th>Date</th><th>Product</th><th>Movement</th><th>Inward Kg</th><th>Outward Kg</th><th>Product Balance Kg</th><th>Source / Basis</th></tr></thead><tbody>';
  out+=d.rows.map(r=>'<tr>'+[displayDate(r.date),r.product,r.kind,r.kg>0?num(r.kg):'—',r.kg<0?num(-r.kg):'—',num(r.balanceKg),r.source+' / '+r.sourceId+(r.part?' / part '+r.part:'')+' · '+r.basis+' · '+r.status].map(v=>'<td>'+esc(v)+'</td>').join('')+'</tr>').join('')||'<tr><td colspan="7">No dated movements match the filters.</td></tr>';
  out+='</tbody></table>';
  if(d.undated.length)out+='<h4>Undated history — excluded from dated balances</h4><table><thead><tr><th>Product</th><th>Movement</th><th>Signed Kg</th><th>Source</th><th>Basis</th></tr></thead><tbody>'+d.undated.map(r=>'<tr>'+[r.product,r.kind,num(r.kg),r.source+' / '+r.sourceId+(r.part?' / part '+r.part:''),r.basis].map(v=>'<td>'+esc(v)+'</td>').join('')+'</tr>').join('')+'</tbody></table>';
  return out;
 }
 async function load(){
  const root=el('riceLedger');if(!root||root.classList.contains('hidden'))return;
  const f=filters(),requestKey=key(f),token=++sequence;committed=null;el('riceLedgerOutput').textContent='Loading committed stock movements…';
  try{
   if(!f.mill)throw Error('Choose a mill first.');if(f.from&&f.to&&f.from>f.to)throw Error('From date cannot follow To date.');
   const response=await fetch('/api/rice_ledger.php?'+new URLSearchParams(f),{credentials:'same-origin',cache:'no-store'}),d=await response.json();
   if(token!==sequence||requestKey!==key(filters())||root.classList.contains('hidden'))return;
   if(!response.ok||!d.ok)throw Error(d.error||'Rice Ledger could not be loaded.');
   const select=el('riceLedgerProduct');select.replaceChildren(new Option('All products',''),...d.products.map(p=>new Option(p,p)));if(f.product&&!d.products.includes(f.product))select.add(new Option(f.product,f.product));select.value=f.product;
   committed={key:requestKey,data:d};el('riceLedgerOutput').innerHTML=table(d);
  }catch(e){if(token===sequence&&requestKey===key(filters()))el('riceLedgerOutput').textContent=e.message||'Stock source could not be read. No balance has been estimated.';}
 }
 function snapshot(){if(!committed||committed.key!==key(filters())){alert('Generate the report for the current filters before printing or exporting.');return null;}return committed.data;}
 function print(){const d=snapshot();if(!d)return;const win=window.open('','_blank');if(!win){alert('Allow the report print window and try again.');return;}win.document.write('<!doctype html><html><head><title data-tt-filename>'+esc(window.TT_DOCUMENT_NAME?.name({party:d.scope.millName,company:d.scope.entity,title:'Rice Ledger',from:d.filters.from||'Beginning',to:d.filters.to||'Latest',reference:d.filters.product})||'Rice Ledger')+'</title><style>body{font:12px Arial}table{border-collapse:collapse;width:100%;margin-bottom:18px}th,td{border:1px solid #aaa;padding:5px;text-align:left}thead{display:table-header-group}@page{size:A4 landscape;margin:12mm}</style></head><body><h2>Rice Ledger</h2>'+table(d)+'</body></html>');win.document.close();win.focus();win.print();}
 function csv(){const d=snapshot();if(!d)return;const rows=[['Rice Ledger',d.scope.entity,d.scope.millName,d.filters.from||'Beginning',d.filters.to||'Latest',d.filters.product||'All products',d.filters.movement],['Note',d.note],['Product','Opening Kg','Inward Kg','Outward Kg','Closing Kg','Status'],...d.summaries.map(s=>[s.product,s.openingKg,s.inwardKg,s.outwardKg,s.closingKg,s.status]),[],['Date','Product','Movement','Signed Kg','Product Balance Kg','Source','Source ID','Part','Basis','Status'],...d.rows.map(r=>[r.date,r.product,r.kind,r.kg,r.balanceKg,r.source,r.sourceId,r.part||'',r.basis,r.status]),[],['Undated history — excluded from dated balances'],...d.undated.map(r=>['Undated',r.product,r.kind,r.kg,'',r.source,r.sourceId,r.part||'',r.basis,'Unverified'])];const quote=v=>'"'+String(typeof v==='number'?v:(/^[=+\-@]/.test(String(v))?"'"+v:v)).replace(/"/g,'""')+'"';const url=URL.createObjectURL(new Blob(['\ufeff'+rows.map(r=>r.map(quote).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'}));const a=document.createElement('a');a.href=url;a.download=(window.TT_DOCUMENT_NAME?.name({party:d.scope.millName,company:d.scope.entity,title:'Rice Ledger',from:d.filters.from||'Beginning',to:d.filters.to||'Latest',reference:d.filters.product,extension:'csv'})||('Rice-Ledger.csv'));a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);}
 window.TT_RICE_LEDGER={load,print,csv};
 document.addEventListener('change',e=>{if(e.target.closest('#riceLedger')){++sequence;committed=null;el('riceLedgerOutput').textContent='Filters changed. Generate Report to load committed stock movements.';}});
})();
