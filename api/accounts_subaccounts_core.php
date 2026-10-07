<?php
declare(strict_types=1);
require_once __DIR__.'/assets_registry_core.php';
function sac_chart():array {
 $m=json_decode((string)file_get_contents(__DIR__.'/../accounts/accounting_master_v1.json'),true,512,JSON_THROW_ON_ERROR);$out=[];
 foreach(array_merge($m['chart'],$m['peopleSubledgers']??[]) as $r)$out[(string)$r['code']]=$r;foreach($out as $code=>&$a){if(!empty($a['parent'])||($a['level']??'')==='heading')continue;if(ctype_digit((string)$code)){$control=(string)(intdiv((int)$code,100)*100);$heading=(string)(intdiv((int)$code,1000)*1000);if($control!==(string)$code&&isset($out[$control]))$a['parent']=$control;elseif($heading!==(string)$code&&isset($out[$heading]))$a['parent']=$heading;elseif(($a['class']??'')==='Expense')$a['parent']='6000';}}unset($a);return $out;
}
function sac_descendants(string $head):array { $chart=sac_chart();$codes=[$head];foreach($chart as $code=>$a){$parent=(string)($a['parent']??'');$seen=[];while($parent!==''&&!isset($seen[$parent])){if($parent===$head){$codes[]=(string)$code;break;}$seen[$parent]=true;$parent=(string)($chart[$parent]['parent']??'');}}return $codes; }
function sac_permission(array $u,string $icon,string $action):bool {
 if(($u['role']??'')==='Super Admin')return true;$p=$u['permissions']['Accounts']??[];return $p==='all'||(is_array($p)&&(in_array($action,$p,true)||in_array($action,(array)($p[$icon]??[]),true)));
}
function sac_normal(string $v):string {return strtolower((string)preg_replace('/[^\pL\pN]/u','',$v));}
function sac_accounts(array $s,string $e):array {
 $out=[];foreach(['FI / Instrument Charges'=>'BANK_INSTRUMENT','Card Charges'=>'BANK_CARD','Transfer Charges'=>'BANK_TRANSFER','Bank Financing Charges'=>'BANK_FINANCING','Bank Finance Markup'=>'FINANCE_MARKUP'] as $name=>$key){$id=$e.'-DEFAULT-'.$key;$out[$id]=['id'=>$id,'entity'=>$e,'name'=>$name,'parentCode'=>'6800','taxCategory'=>'NONE','active'=>true];}
 foreach(['EXPORT','SAVINGS','BROKERAGE','SERVICES'] as $cat)foreach(['RECOVERABLE'=>'1260','PAYABLE'=>'2300'] as $t=>$code){$id=$e.'-DEFAULT-TAX-'.$cat.'-'.$t;$out[$id]=['id'=>$id,'entity'=>$e,'name'=>ucfirst(strtolower($cat)).' Withholding Tax '.ucfirst(strtolower($t)),'parentCode'=>$code,'taxCategory'=>$cat,'active'=>true];}
 foreach((array)($s['accountSubaccounts']??[]) as $id=>$r)if(is_array($r)&&($r['entity']??'')===$e)$out[$id]=$r;return $out;
}
function sac_resolve(array $s,string $e,string $id,bool $active=true):array {
 $a=sac_accounts($s,$e)[$id]??null;if(!$a||($active&&!$a['active']))throw new DomainException('Select an active subaccount in these company books.');$chart=sac_chart();$head=$chart[$a['parentCode']]??null;if(!$head)throw new DomainException('Subaccount head is unavailable.');return $a+['class'=>$head['class'],'parentName'=>$head['name'],'treatment'=>$a['parentCode']==='1260'?'Recoverable tax':($a['parentCode']==='2300'?'Tax payable':$head['class'])];
}
function sac_extra(array $a):array {return ['subaccountId'=>$a['id'],'subaccountName'=>$a['name'],'subledger'=>$a['name'],'parentAccount'=>$a['parentCode'],'taxCategory'=>$a['taxCategory'],'accountClass'=>$a['class']];}
function sac_master(array &$s,string $e,array $b,array $u):array {
 $op=(string)($b['operation']??'add');$id=(string)($b['id']??'');$old=$op==='add'?null:sac_resolve($s,$e,$id,false);if(!in_array($op,['add','edit','delete'],true))throw new DomainException('Unknown subaccount action.');
 if($op==='delete'){$a=$old;$a['active']=false;}else{
  $name=far_text($b['name']??'',180);if($name==='')throw new DomainException('Enter a subaccount name.');$parent=(string)($b['parentCode']??'');$chart=sac_chart();$head=$chart[$parent]??null;
  if(!$head||in_array($head['level']??'',['heading','system'],true)||in_array($parent,['1110','1120','1130','1430','1440','1610','2610'],true))throw new DomainException('Select a valid posting head. Bank, investment and finance registers manage their own accounts.');
  $tax=strtoupper((string)($b['taxCategory']??'NONE'));if(!in_array($tax,['NONE','EXPORT','SAVINGS','BROKERAGE','SERVICES','OTHER'],true)||(!in_array($parent,['1260','2300'],true)&&$tax!=='NONE'))throw new DomainException('Tax category applies to recoverable tax or tax payable heads.');
  foreach(sac_accounts($s,$e) as $other)if($other['id']!==$id&&sac_normal($other['name'])===sac_normal($name))throw new DomainException('This subaccount name already exists. Edit or reactivate it.');
  if($op==='add')$id=far_next((array)($s['accountSubaccounts']??[]),'SUB');$a=['id'=>$id,'entity'=>$e,'name'=>$name,'parentCode'=>$parent,'taxCategory'=>$tax,'active'=>!array_key_exists('active',$b)||(bool)$b['active']];
 }
 $s['accountSubaccounts'][$id]=$a;$s['subaccountAudit'][]=['id'=>$id,'entity'=>$e,'operation'=>$op,'before'=>$old,'after'=>$a,'at'=>gmdate('c'),'by'=>$u['username']??''];return ['subaccountId'=>$id];
}
function sac_payload(array $s,string $e,string $from='',string $to=''):array {
 $chart=sac_chart();$heads=[];$subs=sac_accounts($s,$e);$tax=[];
 foreach($chart as $code=>$r)$heads[(string)$code]=$r+['balance'=>0,'subaccounts'=>[]];
 foreach($subs as $id=>$r){$a=sac_resolve($s,$e,$id,false);$subs[$id]=$a+['balance'=>0];}
 foreach((array)($s['journals']??[]) as $j)if(($j['entity']??'')===$e&&($j['status']??'')==='Posted'&&($to===''||($j['date']??'')<=$to))foreach((array)($j['lines']??[]) as $l){$code=(string)($l['account']??'');$n=round((float)($l['debit']??0)-(float)($l['credit']??0),2);if(isset($heads[$code]))$heads[$code]['balance']+=$n;$id=(string)($l['subaccountId']??'');if(isset($subs[$id]))$subs[$id]['balance']+=$n;
  if(in_array($code,['1260','2300'],true)&&($from===''||($j['date']??'')>=$from)){$cat=$l['taxCategory']??($j['meta']['taxCategory']??(($j['meta']['bankDirectType']??'')==='SAVING_PROFIT'?'SAVINGS':(str_contains($j['sourceType']??'','EXPORT')?'EXPORT':'OTHER')));$k=$code.'|'.$cat;$tax[$k]??=['head'=>$code,'treatment'=>$code==='1260'?'Recoverable':'Payable','category'=>$cat,'debit'=>0,'credit'=>0];$tax[$k]['debit']+=(float)($l['debit']??0);$tax[$k]['credit']+=(float)($l['credit']??0);}
 }
 foreach($heads as &$head)$head['directBalance']=$head['balance'];unset($head);foreach($heads as $code=>$head){$parent=(string)($head['parent']??'');$seen=[];while($parent!==''&&isset($heads[$parent])&&!isset($seen[$parent])){$heads[$parent]['balance']+=$head['directBalance'];$seen[$parent]=true;$parent=(string)($heads[$parent]['parent']??'');}}
 foreach($subs as $a)if(isset($heads[$a['parentCode']]))$heads[$a['parentCode']]['subaccounts'][]=$a;
 return ['ok'=>true,'entity'=>$e,'revision'=>(int)($s['revision']??0),'heads'=>array_values($heads),'subaccounts'=>array_values($subs),'taxReport'=>array_values($tax)];
}
