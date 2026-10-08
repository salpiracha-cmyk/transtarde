<?php
declare(strict_types=1);
require_once __DIR__.'/assets_registry_core.php';
function sac_chart():array {
 $m=json_decode((string)file_get_contents(__DIR__.'/../accounts/accounting_master_v1.json'),true,512,JSON_THROW_ON_ERROR);$out=[];
 foreach(array_merge($m['chart'],$m['peopleSubledgers']??[]) as $r)$out[(string)$r['code']]=$r;foreach($out as $code=>&$a){if(!empty($a['parent'])||($a['level']??'')==='heading')continue;if(ctype_digit((string)$code)){$control=(string)(intdiv((int)$code,100)*100);$heading=(string)(intdiv((int)$code,1000)*1000);if($control!==(string)$code&&isset($out[$control]))$a['parent']=$control;elseif($heading!==(string)$code&&isset($out[$heading]))$a['parent']=$heading;elseif(($a['class']??'')==='Expense')$a['parent']='6000';}}unset($a);return $out;
}
function sac_entity_chart(array $store,string $entity):array {
 $chart=sac_chart();foreach((array)($store['accountHierarchy'][$entity]??[]) as $code=>$parent)if(isset($chart[$code],$chart[$parent]))$chart[$code]['parent']=(string)$parent;return $chart;
}
function sac_descendants(string $head,array $store=[],string $entity=''):array {
 $chart=sac_entity_chart($store,$entity);$codes=[$head];foreach($chart as $code=>$a){$parent=(string)($a['parent']??'');$seen=[];while($parent!==''&&!isset($seen[$parent])){if($parent===$head){$codes[]=(string)$code;break;}$seen[$parent]=true;$parent=(string)($chart[$parent]['parent']??'');}}return $codes;
}
function sac_subtree(array $store,string $entity,string $id):array {
 $accounts=sac_accounts($store,$entity);$ids=[$id];foreach($accounts as $child=>$row){$parent=(string)($row['parentId']??'');$seen=[];while($parent!==''&&!isset($seen[$parent])){if($parent===$id){$ids[]=(string)$child;break;}$seen[$parent]=true;$parent=(string)($accounts[$parent]['parentId']??'');}}return $ids;
}
function sac_permission(array $u,string $icon,string $action):bool {
 if(($u['role']??'')==='Super Admin')return true;$p=$u['permissions']['Accounts']??[];return $p==='all'||(is_array($p)&&(in_array($action,$p,true)||in_array($action,(array)($p[$icon]??[]),true)));
}
function sac_normal(string $v):string {return strtolower((string)preg_replace('/[^\pL\pN]/u','',$v));}
function sac_payees(array $s,string $e,string $kind=''):array {
 $out=[];
 foreach(['Talha','Tayyab','Salman','ARP'] as $name){$id='EXP|'.sac_normal($name);$out[$id]=['id'=>$id,'entity'=>$e,'kind'=>'EXPENSE','name'=>$name,'active'=>true,'configured'=>false];}
 $seedFile=__DIR__.'/../accounts/salary_master_seed_v1.json';$seed=is_file($seedFile)?(array)(json_decode((string)file_get_contents($seedFile),true)['salaryMasters']??[]):[];
 foreach(array_replace($seed,(array)($s['salaryMasters']??[])) as $r)if(is_array($r)&&($r['entity']??'')===$e&&($r['status']??'Active')==='Active'){$name=trim((string)($r['name']??''));if($name!==''){$id='EXP|'.sac_normal($name);$out[$id]=['id'=>$id,'entity'=>$e,'kind'=>'EXPENSE','name'=>$name,'active'=>true,'configured'=>false];}}
 foreach((array)(tt_list_masters()['business_parties']??[]) as $r){$v=(array)($r['values']??[]);if(strcasecmp((string)($v[10]??'Active'),'Active')!==0)continue;$name=trim((string)($v[0]??''));if($name==='')continue;$id='PARTY|'.(string)$r['id'];$out[$id]=['id'=>$id,'entity'=>$e,'kind'=>'BUSINESS','name'=>$name,'active'=>true,'configured'=>false,'roles'=>(string)($v[2]??'')];}
 foreach((array)($s['paymentPayees']??[]) as $r)if(is_array($r)&&($r['entity']??'')===$e&&(($r['kind']??'')==='EXPENSE'||isset($out[$r['id']])))$out[$r['id']]=array_replace($r,['configured'=>true],($r['kind']??'')==='BUSINESS'?['name'=>$out[$r['id']]['name']]:[]);
 $out=array_values(array_filter($out,static fn($r)=>($kind===''||$r['kind']===$kind)&&!empty($r['active'])));usort($out,static fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));return $out;
}
function sac_payee(array $s,string $e,string $id):array {
 foreach(sac_payees($s,$e) as $p)if($p['id']===$id){if(empty($p['configured']))throw new DomainException('Set up the recipient account before posting.');return $p;}
 throw new DomainException('Choose an active recipient in these company books.');
}
function sac_save_payee(array &$s,string $e,array $b,array $u):array {
 $kind=(string)($b['kind']??'');if(!in_array($kind,['EXPENSE','BUSINESS'],true))throw new DomainException('Choose Expense Recipient or Business Party.');
 $name=far_text($b['name']??'',180);if($name==='')throw new DomainException('Enter the recipient name.');
 $requested=(string)($b['id']??'');$existing=null;foreach(sac_payees($s,$e,$kind) as $p)if($p['id']===$requested)$existing=$p;
 $id=$kind==='EXPENSE'?($existing['id']??'EXP|'.sac_normal($name)):$requested;$old=null;
 if($kind==='EXPENSE')foreach(sac_payees($s,$e,$kind) as $p)if($p['id']!==$id&&sac_normal($p['name'])===sac_normal($name))throw new DomainException('This recipient already exists. Edit its setup.');
 foreach(sac_payees($s,$e) as $p)if($p['id']===$id)$old=$p;
 if($kind==='BUSINESS'){if(!$old||$old['kind']!=='BUSINESS')throw new DomainException('Add this Business Party in Master Records first.');$name=$old['name'];}
 $code=(string)($b['accountCode']??'');if($kind==='EXPENSE'&&$code==='')$code='6900';$chart=sac_entity_chart($s,$e);$a=$chart[$code]??null;
 if(!$a||in_array($a['level']??'',['heading','system'],true)||in_array($code,['1110','1120','1130','1430','1440','1610','2610'],true))throw new DomainException('Choose the recipient posting account.');
 if($kind==='EXPENSE'&&($a['class']??'')!=='Expense')throw new DomainException('Expense recipients must use an expense or manufacturing cost account.');
 $subId=(string)($b['subaccountId']??'');if($subId!==''){$sub=sac_resolve($s,$e,$subId);if($sub['parentCode']!==$code)throw new DomainException('Choose a subaccount under the selected head.');}
 $category=(string)($b['expenseCategory']??'OFFICE');if(!in_array($category,['HOME','OFFICE','MILL','MEDICAL','RENT','DONATION','OTHER'],true))throw new DomainException('Choose the usual expense type.');
 $record=['id'=>$id,'entity'=>$e,'kind'=>$kind,'name'=>$name,'accountCode'=>$code,'subaccountId'=>$subId,'expenseCategory'=>$category,'active'=>true,'configured'=>true];
 $storeKey=$e.'|'.$id;$s['paymentPayees'][$storeKey]=$record;$s['paymentPayeeAudit'][]=['entity'=>$e,'before'=>$old,'after'=>$record,'at'=>gmdate('c'),'by'=>$u['username']??''];return ['payee'=>$record];
}
function sac_accounts(array $s,string $e):array {
 $out=[];foreach(['FI / Instrument Charges'=>'BANK_INSTRUMENT','Card Charges'=>'BANK_CARD','Transfer Charges'=>'BANK_TRANSFER','Bank Financing Charges'=>'BANK_FINANCING','Bank Finance Markup'=>'FINANCE_MARKUP'] as $name=>$key){$id=$e.'-DEFAULT-'.$key;$out[$id]=['id'=>$id,'entity'=>$e,'name'=>$name,'parentCode'=>'6800','taxCategory'=>'NONE','active'=>true];}
 foreach(['EXPORT','SAVINGS','BROKERAGE','SERVICES'] as $cat)foreach(['RECOVERABLE'=>'1260','PAYABLE'=>'2300'] as $t=>$code){$id=$e.'-DEFAULT-TAX-'.$cat.'-'.$t;$out[$id]=['id'=>$id,'entity'=>$e,'name'=>ucfirst(strtolower($cat)).' Withholding Tax '.ucfirst(strtolower($t)),'parentCode'=>$code,'taxCategory'=>$cat,'active'=>true];}
 foreach((array)($s['accountSubaccounts']??[]) as $id=>$r)if(is_array($r)&&($r['entity']??'')===$e)$out[$id]=$r;return $out;
}
function sac_resolve(array $s,string $e,string $id,bool $active=true):array {
 $accounts=sac_accounts($s,$e);$a=$accounts[$id]??null;if(!$a)throw new DomainException('Select a subaccount in these company books.');
 $current=$a;$seen=[$id=>true];$names=[$a['name']];$ancestors=[];$enabled=!empty($a['active']);
 while(!empty($current['parentId'])){
  $parent=(string)$current['parentId'];if(isset($seen[$parent])||!isset($accounts[$parent]))throw new DomainException('Subaccount hierarchy is unavailable.');
  $seen[$parent]=true;$ancestors[]=$parent;$current=$accounts[$parent];$enabled=$enabled&&!empty($current['active']);array_unshift($names,$current['name']);
 }
 if($active&&!$enabled)throw new DomainException('Select an active subaccount in these company books.');
 $code=(string)$current['parentCode'];$chart=sac_entity_chart($s,$e);$head=$chart[$code]??null;if(!$head)throw new DomainException('Subaccount head is unavailable.');
 $path=$names;$h=$code;$seen=[];while($h!==''&&isset($chart[$h])&&!isset($seen[$h])){$seen[$h]=true;array_unshift($path,$chart[$h]['name']);$h=(string)($chart[$h]['parent']??'');}
 return array_replace($a,['parentId'=>(string)($a['parentId']??''),'parentCode'=>$code,'active'=>$enabled,'ancestorIds'=>$ancestors,'path'=>implode(' / ',$path),'class'=>$head['class'],'parentName'=>$head['name'],'treatment'=>$code==='1260'?'Recoverable tax':($code==='2300'?'Tax payable':$head['class'])]);
}
function sac_extra(array $a):array {return ['subaccountId'=>$a['id'],'subaccountName'=>$a['name'],'subledger'=>$a['name'],'parentAccount'=>$a['parentCode'],'taxCategory'=>$a['taxCategory'],'accountClass'=>$a['class']];}
function sac_master(array &$s,string $e,array $b,array $u):array {
 $op=(string)($b['operation']??'add');$id=(string)($b['id']??'');
 if($op==='move_head'){
  $chart=sac_entity_chart($s,$e);$code=(string)($b['code']??'');$parent=(string)($b['parentCode']??'');$a=$chart[$code]??null;$head=$chart[$parent]??null;
  if(!$a||!$head||in_array($a['level']??'',['heading','system'],true)||($head['level']??'')==='system')throw new DomainException('Select an account and its destination head.');
  $class=static fn($v)=>$v==='Income'?'Revenue':$v;
  if($class($a['class'])!==$class($head['class']))throw new DomainException('Move this account within its accounting class. Use a reviewed journal to change accounting treatment.');
  if(in_array($parent,sac_descendants($code,$s,$e),true))throw new DomainException('An account cannot be moved into itself or its descendants.');
  $before=(string)($a['parent']??'');$s['accountHierarchy'][$e][$code]=$parent;
  $s['subaccountAudit'][]=['entity'=>$e,'operation'=>$op,'code'=>$code,'before'=>$before,'after'=>$parent,'at'=>gmdate('c'),'by'=>$u['username']??''];return ['accountCode'=>$code];
 }
 if(!in_array($op,['add','edit','delete'],true))throw new DomainException('Unknown subaccount action.');
 $old=$op==='add'?null:sac_resolve($s,$e,$id,false);
 if($op==='delete'){$a=sac_accounts($s,$e)[$id];$a['active']=false;}else{
  $name=far_text($b['name']??'',180);if($name==='')throw new DomainException('Enter a subaccount name.');
  $parentId=(string)($b['parentId']??'');$parent=(string)($b['parentCode']??'');$chart=sac_entity_chart($s,$e);
  if($parentId!==''){
   if($id!==''&&in_array($parentId,sac_subtree($s,$e,$id),true))throw new DomainException('A subaccount cannot be moved into itself or its descendants.');
   $destination=sac_resolve($s,$e,$parentId);$parent=$destination['parentCode'];
  }
  $head=$chart[$parent]??null;
  if(!$head||in_array($head['level']??'',['heading','system'],true)||in_array($parent,['1110','1120','1130','1430','1440','1610','2610'],true))throw new DomainException('Select a valid posting head. Bank, investment and finance registers manage their own accounts.');
  $tax=strtoupper((string)($b['taxCategory']??'NONE'));if(!in_array($tax,['NONE','EXPORT','SAVINGS','BROKERAGE','SERVICES','OTHER'],true)||(!in_array($parent,['1260','2300'],true)&&$tax!=='NONE'))throw new DomainException('Tax category applies to recoverable tax or tax payable heads.');
  foreach(sac_accounts($s,$e) as $other)if($other['id']!==$id&&sac_normal($other['name'])===sac_normal($name))throw new DomainException('This subaccount name already exists. Edit or reactivate it.');
  if($op==='add')$id=far_next((array)($s['accountSubaccounts']??[]),'SUB');
  $a=['id'=>$id,'entity'=>$e,'name'=>$name,'parentCode'=>$parent,'parentId'=>$parentId,'taxCategory'=>$tax,'active'=>!array_key_exists('active',$b)||(bool)$b['active']];
  // A subtree moves together. Its rows keep stable IDs and its vouchers keep saved treatment.
  if($old&&$old['parentCode']!==$parent)foreach(sac_subtree($s,$e,$id) as $child){
   $r=sac_accounts($s,$e)[$child];$r['parentCode']=$parent;if(!in_array($parent,['1260','2300'],true))$r['taxCategory']='NONE';$s['accountSubaccounts'][$child]=$r;
  }
 }
 $s['accountSubaccounts'][$id]=$a;$s['subaccountAudit'][]=['id'=>$id,'entity'=>$e,'operation'=>$op,'before'=>$old,'after'=>$a,'at'=>gmdate('c'),'by'=>$u['username']??''];return ['subaccountId'=>$id];
}
function sac_payload(array $s,string $e,string $from='',string $to=''):array {
 $chart=sac_entity_chart($s,$e);$heads=[];$subs=sac_accounts($s,$e);$tax=[];
 foreach($chart as $code=>$r)$heads[(string)$code]=$r+['balance'=>0,'subaccounts'=>[]];
 foreach($subs as $id=>$r){$a=sac_resolve($s,$e,$id,false);$subs[$id]=$a+['balance'=>0];}
 foreach((array)($s['journals']??[]) as $j)if(($j['entity']??'')===$e&&($j['status']??'')==='Posted'&&($to===''||($j['date']??'')<=$to))foreach((array)($j['lines']??[]) as $l){$code=(string)($l['account']??'');$n=round((float)($l['debit']??0)-(float)($l['credit']??0),2);if(isset($heads[$code]))$heads[$code]['balance']+=$n;$id=(string)($l['subaccountId']??'');if(isset($subs[$id]))$subs[$id]['balance']+=$n;
  if(in_array($code,['1260','2300'],true)&&($from===''||($j['date']??'')>=$from)){$cat=$l['taxCategory']??($j['meta']['taxCategory']??(($j['meta']['bankDirectType']??'')==='SAVING_PROFIT'?'SAVINGS':(str_contains($j['sourceType']??'','EXPORT')?'EXPORT':'OTHER')));$k=$code.'|'.$cat;$tax[$k]??=['head'=>$code,'treatment'=>$code==='1260'?'Recoverable':'Payable','category'=>$cat,'debit'=>0,'credit'=>0];$tax[$k]['debit']+=(float)($l['debit']??0);$tax[$k]['credit']+=(float)($l['credit']??0);}
 }
 foreach($heads as &$head)$head['directBalance']=$head['balance'];unset($head);foreach($heads as $code=>$head){$parent=(string)($head['parent']??'');$seen=[];while($parent!==''&&isset($heads[$parent])&&!isset($seen[$parent])){$heads[$parent]['balance']+=$head['directBalance'];$seen[$parent]=true;$parent=(string)($heads[$parent]['parent']??'');}}
 foreach($subs as &$a)$a['directBalance']=$a['balance'];unset($a);
 foreach($subs as $a)foreach($a['ancestorIds'] as $parent)if(isset($subs[$parent]))$subs[$parent]['balance']+=$a['directBalance'];
 foreach($subs as $a)if(empty($a['parentId'])&&isset($heads[$a['parentCode']]))$heads[$a['parentCode']]['subaccounts'][]=$a;
 return ['ok'=>true,'entity'=>$e,'revision'=>(int)($s['revision']??0),'heads'=>array_values($heads),'subaccounts'=>array_values($subs),'taxReport'=>array_values($tax)];
}


