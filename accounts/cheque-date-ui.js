(() => {
 'use strict';
 const bound=new WeakMap(),records=new Set();
 const isCheque=input=>/cheque.*date|cheque-date/i.test([input.id,input.name,...input.getAttributeNames()].join(' '));
 function voucherFor(input){
  const selector=input.dataset.voucherDate;if(selector)return document.querySelector(selector);
  let scope=input.parentElement;while(scope&&scope!==document.body){
   const dates=[...scope.querySelectorAll('input[type=date]')].filter(d=>d!==input&&!isCheque(d)&&!d.closest('table'));
   const date=dates.find(d=>d.matches('[data-date],[name=date],[data-payment-date],[data-plan-date]'))||dates.find(d=>/payment|paydate|voucher|receipt|settlement|posting/i.test(d.id+' '+d.name))||dates.find(d=>!/due|birth|effective|statement|invoice|bill|start|end/i.test(d.id+' '+d.name));
   if(date)return date;scope=scope.parentElement;
  }return null;
 }
 function bind(input,voucher=voucherFor(input)){
  if(!input||!voucher||bound.get(input)?.voucher===voucher)return;
  const record={input,voucher,last:voucher.value,edited:!!input.value&&input.value!==voucher.value};
  if(!record.edited)input.value=voucher.value;
 input.removeAttribute('min');input.removeAttribute('max');
  input.addEventListener('input',()=>{record.edited=!!input.value&&input.value!==voucher.value});
  input.addEventListener('change',()=>{record.edited=!!input.value&&input.value!==voucher.value});bound.set(input,record);records.add(record);
 }
 function refresh(root=document){for(const input of root.querySelectorAll('input[type=date]'))if(isCheque(input))bind(input);}
 function sync(voucher){for(const r of records){if(!r.input.isConnected){records.delete(r);continue;}if(r.voucher!==voucher)continue;if(!r.edited||!r.input.value||r.input.value===r.last){r.input.value=voucher.value;r.edited=false;}r.last=voucher.value;}}
 for(const event of ['input','change'])document.addEventListener(event,e=>{if(e.target.matches?.('input[type=date]'))sync(e.target)},true);
 new MutationObserver(()=>refresh()).observe(document.body,{childList:true,subtree:true});
 window.TT_CHEQUE_DATES={bind,refresh,voucherFor};refresh();
})();
