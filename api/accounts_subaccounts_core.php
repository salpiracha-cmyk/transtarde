<?php
declare(strict_types=1);
require_once __DIR__.'/accounts_reference.php';
require_once __DIR__.'/assets_registry_core.php';
require_once __DIR__.'/accounts_ledger_visibility.php';
function sac_chart():array {
 $m=json_decode((string)file_get_contents(__DIR__.'/../accounts/accounting_master_v1.json'),true,512,JSON_THROW_ON_ERROR);$out=[];
 foreach(array_merge($m['chart'],$m['peopleSubledgers']??[]) as $r)$out[(string)$r['code']]=$r;
 // Include posted policy heads without overriding an existing chart identity (e.g. donations 7200).
 foreach(['settlement_policy_v1.json','export_realization_policy_v1.json'] as $file){$path=__DIR__.'/../accounts/'.$file;if(!is_file($path))continue;$policy=json_decode((string)file_get_contents($path),true);foreach((array)($policy['accounts']??[]) as $r)if(is_array($r)&&isset($r['code'])&&!isset($out[(string)$r['code']]))$out[(string)$r['code']]=$r;}
 foreach($out as $code=>&$a){if(!empty($a['parent'])||($a['level']??'')==='heading')continue;if(ctype_digit((string)$code)){$control=(string)(intdiv((int)$code,100)*100);$heading=(string)(intdiv((int)$code,1000)*1000);if($control!==(string)$code&&isset($out[$control]))$a['parent']=$control;elseif($heading!==(string)$code&&isset($out[$heading]))$a['parent']=$heading;elseif(($a['class']??'')==='Expense')$a['parent']='6000';}}unset($a);return $out;
}
function sac_entity_chart(array $store,string $entity):array {
 $chart=sac_chart();foreach((array)($store['accountHierarchy'][$entity]??[]) as $code=>$parent)if(isset($chart[$code],$chart[$parent]))$chart[$code]['parent']=(string)$parent;if($entity==='TG'){$used=[];foreach((array)($store['journals']??[]) as $j)if(($j['entity']??'')==='TG')foreach((array)($j['lines']??[]) as $l)$used[(string)($l['account']??'')]=true;foreach($chart as $code=>$row)if(((string)$code==='6910'||str_starts_with((string)$code,'FAM-')||str_starts_with((string)$code,'REIMB-'))&&!isset($used[$code]))unset($chart[$code]);}return $chart;
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
/** Legacy Pakistan parties stay available there; TG requires explicit setup or its own history. */
function sac_party_company_names(array $s,string $e):array {
 $names=[];
 foreach((array)($s['journals']??[]) as $j)if(($j['entity']??'')===$e&&($j['status']??'')==='Posted')foreach((array)($j['lines']??[]) as $line)foreach(['counterparty','party','supplier','vendor','customer','broker'] as $field)if(!empty($line[$field]))$names[sac_normal((string)$line[$field])]=true;
 foreach((array)($s['paymentPayees']??[]) as $p)if(($p['entity']??'')===$e&&!empty($p['active'])&&!empty($p['name']))$names[sac_normal((string)$p['name'])]=true;
 return $names;
}
function sac_party_company_allowed(array $row,string $e,array $used):bool {
 $v=(array)($row['values']??[]);$profile=json_decode((string)($v[12]??'{}'),true);
 if(is_array($profile)&&isset($profile['accountingCompanies'])&&is_array($profile['accountingCompanies']))return in_array($e,$profile['accountingCompanies'],true);
 return $e!=='TG'||isset($used[sac_normal((string)($v[0]??''))]);
}
function sac_payees(array $s,string $e,string $kind=''):array {
 $out=[];$used=sac_party_company_names($s,$e);
 foreach(['Talha','Tayyab','Salman','ARP'] as $name){if($e==='TG'&&!isset($used[sac_normal($name)]))continue;$id='EXP|'.sac_normal($name);$out[$id]=['id'=>$id,'entity'=>$e,'kind'=>'EXPENSE','name'=>$name,'active'=>true,'configured'=>false];}
 $seedFile=__DIR__.'/../accounts/salary_master_seed_v1.json';$seed=is_file($seedFile)?(array)(json_decode((string)file_get_contents($seedFile),true)['salaryMasters']??[]):[];
 foreach(array_replace($seed,(array)($s['salaryMasters']??[])) as $r)if(is_array($r)&&($r['entity']??'')===$e&&($r['status']??'Active')==='Active'){$name=trim((string)($r['name']??''));if($name!==''){$id='EXP|'.sac_normal($name);$out[$id]=['id'=>$id,'entity'=>$e,'kind'=>'EXPENSE','name'=>$name,'active'=>true,'configured'=>false];}}
 foreach((array)(tt_list_masters()['business_parties']??[]) as $r){if(!sac_party_company_allowed($r,$e,$used))continue;$v=(array)($r['values']??[]);if(strcasecmp((string)($v[10]??'Active'),'Active')!==0)continue;$name=trim((string)($v[0]??''));if($name==='')continue;$id='PARTY|'.(string)$r['id'];$out[$id]=['id'=>$id,'entity'=>$e,'kind'=>'BUSINESS','name'=>$name,'active'=>true,'configured'=>false,'roles'=>(string)($v[2]??'')];}
 foreach((array)($s['paymentPayees']??[]) as $r)if(is_array($r)&&($r['entity']??'')===$e&&(($r['kind']??'')==='EXPENSE'||isset($out[$r['id']])))$out[$r['id']]=array_replace($r,['configured'=>true],($r['kind']??'')==='BUSINESS'?['name'=>$out[$r['id']]['name']]:[]);
 $out=array_values(array_filter($out,static fn($r)=>($kind===''||$r['kind']===$kind)&&!empty($r['active'])));usort($out,static fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));return $out;
}
/** Expense forms share canonical service-party identities; bank mappings stay separate. */
function sac_expense_recipients(array $s,string $e):array {
 $out=sac_payees($s,$e,'EXPENSE');$used=sac_party_company_names($s,$e);
 foreach((array)(tt_list_masters()['business_parties']??[]) as $r){
  if(!sac_party_company_allowed($r,$e,$used))continue;
  $v=(array)($r['values']??[]);if(strcasecmp((string)($v[10]??'Active'),'Active')!==0||!tt_business_party_has_category($v[2]??'','Service Provider'))continue;
  $profile=json_decode((string)($v[12]??'{}'),true);$code=(string)($profile['service']['expenseAccountCode']??'');$chart=sac_entity_chart($s,$e);
  if($code===''||($chart[$code]['class']??'')!=='Expense'||in_array($chart[$code]['level']??'',['heading','system'],true))continue;
  $category='OTHER';foreach(sac_expense_defaults() as $key=>$default)if($default[1]===$code){$category=$key;break;}
  $out[]=['id'=>'PARTY|'.$r['id'],'entity'=>$e,'kind'=>'BUSINESS','name'=>(string)$v[0],'serviceProvider'=>true,'accountCode'=>$code,'expenseCategory'=>$category,'active'=>true,'configured'=>true];
 }
 usort($out,static fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));return $out;
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
function sac_expense_defaults():array {
 return ['HOME'=>['Home Expense','6910'],'OFFICE'=>['Office / Administrative Expense','6900'],'CAR_REPAIRS'=>['Car Repairs','6410'],'FUEL'=>['Vehicle Fuel','6610'],'VEHICLE_TAX'=>['Vehicle Tax & Licence Fees','6620'],'REPAIRS'=>['Repairs & Maintenance','6400'],'TRAVEL'=>['Travel & Conveyance','6500'],'PROFESSIONAL'=>['Professional Fees','6700'],'MEDICAL'=>['Medical','6230'],'RENT'=>['Rent','6300'],'DONATION'=>['Donation','7200'],'EXPORT'=>['General Export Expense','5550'],'OTHER'=>['Other Expense','6900'],'ARP'=>['ARP Expense','6910']];
}
function sac_home_person(string $name):string {
 return match(sac_normal($name)){'mrssrp'=>'FAM-SALMAN','mrstrp'=>'FAM-TALHA','mrstayyab'=>'FAM-TAYYAB',default=>'SHARED'};
}
function sac_home_name(string $person):string {return match($person){'FAM-SALMAN'=>'Salman Home Expense','FAM-TALHA'=>'Talha Home Expense','FAM-TAYYAB'=>'Tayyab Home Expense',default=>'Combined Home Expense'};}
function sac_home_id(string $entity,string $person):string {return $entity.'-HOME-'.$person;}
function sac_accounts(array $s,string $e):array {
 $out=[];if($e==='TG')$out['TG-DEFAULT-ONLINE-BANKING']=['id'=>'TG-DEFAULT-ONLINE-BANKING','entity'=>'TG','name'=>'Online Banking Charges','parentCode'=>'6800','taxCategory'=>'NONE','active'=>true];foreach(sac_expense_defaults() as $category=>[$name,$code]){$id=$e.'-EXPENSE-TYPE-'.$category;$out[$id]=['id'=>$id,'entity'=>$e,'name'=>$name,'parentCode'=>$code,'taxCategory'=>'NONE','active'=>true,'builtinCategory'=>$category];}
 foreach(['FAM-SALMAN','FAM-TALHA','FAM-TAYYAB','SHARED'] as $person){$id=sac_home_id($e,$person);$out[$id]=['id'=>$id,'entity'=>$e,'name'=>sac_home_name($person),'parentCode'=>'6910','parentId'=>$e.'-EXPENSE-TYPE-HOME','taxCategory'=>'NONE','active'=>true,'homePerson'=>$person];}
 foreach(['FI / Instrument Charges'=>'BANK_INSTRUMENT','Card Charges'=>'BANK_CARD','Transfer Charges'=>'BANK_TRANSFER','Bank Financing Charges'=>'BANK_FINANCING','Bank Finance Markup'=>'FINANCE_MARKUP'] as $name=>$key){$id=$e.'-DEFAULT-'.$key;$out[$id]=['id'=>$id,'entity'=>$e,'name'=>$name,'parentCode'=>'6800','taxCategory'=>'NONE','active'=>$e!=='TG'];}
 foreach(['EXPORT','SAVINGS','BROKERAGE','SERVICES'] as $cat)foreach(['RECOVERABLE'=>'1260','PAYABLE'=>'2300'] as $t=>$code){$id=$e.'-DEFAULT-TAX-'.$cat.'-'.$t;$out[$id]=['id'=>$id,'entity'=>$e,'name'=>ucfirst(strtolower($cat)).' Withholding Tax '.ucfirst(strtolower($t)),'parentCode'=>$code,'taxCategory'=>$cat,'active'=>true];}
 foreach((array)($s['accountSubaccounts']??[]) as $id=>$r)if(is_array($r)&&($r['entity']??'')===$e)$out[$id]=$r;if($e==='TG')foreach($out as $id=>$row)if($id!=='TG-DEFAULT-ONLINE-BANKING'&&($row['parentCode']??'')==='6800'&&!empty($row['active'])&&in_array(strtoupper(trim((string)($row['name']??''))),['WEB BANKING CHARGES','ONLINE BANKING CHARGES','ONLINE / WEB BANKING CHARGES'],true)){unset($out['TG-DEFAULT-ONLINE-BANKING']);break;}
 if($e==='TG'){
  $used=[];foreach((array)($s['journals']??[]) as $j)if(($j['entity']??'')==='TG'&&($j['status']??'')==='Posted')foreach((array)($j['lines']??[]) as $line)if(!empty($line['subaccountId']))$used[(string)$line['subaccountId']]=true;
  foreach(array_keys($used) as $id){$seen=[];while(!empty($out[$id]['parentId'])&&!isset($seen[$id])){$seen[$id]=true;$id=(string)$out[$id]['parentId'];$used[$id]=true;}}
  foreach($out as $id=>$row){$pakistanTemplate=isset($row['homePerson'])||($row['parentCode']??'')==='6910'||in_array($row['builtinCategory']??'',['HOME','ARP'],true)||str_starts_with((string)$id,'TG-DEFAULT-TAX-');
   if($pakistanTemplate){if(isset($used[$id])){$out[$id]['active']=false;$out[$id]['historicalOnly']=true;}else unset($out[$id]);}
  }
 }
 return $out;
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
 return array_replace($a,['parentId'=>(string)($a['parentId']??''),'parentCode'=>$code,'active'=>$enabled,'ancestorIds'=>$ancestors,'path'=>sac_display_path($path),'class'=>$head['class'],'parentName'=>$head['name'],'expenseClassification'=>$code==='5550'?'EXPORT':(str_starts_with($code,'5')?'MILL':($code==='6910'?'HOME':'OFFICE')),'treatment'=>$code==='1260'?'Recoverable tax':($code==='2300'?'Tax payable':$head['class'])]);
}
function sac_display_path(array $names):string {
 $parts=[];foreach($names as $name){$name=trim((string)$name);if($name!==''&&(!$parts||strcasecmp($parts[count($parts)-1],$name)!==0))$parts[]=$name;}return implode(' / ',$parts);
}
function sac_extra(array $a):array {return ['subaccountId'=>$a['id'],'subaccountName'=>$a['name'],'subledger'=>$a['name'],'parentAccount'=>$a['parentCode'],'taxCategory'=>$a['taxCategory'],'accountClass'=>$a['class'],'expenseClassification'=>$a['expenseClassification']??'OFFICE'];}
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
  $classification=(string)($b['expenseClassification']??$old['expenseClassification']??'');if(!empty($b['expenseType'])){if(!in_array($classification,['MILL','HOME','OFFICE','EXPORT'],true)||$head['class']!=='Expense')throw new DomainException('Choose Milling / Production, Home, Office or General Export.');$valid=match($classification){'EXPORT'=>$parent==='5550','MILL'=>str_starts_with($parent,'5')&&$parent!=='5550','HOME'=>$parent==='6910','OFFICE'=>!str_starts_with($parent,'5')&&$parent!=='6910'};if(!$valid)throw new DomainException('Choose a parent under the selected expense classification.');}
  if(!empty($old['builtinCategory'])&&($parent!==$old['parentCode']||$parentId!==''))throw new DomainException('Standard expense types keep their posting head. Add a new type for a different classification.');
  if(isset($old['homePerson'])&&($parent!=='6910'||$parentId!==$e.'-EXPENSE-TYPE-HOME'))throw new DomainException('Keep Home ledgers under Home Expense.');
  $tax=strtoupper((string)($b['taxCategory']??'NONE'));if(!in_array($tax,['NONE','EXPORT','SAVINGS','BROKERAGE','SERVICES','OTHER'],true)||(!in_array($parent,['1260','2300'],true)&&$tax!=='NONE'))throw new DomainException('Tax category applies to recoverable tax or tax payable heads.');
  foreach(sac_accounts($s,$e) as $other)if($other['id']!==$id&&sac_normal($other['name'])===sac_normal($name))throw new DomainException('This subaccount name already exists. Edit or reactivate it.');
  if($op==='add')$id=far_next((array)($s['accountSubaccounts']??[]),'SUB');
  $a=['id'=>$id,'entity'=>$e,'name'=>$name,'expenseClassification'=>$classification]+array_intersect_key($old??[],array_flip(['builtinCategory','homePerson']))+['parentCode'=>$parent,'parentId'=>$parentId,'taxCategory'=>$tax,'active'=>!array_key_exists('active',$b)||(bool)$b['active']];
  // A subtree moves together. Its rows keep stable IDs and its vouchers keep saved treatment.
  if($old&&$old['parentCode']!==$parent)foreach(sac_subtree($s,$e,$id) as $child){
   $r=sac_accounts($s,$e)[$child];$r['parentCode']=$parent;if(!in_array($parent,['1260','2300'],true))$r['taxCategory']='NONE';$s['accountSubaccounts'][$child]=$r;
  }
 }
 $s['accountSubaccounts'][$id]=$a;$s['subaccountAudit'][]=['id'=>$id,'entity'=>$e,'operation'=>$op,'before'=>$old,'after'=>$a,'at'=>gmdate('c'),'by'=>$u['username']??''];return ['subaccountId'=>$id];
}
/** Classify saved tax facts without changing journals or consulting today's masters. */
function sac_tax_category(array $state,array $journal,array $line,array $seen=[]):string {
 foreach([$line['taxCategory']??null,$journal['meta']['taxCategory']??null] as $saved)if($saved!==null)return (string)$saved;
 $id=(string)($journal['id']??'');if(isset($seen[$id])||count($seen)>=16)return 'OTHER';$seen[$id]=true;
 $originalId=(string)($journal['reversalOf']??$journal['meta']['reversalOf']??'');
 if($originalId!==''){
  $original=$state['journals'][$originalId]??null;$categories=[];
  if(is_array($original)&&($original['entity']??'')===($journal['entity']??'')&&($original['status']??'')==='Posted'){
   // A generic cancellation may have discarded the source metadata. Inherit only
   // from exact opposite tax lines in the explicitly linked original company.
   foreach((array)($original['lines']??[]) as $old)if(is_array($old)&&(string)($old['account']??'')===(string)($line['account']??'')&&(string)($old['subaccountId']??'')===(string)($line['subaccountId']??'')&&(int)round((float)($old['debit']??0)*100)===(int)round((float)($line['credit']??0)*100)&&(int)round((float)($old['credit']??0)*100)===(int)round((float)($line['debit']??0)*100))$categories[sac_tax_category($state,$original,$old,$seen)]=true;
  }
  return count($categories)===1?(string)array_key_first($categories):'OTHER';
 }
 $source=(string)($journal['meta']['originalSourceType']??$journal['sourceType']??'');$meta=(array)($journal['meta']??[]);$code=(string)($line['account']??'');
 if($code==='2300'){
  if($source==='BROKERAGE_WHT_DEPOSIT')return 'BROKERAGE';
  $withheld=$source==='COMMODITY_BILL_VERIFIED'?(float)($meta['brokerageWithholding']??0):0.;
  if(in_array($source,['SUPPLIER_PAYMENT','SUPPLIER_CHEQUE_ISSUED'],true))foreach((array)($meta['allocations']??[]) as $allocation)if(is_array($allocation))$withheld+=(float)($allocation['brokerageWht']??0);
  if($withheld>0&&(int)round($withheld*100)===(int)round(((float)($line['credit']??0)-(float)($line['debit']??0))*100))return 'BROKERAGE';
 }
 if(($meta['bankDirectType']??$meta['bankEntryType']??'')==='SAVING_PROFIT')return 'SAVINGS';
 return str_contains($source,'EXPORT')?'EXPORT':'OTHER';
}
function sac_tax_entries(array $state,string $entity,string $from='',string $to=''):array {
 $rows=[];
 foreach((array)($state['journals']??[]) as $id=>$journal){
  if(!is_array($journal)||($journal['entity']??'')!==$entity||($journal['status']??'')!=='Posted')continue;
  $date=(string)($journal['date']??'');if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$date,$parts)||!checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1])||($from!==''&&$date<$from)||($to!==''&&$date>$to))continue;
  foreach((array)($journal['lines']??[]) as $index=>$line){$code=(string)($line['account']??'');if(!in_array($code,['1260','2300'],true))continue;
   $category=sac_tax_category($state,$journal,$line);
   $rows[]=['journalId'=>(string)($journal['id']??$id),'postId'=>tt_accounts_public_post($state,$journal),'lineIndex'=>$index,'date'=>$date,'entity'=>$entity,'currency'=>$entity==='TG'?'AED':'PKR','head'=>$code,'treatment'=>$code==='1260'?'Recoverable':'Payable','category'=>$category,'subaccountId'=>(string)($line['subaccountId']??''),'subaccountName'=>(string)($line['subaccountName']??''),'party'=>(string)($line['counterparty']??$line['subledger']??$line['partyName']??$journal['meta']['customer']??''),'reference'=>(string)($journal['reference']??''),'narration'=>(string)($journal['narration']??''),'debit'=>round((float)($line['debit']??0),2),'credit'=>round((float)($line['credit']??0),2),'reversalOf'=>(string)($journal['reversalOf']??'')];
  }
 }
 usort($rows,static fn($a,$b)=>strcmp($a['date'].'|'.$a['journalId'],$b['date'].'|'.$b['journalId'])?:($a['lineIndex']<=>$b['lineIndex']));return $rows;
}
function sac_payload(array $s,string $e,string $from='',string $to=''):array {
 $chart=sac_entity_chart($s,$e);$heads=[];$subs=sac_accounts($s,$e);$tax=[];
 foreach($chart as $code=>$r)$heads[(string)$code]=$r+['balance'=>0,'subaccounts'=>[]];
 foreach($subs as $id=>$r){$a=sac_resolve($s,$e,$id,false);$subs[$id]=$a+['balance'=>0];}
 foreach((array)($s['journals']??[]) as $j)if(($j['entity']??'')===$e&&($j['status']??'')==='Posted'&&($to===''||($j['date']??'')<=$to))foreach((array)($j['lines']??[]) as $l){$code=(string)($l['account']??'');$n=round((float)($l['debit']??0)-(float)($l['credit']??0),2);if(isset($heads[$code]))$heads[$code]['balance']+=$n;$id=(string)($l['subaccountId']??'');if(isset($subs[$id]))$subs[$id]['balance']+=$n;

 }
 foreach($heads as &$head)$head['directBalance']=$head['balance'];unset($head);foreach($heads as $code=>$head){$parent=(string)($head['parent']??'');$seen=[];while($parent!==''&&isset($heads[$parent])&&!isset($seen[$parent])){$heads[$parent]['balance']+=$head['directBalance'];$seen[$parent]=true;$parent=(string)($heads[$parent]['parent']??'');}}
 foreach($subs as &$a)$a['directBalance']=$a['balance'];unset($a);
 foreach($subs as $a)foreach($a['ancestorIds'] as $parent)if(isset($subs[$parent]))$subs[$parent]['balance']+=$a['directBalance'];
 foreach($subs as $a)if(empty($a['parentId'])&&isset($heads[$a['parentCode']]))$heads[$a['parentCode']]['subaccounts'][]=$a;
 $taxEntries=sac_tax_entries($s,$e,$from,$to);foreach($taxEntries as $entry){$k=$entry['head'].'|'.$entry['category'];$tax[$k]??=array_intersect_key($entry,array_flip(['head','treatment','category']))+['debit'=>0.,'credit'=>0.];$tax[$k]['debit']+=$entry['debit'];$tax[$k]['credit']+=$entry['credit'];}
 return ['ok'=>true,'entity'=>$e,'taxEntries'=>$taxEntries,'taxFilters'=>['from'=>$from,'to'=>$to],'currency'=>$e==='TG'?'AED':'PKR','revision'=>(int)($s['revision']??0),'heads'=>array_values($heads),'subaccounts'=>array_values($subs),'taxReport'=>array_values($tax)];
}