/** Expense activity is linked to the recipient, never an artificial receivable. */
function sac_expense_activity(array $s,string $e,string $from,string $to):array {
 $chart=sac_chart();$out=[];
 foreach((array)($s['journals']??[]) as $j){
  if(($j['entity']??'')!==$e||($j['status']??'')!=='Posted'||($j['date']??'')<$from||($j['date']??'')>$to)continue;
  $meta=(array)($j['meta']??[]);$source=(array)($s['generalExpenses'][(string)($meta['generalExpenseId']??'')]??[]);
  foreach((array)($j['lines']??[]) as $l){
   $code=(string)($l['account']??'');if(($chart[$code]['class']??'')!=='Expense'||str_starts_with($code,'5'))continue;
   $id=(string)($l['expenseRecipientId']??$l['paymentPayeeId']??$meta['payeeId']??$source['payeeId']??'');
   $recipient=(string)($s['paymentPayees'][$e.'|'.$id]['name']??$l['counterparty']??$l['party']??$l['person']??$meta['payee']??$meta['beneficiary']??$source['payee']??'');
   $asset=(array)($s['managedAssets'][(string)($l['assetId']??'')]??[]);
   $out[]=['date'=>$j['date'],'voucher'=>$j['id'],'journalId'=>$j['id'],'sourceType'=>$j['sourceType']??'','account'=>$code,'accountName'=>$l['accountName']??$chart[$code]['name'],'recipient'=>$recipient,'recipientId'=>$id,'assetId'=>$l['assetId']??'','assetName'=>$l['assetName']??$asset['name']??'','registrationNo'=>$l['registrationNo']??$asset['registrationNo']??'','purpose'=>$l['expensePurpose']??$j['narration']??'','reference'=>$j['reference']??'','amount'=>round((float)($l['debit']??0)-(float)($l['credit']??0),2),'reversal'=>!empty($j['reversalOf'])];
  }
 }
 return $out;
}
