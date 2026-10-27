(()=>{'use strict';
const home=document.getElementById('directorHome'),grid=home?.querySelector('.homeGrid');if(!grid)return;
const rates=document.createElement('div');home.querySelector('.hero').after(rates);window.TT_DIRECTOR_RATES?.mount(rates,{date:new Date().toLocaleDateString('en-CA',{timeZone:'Asia/Karachi'})});
const settings=document.createElement('details');settings.className='directorSettings';settings.innerHTML='<summary>Settings &amp; annual reviews</summary><div class="homeGrid"></div>';grid.after(settings);const extra=settings.querySelector('.homeGrid');for(const tile of [...grid.children]){const title=tile.querySelector('h3')?.textContent||'';if(['TG Master','Brokerage Master','Freight','Opening Balances','Annual Zakat','Ghati & Stock Reconciliation'].includes(title))extra.append(tile);}
const order=['ARP Morning Overview','Shipment Profit & Loss','Cash Flow','Costing','Other Exporter Sale/Purchase','Stock & Commitments','Assets & Investments'];for(const tile of [...grid.children].sort((a,b)=>order.indexOf(a.querySelector('h3')?.textContent)-order.indexOf(b.querySelector('h3')?.textContent)))grid.append(tile);
// Freight is appended by its own existing renderer; move only that known new tile.
const observer=new MutationObserver(()=>{const freight=grid.querySelector('#openFreightMaster');if(freight)extra.append(freight);});observer.observe(grid,{childList:true});
})();
