<?php
declare(strict_types=1);
$code=file_get_contents(__DIR__.'/../../api/accounts_subaccounts_core.php');$a=strpos($code,'function sac_normal(');$b=strpos($code,'function sac_payee(array',$a);eval(substr($code,$a,$b-$a));
function tt_list_masters():array{return $GLOBALS['fixtureMasters'];}
function tt_business_party_has_category($roles,$role):bool{return in_array($role,array_map('trim',explode(';',(string)$roles)),true);}
function sac_entity_chart($s,$e):array{return ['6700'=>['class'=>'Expense']];}
function sac_expense_defaults():array{return ['PROFESSIONAL'=>['Professional Fees','6700']];}
function row(string $id,string $name,array $profile=[],string $roles='Supplier'):array{$v=array_fill(0,13,'');$v[0]=$name;$v[2]=$roles;$v[10]='Active';$v[12]=json_encode($profile);return ['id'=>$id,'values'=>$v];}
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$GLOBALS['fixtureMasters']=['business_parties'=>[row('PK','Pakistan Bags'),row('USED','Rice Exporter'),row('TG','UAE Auditor',['accountingCompanies'=>['TG'],'service'=>['expenseAccountCode'=>'6700']],'Service Provider'),row('PKS','Pakistan Consultant',['service'=>['expenseAccountCode'=>'6700']],'Service Provider'),row('DISABLED','Previously Used',['accountingCompanies'=>['TTI']])]];
$s=['journals'=>['J'=>['entity'=>'TG','status'=>'Posted','lines'=>[['counterparty'=>'Rice Exporter'],['counterparty'=>'Previously Used']]]],'paymentPayees'=>['TG|EXP'=>['id'=>'EXP|local','entity'=>'TG','kind'=>'EXPENSE','name'=>'Local Recipient','active'=>true]]];$before=serialize($s);
$tg=array_column(sac_payees($s,'TG'),'name');check(!in_array('Pakistan Bags',$tg,true)&&!in_array('Pakistan Consultant',$tg,true),'Pakistan-only names leak into TG');check(in_array('Rice Exporter',$tg,true)&&in_array('UAE Auditor',$tg,true)&&in_array('Local Recipient',$tg,true),'TG history or assigned recipient lost');check(!in_array('Previously Used',$tg,true),'Explicit company restriction ignored');
$pk=array_column(sac_payees($s,'TTI'),'name');check(in_array('Pakistan Bags',$pk,true)&&!in_array('UAE Auditor',$pk,true),'Pakistan choices lost or explicit TG leaked');
$expenses=array_column(sac_expense_recipients($s,'TG'),'name');check(in_array('UAE Auditor',$expenses,true)&&!in_array('Pakistan Consultant',$expenses,true),'Service-provider expenses not scoped');check(serialize($s)===$before,'Choices changed historical records');
$s['journals']['J']['entity']='BRM';check(!in_array('Rice Exporter',array_column(sac_payees($s,'TG'),'name'),true),'Other-company history enabled TG');echo "PASS company-scoped payees, service providers, explicit assignments, TG history and immutable records\n";
