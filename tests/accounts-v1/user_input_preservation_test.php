<?php
declare(strict_types=1);
// Exercise the actual validator without calling the live user-management route.
$source=file_get_contents(dirname(__DIR__,2).'/api/users.php');
$start=strpos($source,'function clean_input(');
$end=strpos($source,"\ntry {",$start);
eval(substr($source,$start,$end-$start));
function check(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
$base=['name'=>'Fixture','username'=>'fixture.user','role'=>'Director','active'=>true,'permissions'=>['Accounts'=>['reports'=>['View']]],'masterAccess'=>true,'masterPermissions'=>['reference_lists'=>['Use','View'],'product_settings'=>['View','Edit']]];
foreach(['all',['View','Edit']] as $legacy){
    $input=$base;$input['permissions']['Directors']=$legacy;
    $result=clean_input($input,['Directors'=>$legacy]);
    check($result['permissions']['Directors']===$legacy,'Unchanged legacy grant lost at server validation');
    check($result['masterPermissions']===$base['masterPermissions'],'Hidden master scopes lost');
    $new=clean_input($input);
    check(!isset($new['permissions']['Directors']),'Validator must not create a new module-wide legacy grant');
}
$input=$base;$input['permissions']['Directors']=['tgmaster'=>['View'],'assets'=>['View','Edit']];
check(clean_input($input)['permissions']['Directors']===$input['permissions']['Directors'],'Granular grants changed');
echo "PASS user validator retains existing legacy grants without creating new legacy grants\n";