/** Expense activity is linked to the recipient, never an artificial receivable. */
function sac_expense_activity(array $s,string $e,string $from,string $to):array {
 $s=alv_amendment_books($s);
 $chart=sac_chart();$out=[];$hidden=alv_hidden($s,$to);$zakat=gm_zakat_payments($s,$from,$to,$e);$paid=array_column($zakat['rows'],'amountPKR','postId');
 foreach((array)($s['journals']??[]) as $j){
  $hiddenJournal=isset($hidden[(string)($j['id']??'')]);
  if(($j['entity']??'')!==$e||($j['status']??'')!=='Posted'||($j['date']??'')<$from||($j['date']??'')>$to)continue;
  $meta=(array)($j['meta']??[]);$source=(array)($s['generalExpenses'][(string)($meta['generalExpenseId']??'')]??[]);
  foreach((array)($j['lines']??[]) as $l){
   $code=(string)($l['account']??'');if(!empty($l['personalRecovery'])||($hiddenJournal&&$code!=='7210'))continue;
   if($code==='7210'&&!array_key_exists((string)$j['id'],$paid))continue;
   $master=(array)($s['salaryMasters'][(string)($l['salaryMasterId']??$meta['salaryMasterId']??'')]??[]);
   $monthly=$code==='3200'&&($l['category']??$meta['category']??$master['category']??'')==='HOME_MONTHLY_GIVE';
   if(!$monthly&&(($chart[$code]['class']??'')!=='Expense'||(str_starts_with($code,'5')&&$code!=='5550')))continue;
   if($monthly){$person=sac_home_person((string)($l['person']??$meta['person']??$master['name']??''));$l['expenseFor']=$person;$l['expenseForName']=match($person){'FAM-SALMAN'=>'Salman','FAM-TALHA'=>'Talha','FAM-TAYYAB'=>'Tayyab',default=>'Shared–Common'};$l['expenseArea']='HOME';}
   $home=($l['expenseArea']??$l['location']??'')==='HOME'||$code==='6910';
   $homeId=$home&&($l['expenseFor']??'SHARED')!=='FAM-ABU'?sac_home_id($e,(string)($l['expenseFor']??'SHARED')):'';
   $id=(string)($l['expenseRecipientId']??$l['paymentPayeeId']??$meta['payeeId']??$source['payeeId']??'');
   $recipient=(string)($s['paymentPayees'][$e.'|'.$id]['name']??$l['counterparty']??$l['party']??$l['person']??$meta['payee']??$meta['beneficiary']??$source['payee']??'');
   $asset=(array)($s['managedAssets'][(string)($l['assetId']??'')]??[]);
   $out[]=['homeLedgerId'=>$homeId,'homeLedgerName'=>$homeId!==''?sac_home_name((string)($l['expenseFor']??'SHARED')):'','date'=>$j['date'],'voucher'=>$j['id'],'journalId'=>$j['id'],'publicPostId'=>tt_accounts_public_post($s,$j),'sourceType'=>$j['sourceType']??'','account'=>$code,'accountName'=>$l['accountName']??$chart[$code]['name'],'expenseFor'=>$l['expenseFor']??'SHARED','expenseForName'=>$l['expenseForName']??'Shared–Common','expenseArea'=>$l['expenseArea']??$l['location']??$meta['location']??'','personalTreatment'=>$l['personalTreatment']??'COMPANY','recipient'=>$recipient,'recipientId'=>$id,'assetId'=>$l['assetId']??'','assetName'=>$l['assetName']??$asset['name']??'','registrationNo'=>$l['registrationNo']??$asset['registrationNo']??'','purpose'=>$l['expensePurpose']??$j['narration']??'','reference'=>$j['reference']??'','amount'=>round((float)($l['debit']??0)-(float)($l['credit']??0),2),'reversal'=>!empty($j['reversalOf'])];
  }
 }
 // Separated payroll payments settle a liability and contain no second expense
 // debit. Present their proven Zakat component on the payment date, without
 // inserting or changing any journal or expensing the accrual twice.
 foreach($zakat['rows'] as $payment)foreach($payment['payrollComponents']??[] as $part){
  $journal=null;foreach($s['journals']??[] as $key=>$candidate)if((string)($candidate['id']??$key)===$payment['postId']){$journal=$candidate;break;}
  if(!$journal)continue;$person='';foreach($journal['lines']??[] as $line)if(zp_salary_key($line,$journal)===$part['salaryMasterId'].'|'.$part['month']){$person=(string)($line['person']??$line['party']??'');if($person!=='')break;}
  $person=$person?:($s['salaryMasters'][$part['salaryMasterId']]['name']??'');
  $out[]=['homeLedgerId'=>'','homeLedgerName'=>'','date'=>$payment['date'],'voucher'=>$payment['postId'],'journalId'=>$payment['postId'],'publicPostId'=>tt_accounts_public_post($s,$journal),'sourceType'=>$journal['sourceType']??'','account'=>'7210','accountName'=>$chart['7210']['name'],'expenseFor'=>'SHARED','expenseForName'=>'Shared–Common','expenseArea'=>'','personalTreatment'=>'COMPANY','recipient'=>$person,'recipientId'=>$part['salaryMasterId'],'assetId'=>'','assetName'=>'','registrationNo'=>'','purpose'=>'Zakat — '.$part['month'],'reference'=>$payment['reference'],'amount'=>$part['amountPKR'],'reversal'=>!empty($payment['reversalOf'])];
 }
 usort($out,static fn($a,$b)=>strcmp($a['date'],$b['date'])?:strnatcmp($a['journalId'],$b['journalId']));
 return $out;
}

