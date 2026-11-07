<?php
declare(strict_types=1);
require __DIR__.'/../../api/document_ai_settings.php';
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$tiers=[['provider'=>'openai','model'=>'example-mini','enabled'=>true],['provider'=>'gemini','model'=>'example-flash','enabled'=>true],['provider'=>'gemini','model'=>'example-backup','enabled'=>false]];
$settings=tt_ai_validate_settings(['enabled'=>true,'fallback'=>true,'tiers'=>$tiers]);
$calls=[];$result=tt_ai_route($settings,function($tier)use(&$calls){$calls[]=$tier['model'];if(count($calls)===1)throw new RuntimeException('Unavailable',404);return ['data'=>['buyer'=>'Example']];});
check($calls===['example-mini','example-flash'],'Must use enabled tiers in order and stop after success');
check($result['tier']===2&&count($result['attempts'])===1,'Must report the selected tier and preceding failure');
$calls=[];try{tt_ai_route($settings+[],function($tier)use(&$calls){$calls[]=$tier['model'];throw new RuntimeException('Unavailable');});throw new LogicException('Expected failure');}catch(RuntimeException $e){check(count($calls)===2,'Disabled tier must not run');}
$settings['fallback']=false;$calls=[];try{tt_ai_route($settings,function($tier)use(&$calls){$calls[]=$tier;throw new RuntimeException('Unavailable');});throw new LogicException('Expected failure');}catch(RuntimeException $e){check(count($calls)===1,'Fallback off must make one attempt');}
$settings['enabled']=false;$calls=[];try{tt_ai_route($settings,function($tier)use(&$calls){$calls[]=$tier;return [];});throw new LogicException('Expected failure');}catch(RuntimeException $e){check(count($calls)===0,'Reader off must make no calls');}
try{tt_ai_validate_settings(['enabled'=>true,'tiers'=>[$tiers[0],$tiers[0]]]);throw new LogicException('Duplicate accepted');}catch(InvalidArgumentException $e){}
try{tt_ai_validate_settings(['enabled'=>true,'tiers'=>[['provider'=>'other','model'=>'test-model','enabled'=>true]]]);throw new LogicException('Provider accepted');}catch(InvalidArgumentException $e){}
echo "Routing tests passed\n";
