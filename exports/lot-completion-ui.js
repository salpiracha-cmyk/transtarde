(()=>{
  'use strict';
  const READY_TEXT='Final set ready';
  let scheduled=false;

  function exactLotLabels(){
    document.querySelectorAll('.contractCard').forEach(card=>{
      const buttons=[...card.querySelectorAll('[data-open-lot]')];
      const refs=buttons.map(btn=>{
        const small=btn.querySelector('small');
        const ref=(btn.dataset.ttLotRef||small?.textContent||'').trim();
        if(!ref)return'';
        btn.dataset.ttLotRef=ref;
        const firstText=[...btn.childNodes].find(n=>n.nodeType===Node.TEXT_NODE);
        if(firstText&&firstText.nodeValue.trim()!==ref)firstText.nodeValue=ref+' ';
        if(small)small.remove();
        return ref;
      });
      const noteLabels=[...card.querySelectorAll('.processNote b')].slice(1);
      noteLabels.forEach((label,i)=>{
        const ref=refs[i];
        if(ref&&label.textContent!==ref+':')label.textContent=ref+':';
      });
    });
  }

  function gateCompletion(){
    const footer=document.querySelector('.lotFooter');
    if(!footer)return;

    const tiles=document.querySelector('.workspaceTiles');
    const outputTile=document.querySelector('[data-workspace="output"]');
    if(tiles&&outputTile&&tiles.lastElementChild!==outputTile)tiles.appendChild(outputTile);

    const head=document.querySelector('.workspaceHead');
    const cancel=footer.querySelector('#cancelLot');
    if(cancel&&head){
      cancel.classList.add('small');
      cancel.title='Cancel this lot with a mandatory reason';
      head.appendChild(cancel);
    }

    const onFinalOutput=!!outputTile?.classList.contains('active');
    const allControlsPassed=outputTile?.querySelector('small')?.textContent.trim()===READY_TEXT;
    footer.classList.toggle('tt-ready-to-close',onFinalOutput&&allControlsPassed);

    const complete=footer.querySelector('#completeLot');
    if(complete){
      complete.textContent='MARK LOT COMPLETE & CLOSE';
      complete.title='Close this lot and remove it from Active Shipments';
    }
    const lotLabel=footer.querySelector('b');
    if(lotLabel&&!lotLabel.dataset.ttFinalLabel){
      lotLabel.textContent='FINAL COMPLETION · '+lotLabel.textContent.trim();
      lotLabel.dataset.ttFinalLabel='1';
    }
  }

  function sync(){
    exactLotLabels();
    gateCompletion();
  }

  const style=document.createElement('style');
  style.textContent=`
    .lotFooter{display:none!important}
    .lotFooter.tt-ready-to-close{display:flex!important;align-items:center}
    .workspaceHead #cancelLot{margin-left:8px}
  `;
  document.head.appendChild(style);

  const observer=new MutationObserver(()=>{
    if(scheduled)return;
    scheduled=true;
    requestAnimationFrame(()=>{scheduled=false;sync()});
  });
  observer.observe(document.documentElement,{subtree:true,childList:true,characterData:true,attributes:true,attributeFilter:['class']});
  document.addEventListener('click',()=>setTimeout(sync,0),true);
  document.addEventListener('change',()=>setTimeout(sync,0),true);
  sync();
})();