/** Read-only presentation from committed bill snapshots; never changes historic journals. */
/** Compact display only: the saved narration and immutable journal remain unchanged. */
function sac_compact_narration(string $text,array $meta=[]):string {
 $method=(string)($meta['bankPaymentMethod']??'');$cheque=trim((string)($meta['chequeNo']??''));$ref=trim((string)($meta['bankReference']??''));
 $text=str_replace(['ONLINE_BANKING','BANK_TRANSFER'],['Online Banking','Bank Transfer'],$text);
 $parts=preg_split('/\s*[·|]\s*/u',$text)?:[$text];$seen=[];$out=[];
 $hasCheque=$cheque!==''&&stripos($text,$cheque)!==false;
 foreach($parts as $part){$part=trim($part);if($part==='')continue;$key=strtolower(preg_replace('/[^a-zA-Z0-9]+/','',$part)??$part);
  if($hasCheque&&strcasecmp($part,'CHEQUE')===0)continue;
  if($cheque!==''&&$part===$cheque)$part='Cheque '.$cheque;
  $key=strtolower(preg_replace('/[^a-zA-Z0-9]+/','',$part)??$part);if(isset($seen[$key]))continue;$seen[$key]=true;$out[]=$part;
 }
 $joined=implode(' · ',$out);$label=$method==='CHEQUE'&&$cheque!==''?'Cheque '.$cheque:($method==='ONLINE_BANKING'?'Online Banking':($method==='BANK_TRANSFER'?'Bank Transfer':''));
 if($label!==''&&stripos($joined,$label)===false)$joined.=($joined!==''?' · ':'').$label;
 if($method!=='CHEQUE'&&$ref!==''&&stripos($joined,$ref)===false)$joined.=' · '.$ref;
 return $joined;
}
function sac_journal_narration(array $store,array $journal):string {
 $meta=(array)($journal['meta']??[]);$text=sac_compact_narration((string)($journal['narration']??''),$meta);$detail=[];
 $bill=$store['supplierBills'][(string)($meta['supplierBillId']??'')]??null;
 if(is_array($bill)&&($bill['entity']??'')===($journal['entity']??'')){
  $source=(array)($bill['sourceRecord']??[]);$number=trim((string)($journal['reference']??$bill['billNo']??$source['invoiceNo']??''));if($number!=='')$detail[]='Bill No. '.$number;
  $sections=(array)($meta['shipmentSections']??$source['shipmentSections']??[]);if(!$sections)$sections=[$source];$count=0;$ports=[];$countries=[];$lots=[];
  foreach($sections as $r){$count+=(int)($r['containerCount']??count((array)($r['containers']??[])));foreach(['portOfDischarge','dischargePort','destinationPort'] as $key)if(trim((string)($r[$key]??''))!==''){$ports[trim((string)$r[$key])]=true;break;}foreach(['dischargeCountry','podCountry','destinationCountry'] as $key)if(trim((string)($r[$key]??''))!==''){$countries[trim((string)$r[$key])]=true;break;}if(!empty($r['shipmentRef']))$lots[(string)$r['shipmentRef']]=true;}
  if($count)$detail[]='Containers '.$count;if($ports)$detail[]='Discharge port '.implode(', ',array_keys($ports));if($countries)$detail[]='Discharge country '.implode(', ',array_keys($countries));if($lots)$detail[]='Shipment '.implode(', ',array_keys($lots));
 }
 $bag=$store['bagSupplierBills'][(string)($meta['bagBillId']??'')]??null;
 if(is_array($bag)&&($bag['entity']??'')===($journal['entity']??'')){
  $number=$journal['reference']??$bag['sellerInvoice']??'';$brand=$meta['brand']??$bag['brand']??'';$bags=$meta['bags']??$bag['bags']??0;$packing=$meta['packingSize']??$bag['packingSize']??'';if($number!=='')$detail[]='Supplier Bill No. '.$number;if($brand!=='')$detail[]='Brand '.$brand;if($bags)$detail[]='Bags '.number_format((float)$bags,0);if($packing!=='')$detail[]='Packing '.$packing;
 }
 foreach($detail as $piece)if(stripos($text,$piece)===false)$text.=' · '.$piece;return $text;
}


