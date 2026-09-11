<?php
declare(strict_types=1);
/* Transtrade 2026-09-09 release wrapper. The stable module.php remains intact. */
ob_start();
require __DIR__ . '/module.php';
$html=(string)ob_get_clean();
$id=strtolower((string)($_GET['id']??''));

function tt_release_replace(string $html,string $old,string $new,string $label): string {
    if(!str_contains($html,$old)){error_log('Transtrade release patch not matched: '.$label);return$html;}
    return str_replace($old,$new,$html);
}

if($id==='exports'){
    $html=tt_release_replace($html,"if(!c.received)return alert('Mark the signed Sales Contract received before issuing the Bag Order.');",'', 'Bag Order signed-contract gate');
    $html=tt_release_replace($html,
        "function processStatus(p){if(!contractByRef(p.contractRef)?.received)return'Waiting for signed contract';if(!p.bagOrders.length)return'Bag artwork / Bag Order pending';if(!p.production.sentToMill)return'Production Instructions pending';",
        "function processStatus(p){if(!p.bagOrders.length)return'Bag Order pending';if(!contractByRef(p.contractRef)?.received)return'Signed contract required before Production';if(!p.production.sentToMill)return'Production Instructions pending';",
        'Process stage order');

    $millFn=<<<'JS'
function millLocations(){
 const seen=new Map(),add=(name,type)=>{name=String(name||'').trim();if(!name)return;const k=name.toLowerCase();if(!seen.has(k))seen.set(k,{id:k,name,type:String(type||'Mill')})};
 add('TTI Rice Mills','Own / Default');
 let mills=[];try{mills=JSON.parse(localStorage.getItem(MILL_STORE)||'[]')}catch{};
 if(Array.isArray(mills))for(const m of mills){const name=String(m?.name||'').trim(),type=String(m?.type||'');if(name&&(/tti\s*rice/i.test(name)||/external|reprocess|processing/i.test(type)))add(name,/tti\s*rice/i.test(name)?'Own / Default':'Reprocessing Mill')}
 let sodas=[];try{sodas=JSON.parse(localStorage.getItem('tt35exmill')||'[]')}catch{};
 if(Array.isArray(sodas))for(const x of sodas)if(String(x?.mill||'').trim()&&num(x?.qtyKg)>num(x?.loadedKg))add(x.mill,'Active Ex-Mill SODA');
 for(const v of masterValues('mills')){const name=String(v[0]||'').trim(),type=String(v[2]||'');if(name&&(/mill|reprocess|processing/i.test(type)||/rice\s*mill/i.test(name)))add(name,type||'Mill Master')}
 return [...seen.values()]
}
JS;
    $count=0;$html=preg_replace_callback('~function millLocations\(\)\{.*?return rows\}~s',fn()=>$millFn,$html,1,$count)??$html;if($count!==1)error_log('Transtrade release patch not matched: millLocations');

    $oldDeliver='<div class="field"><label>Deliver To</label><input id="boDeliver" value="${esc(po.deliverTo)}"></div>';
    $newDeliver='<div class="field"><label>Deliver To *</label><select id="boDeliver" required data-required="true">${[...new Set([\'To Be Advised Later\',...millLocations().map(m=>m.name),po.deliverTo].filter(Boolean))].map(name=>`<option ${po.deliverTo===name?\'selected\':\'\'}>${esc(name)}</option>`).join(\'\')}</select><span class="tt-bag-marks-help">TTI Rice Mills, active reprocessing mills and mills with an active Ex-Mill SODA are shown automatically.</span></div>';
    $html=tt_release_replace($html,$oldDeliver,$newDeliver,'Bag Order Deliver To dropdown');
    $html=str_replace('boDeliver.oninput=e=>po.deliverTo=e.target.value','boDeliver.onchange=e=>po.deliverTo=e.target.value',$html);

    $html=str_replace(['GOOD SIDE Artwork','GOOD SIDE artwork','GOOD SIDE bag marking','GOOD SIDE bag markings','GOOD SIDE','Good side artwork'],['BAG MARKS','BAG MARKS','BAG MARKS','BAG MARKS','BAG MARKS','Bag Marks'],$html);

    $html=tt_release_replace($html,'<input data-bo-art="${i}" type="file" accept="image/*">','<input data-bo-art="${i}" type="file" accept=".pdf,.png,.jpg,.jpeg,.webp,application/pdf,image/png,image/jpeg,image/webp" data-required="true"><span class="tt-bag-marks-help">PDF, JPEG, PNG or WebP · max 10 MB</span>','Bag Marks file accept');
    $oldUpload="if(f.size>1536*1024){e.target.value='';return alert('Each bag marking file must be 1.5 MB or smaller.')}if(!f.type.startsWith('image/')){e.target.value='';return alert('Bag marking must be an image.')}";
    $newUpload="if(f.size>10*1024*1024){e.target.value='';return window.TTValidation?.fail([e.target],'BAG MARKS file must be 10 MB or smaller.')}const bagMarksOK=/^(application\\/pdf|image\\/(?:png|jpeg|webp))$/i.test(f.type)||/\\.(pdf|png|jpe?g|webp)$/i.test(f.name);if(!bagMarksOK){e.target.value='';return window.TTValidation?.fail([e.target],'BAG MARKS accepts PDF, JPEG, PNG or WebP files only.')}";
    $html=tt_release_replace($html,$oldUpload,$newUpload,'Bag Marks client file validation');
    $html=str_replace("}catch(err){e.target.value='';alert(err.message)}});", "}catch(err){e.target.value='';window.TTValidation?.fail([e.target],err.message||'BAG MARKS file was not saved. Please retry.')}});",$html);

    $oldImg='<img src="${l.artworkData}" alt="Approved ${esc(l.brand)} bag marking">';
    $newImg='${String(l.artworkDocument?.mime||\'\').toLowerCase()===\'application/pdf\'||/\\.pdf$/i.test(String(l.artworkName||\'\'))?`<div class="approvedPdfMarks"><b>APPROVED BAG MARKS — PDF ATTACHED</b><p>${esc(l.artworkName||\'Bag marks PDF\')}</p></div>`:`<img src="${l.artworkData}" alt="Approved ${esc(l.brand)} BAG MARKS">`}';
    $html=tt_release_replace($html,$oldImg,$newImg,'Bag Marks PDF print handling');
    $html=str_replace("if(line?.artworkData&&!String(line.artworkData).startsWith('data:image/')&&cell&&!cell.querySelector('.artPreview'))", "if(line?.artworkData&&(String(line.artworkDocument?.mime||'').startsWith('image/')||/\\.(png|jpe?g|webp)$/i.test(String(line.artworkName||'')))&&cell&&!cell.querySelector('.artPreview'))",$html);

    $html=str_replace('<label>Customer Name</label><input id="mCustName">','<label>Customer Name *</label><input id="mCustName" required data-required="true">',$html);
    $html=str_replace('<label>Address</label><textarea id="mCustAddress"></textarea>','<label>Address *</label><textarea id="mCustAddress" required data-required="true"></textarea>',$html);
    $html=str_replace('<div class="field"><label>Country</label><input id="mCustCountry"></div>','<div class="field"><label>Country</label><input id="mCustCountry"><label class="tt-contract-toggle"><input id="mCustShowCountry" type="checkbox"> Show Country on Sales Contract</label></div>',$html);
    $html=str_replace('<div class="field"><label>Email</label><input id="mCustEmail" type="email"></div>','<div class="field"><label>Email</label><input id="mCustEmail" type="email"><label class="tt-contract-toggle"><input id="mCustShowEmail" type="checkbox"> Show Email on Sales Contract</label></div>',$html);
    $html=str_replace('<div class="field"><label>Phone</label><input id="mCustPhone"></div>','<div class="field"><label>Phone</label><input id="mCustPhone"><label class="tt-contract-toggle"><input id="mCustShowPhone" type="checkbox"> Show Phone on Sales Contract</label></div><div class="field"><label>VAT / Tax / Registration</label><input id="mCustTax"><label class="tt-contract-toggle"><input id="mCustShowTax" type="checkbox"> Show VAT / Tax on Sales Contract</label></div>',$html);
    $oldCustomer="address,country:document.getElementById('mCustCountry').value.trim(),email:document.getElementById('mCustEmail').value.trim(),phone:document.getElementById('mCustPhone').value.trim(),notifies,";
    $newCustomer="address,country:document.getElementById('mCustCountry').value.trim(),email:document.getElementById('mCustEmail').value.trim(),phone:document.getElementById('mCustPhone').value.trim(),tax:document.getElementById('mCustTax')?.value.trim()||'',showCountry:!!document.getElementById('mCustShowCountry')?.checked,showEmail:!!document.getElementById('mCustShowEmail')?.checked,showPhone:!!document.getElementById('mCustShowPhone')?.checked,showTax:!!document.getElementById('mCustShowTax')?.checked,notifies,";
    $html=tt_release_replace($html,$oldCustomer,$newCustomer,'Customer Contract display flags');
    $oldBuyer="function buyerOf(c){if(c?._documentBuyer)return c._documentBuyer;const cu=customer(c.customerId)||{};return{name:cu.name||'',address:cu.address||'',country:cu.country||''}}";
    $newBuyer="function buyerOf(c){if(c?._documentBuyer)return c._documentBuyer;const cu=customer(c.customerId)||{};return{name:cu.name||'',address:cu.address||'',country:cu.country||'',email:cu.email||'',phone:cu.phone||'',tax:cu.tax||'',showCountry:!!cu.showCountry,showEmail:!!cu.showEmail,showPhone:!!cu.showPhone,showTax:!!cu.showTax}}";
    $html=tt_release_replace($html,$oldBuyer,$newBuyer,'Buyer document context');
    $oldParty='function partyGrid(c){const s=sellerOf(c),b=buyerOf(c);return`<div class="docPartyGrid"><div><div class="docPartyLabel">SELLER / EXPORTER</div><div class="docPartyName">${esc(s.name)}</div><div>${esc(s.address)}</div></div><div><div class="docPartyLabel">BUYER / IMPORTER</div><div class="docPartyName">${esc(b.name)}</div><div>${esc(b.address)}</div></div></div>`}';
    $newParty='function partyGrid(c){const s=sellerOf(c),b=buyerOf(c),extra=[[b.showCountry,b.country],[b.showEmail,b.email],[b.showPhone,b.phone],[b.showTax,b.tax]].filter(x=>x[0]&&x[1]).map(x=>`<div>${esc(x[1])}</div>`).join(\'\');return`<div class="docPartyGrid"><div><div class="docPartyLabel">SELLER / EXPORTER</div><div class="docPartyName">${esc(s.name)}</div><div>${esc(s.address)}</div></div><div><div class="docPartyLabel">BUYER / IMPORTER</div><div class="docPartyName">${esc(b.name)}</div><div>${esc(b.address)}</div>${extra}</div></div>`}';
    $html=tt_release_replace($html,$oldParty,$newParty,'Sales Contract optional customer fields');

    $theme='<link rel="stylesheet" href="/exports/release-theme.css?v=20260909-1">';
    if(str_contains($html,'</head>'))$html=str_replace('</head>',$theme.'</head>',$html);
}

$releaseScripts='<script src="/global-validation.js?v=20260909-1"></script>';
if($id==='exports')$releaseScripts.='<script src="/customer-contract-options.js?v=20260911-reports-1"></script>';
if($id==='milling')$releaseScripts.='<script src="/milling-quality-identity.js?v=20260910-1"></script>';
if(str_contains($html,'</body>'))$html=str_replace('</body>',$releaseScripts.'</body>',$html);else$html.=$releaseScripts;

echo $html;