/** Paid-basis reporting only. No assessment, journal, allocation or storage writes. */
function zp_valid_date(string $value):bool {
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    $errors=DateTimeImmutable::getLastErrors();
    return $date!==false&&($errors===false||($errors['warning_count']===0&&$errors['error_count']===0))&&$date->format('Y-m-d')===$value;
}
/** A payroll payment identifies its beneficiary on the payable, not necessarily the bank. */
function zp_salary_key(array $line,array $journal):string {
    $id=trim((string)($line['salaryMasterId']??$journal['meta']['salaryMasterId']??''));
    $month=(string)($line['month']??$journal['meta']['month']??'');
    return $id!==''&&preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$month)?$id.'|'.$month:'';
}
/** Explicit expense wording; recipient names and custodial fund transfers are not expense purposes. */
function zp_zakat_text(string $text):bool {
    return preg_match('/(?<![\pL\pN])(?:zakat|zakaat|zakah|زک[او](?:[\x{064B}-\x{065F}\x{0670}]*[تۃة]))(?![\pL\pN])/iu',$text)===1;
}
/** New manual expense lines only; cash, creditors, funds and opening controls retain their heads. */
function zp_route_expense_lines(array $lines,string $narration=''):array {
    $chart=sac_chart();$expenseDebits=0;
    foreach($lines as $line)if(($chart[(string)($line['account']??'')]['class']??'')==='Expense'&&(float)($line['debit']??0)>.005)$expenseDebits++;
    foreach($lines as &$line){
        if(($chart[(string)($line['account']??'')]['class']??'')!=='Expense'||(float)($line['debit']??0)<=.005)continue;
        $purpose=(string)($line['expensePurpose']??$line['memo']??'');
        if(!zp_zakat_text($purpose)&&!($expenseDebits===1&&zp_zakat_text($narration)))continue;
        $line['account']='7210';$line['accountName']=$chart['7210']['name'];$line['donationType']='ZAKAT';
        foreach(['subaccountId','subaccountName','parentAccount','taxCategory','accountClass','expenseClassification'] as $field)unset($line[$field]);
        if(isset($line['targetKey']))$line['targetKey']='';
    }unset($line);return $lines;
}
function zp_balanced_source(array $journal):bool {
    $debits=0.;$credits=0.;$lines=$journal['lines']??[];
    if(!is_array($lines)||count($lines)<2)return false;
    foreach($lines as $line){if(!is_array($line))return false;foreach(['debit','credit'] as $side){$amount=$line[$side]??0;if(!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<0)return false;}$debits+=(float)($line['debit']??0);$credits+=(float)($line['credit']??0);}
    return abs($debits-$credits)<.005;
}
function gm_zakat_payments(array $state,string $from,string $to,string $entity='TTI'):array {
    $rows=[];$total=0.;$warnings=[];$salary=[];$salaryGross=[];$salaryCashPaid=[];
    // An accrual is not payment. Payroll cash can settle several components and
    // must not be allocated to Zakat by an assumed payment order or proportion.
    foreach((array)($state['journals']??[]) as $j){if(!is_array($j)||($j['entity']??'')!==$entity||($j['status']??'')!=='Posted'||!zp_valid_date((string)($j['date']??''))||$j['date']>$to||!zp_balanced_source($j))continue;foreach((array)($j['lines']??[]) as $line){if(!is_array($line))continue;$key=zp_salary_key($line,$j);if($key===''||!in_array((string)($line['account']??''),['6210','6220','7210','3200','6910'],true))continue;$salary[$key]??=0.;$net=(float)($line['debit']??0)-(float)($line['credit']??0);$salaryGross[$key]=($salaryGross[$key]??0)+$net;if((string)($line['account']??'')==='7210')$salary[$key]+=$net;}}
    foreach((array)($state['journals']??[]) as $j){
        if(!is_array($j)||($j['entity']??'')!==$entity||($j['status']??'')!=='Posted'||!zp_valid_date((string)($j['date']??''))||$j['date']>$to||!zp_balanced_source($j))continue;
        $cash=0.;$parts=[];foreach((array)($j['lines']??[]) as $line){if(!is_array($line))continue;$net=(float)($line['debit']??0)-(float)($line['credit']??0);$code=(string)($line['account']??'');if(in_array($code,['1110','1120'],true))$cash-=$net;elseif(in_array($code,['2140','2230'],true)&&($key=zp_salary_key($line,$j))!=='')$parts[$key]=($parts[$key]??0)+$net;}
        if(abs($cash)>.005)foreach($parts as $key=>$net)$salaryCashPaid[$key]=($salaryCashPaid[$key]??0)+$net;
    }
    foreach((array)($state['journals']??[]) as $id=>$j){
        if(!is_array($j)||($j['entity']??'')!==$entity||($j['status']??'')!=='Posted')continue;$date=(string)($j['date']??'');$postId=(string)($j['id']??$id);$amount=0.;$cash=0.;$payroll=str_starts_with((string)($j['sourceType']??''),'SALARY_');$salaryKnown=true;$salaryZakat=false;$cashInPeriod=false;$dateMismatch=false;$nonCashCredit=0.;$nonCashDebit=0.;$valid=true;$debits=0.;$credits=0.;$hasZakat=false;$advanceOnly=str_starts_with((string)($j['sourceType']??''),'SALARY_ADVANCE')||str_starts_with((string)($j['meta']['originalSourceType']??''),'SALARY_ADVANCE');
        $advanceLabel=$advanceOnly;$payrollKeys=[];$settlements=[];$nonCash=[];$components=[];
        foreach((array)($j['lines']??[]) as $line){if(!is_array($line)){$valid=false;continue;}foreach(['debit','credit'] as $side)if(!is_numeric($line[$side]??0)||!is_finite((float)($line[$side]??0))||(float)($line[$side]??0)<0)$valid=false;$debits+=(float)($line['debit']??0);$credits+=(float)($line['credit']??0);$code=(string)($line['account']??'');if(!in_array($code,['1230','1110','1120'],true))$advanceOnly=false;$net=(float)($line['debit']??0)-(float)($line['credit']??0);if($code==='7210'){$hasZakat=true;$amount+=$net;}
            $key=zp_salary_key($line,$j);
            if(in_array($code,['2140','2230'],true)&&abs($net)>.005){$payrollKeys[$key]=true;if($key!=='')$settlements[$key]=($settlements[$key]??0)+$net;}
            if(in_array($code,['1110','1120'],true)){$cash-=$net;$on=(string)($line['paymentDate']??$date);if(!zp_valid_date($on))$dateMismatch=true;elseif($on>=$from&&$on<=$to)$cashInPeriod=true;if($on!==$date)$dateMismatch=true;
                if($key!==''){$payroll=true;$payrollKeys[$key]=true;}
            }else{$identity=$code.'|'.$key.'|'.(string)($line['subaccountId']??'').'|'.(string)($line['counterparty']??$line['party']??'');$nonCash[$identity]=($nonCash[$identity]??0)+$net;}
        }
        foreach($nonCash as $net){$nonCashCredit+=max(0,-$net);$nonCashDebit+=max(0,$net);}
        if($payroll){$salaryKnown=(bool)$payrollKeys;foreach($payrollKeys as $key=>$unused){if(!array_key_exists($key,$salary))$salaryKnown=false;elseif($salary[$key]>.005)$salaryZakat=true;}}
        if((!$valid||abs($debits-$credits)>.005)&&($hasZakat||$payroll)&&(!zp_valid_date($date)||($date>=$from&&$date<=$to))){$warnings[]='Reconcile the invalid or unbalanced Zakat source Post '.$postId.' before reporting paid expense.';continue;}
        // A proven advance posts only a receivable and cash/bank. It is not a
        // salary/Zakat settlement, even when the employee has monthly Zakat.
        if($advanceOnly&&!$hasZakat&&$valid&&abs($debits-$credits)<.005){$payroll=false;$salaryZakat=false;$salaryKnown=true;}
        // A full single-voucher settlement proves all its recorded components.
        // Partial payments, advance recovery and unknown beneficiaries remain unallocated.
        $fullyFunded=!$advanceLabel&&($hasZakat||$salaryKnown)&&(($cash>.005&&$nonCashCredit<.005&&$cash+.005>=$nonCashDebit)||($cash<-.005&&$nonCashDebit<.005&&-$cash+.005>=$nonCashCredit));
        if($fullyFunded&&!$hasZakat&&$salaryZakat){foreach($payrollKeys as $key=>$unused){if(($salary[$key]??0)<=.005)continue;$settled=$settlements[$key]??0;$gross=$salaryGross[$key]??0;if($settled*$cash<=0||abs(abs($settled)-$gross)>.005||($salaryCashPaid[$key]??0)>$gross+.005){$fullyFunded=false;break;}$components[]=['salaryMasterId'=>explode('|',$key)[0],'month'=>explode('|',$key)[1],'amountPKR'=>round($salary[$key]*($settled>0?1:-1),2)];}if($fullyFunded)$amount=round(array_sum(array_column($components,'amountPKR')),2);}
        if(abs($cash)>.005&&($cashInPeriod||!zp_valid_date($date))&&$payroll&&($amount>.005||$salaryZakat||!$salaryKnown)&&!($fullyFunded&&abs($amount)>.005)){$warnings[]='Review the original Zakat component of payroll cash Post '.$postId.'; no salary payment allocation has been guessed.';continue;}
        if(abs($amount)<.005)continue;
        if($cashInPeriod&&$dateMismatch){$warnings[]='Reconcile the actual payment date against the voucher date of Zakat Post '.$postId.'.';continue;}
        if(!zp_valid_date($date)){$warnings[]='Confirm the original date of Zakat Post '.$postId.' before payment reconciliation.';continue;}if($date<$from||$date>$to)continue;
        if(abs($cash)<.005)continue; // Pure unpaid expense/accrual, not an actual payout.
        if($dateMismatch||($amount>0&&($cash+.005<$amount||$nonCashCredit>.005))||($amount<0&&($cash-.005>$amount||$nonCashDebit>.005))){$warnings[]='Review the mixed or partial cash settlement of Zakat Post '.$postId.' before applying it to the assessment.';continue;}
        $amount=round($amount,2);$total+=$amount;$rows[]=['postId'=>$postId,'date'=>$date,'amountPKR'=>$amount,'reference'=>(string)($j['reference']??''),'reversalOf'=>$j['reversalOf']??null]+($components?['payrollComponents'=>$components]:[]);
    }
    usort($rows,static fn($a,$b)=>strcmp($a['date'].'|'.$a['postId'],$b['date'].'|'.$b['postId']));return ['totalPKR'=>round($total,2),'rows'=>$rows,'warnings'=>array_values(array_unique($warnings))];
}
