<?php
declare(strict_types=1);
define('TT_DOCUMENT_AI_FUNCTIONS_ONLY',true);
define('TT_DATA_DIR',sys_get_temp_dir().'/tt-ai-test-'.bin2hex(random_bytes(6)));
function tt_ensure_data_dir():void{if(!is_dir(TT_DATA_DIR))mkdir(TT_DATA_DIR,0700,true);}
$GLOBALS['audits']=[];
function tt_audit($id,$username,$message):void{$GLOBALS['audits'][]=$message;}
require __DIR__.'/../../api/document_ai.php';
function verify(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function rejects(callable $fn,string $message):void{try{$fn();}catch(RuntimeException|InvalidArgumentException $e){return;}throw new LogicException($message);}
try{
    $schema=['type'=>'object','properties'=>['buyer'=>['type'=>'string'],'amount'=>['type'=>'number'],'bags'=>['type'=>'array','items'=>['type'=>'object','properties'=>['label'=>['type'=>'string']]]]]];
    $data=['buyer'=>'Example buyer','amount'=>42.5,'bags'=>[['label'=>'25 KG']]];
    $response=['status'=>'completed','model'=>'example-mini-2026','usage'=>['input_tokens'=>100],'output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode($data)]]]]];
    $result=tt_ai_parse_openai_response(json_encode($response),'example-mini',$schema);
    verify($result['data']===$data&&$result['returnedModel']==='example-mini-2026'&&$result['usage']['input_tokens']===100,'Parse fields and actual model/usage');
    $bad=$response;$bad['status']='incomplete';rejects(fn()=>tt_ai_parse_openai_response(json_encode($bad),'test',$schema),'Incomplete response accepted');
    $bad=$response;$bad['output'][0]['content']=[['type'=>'refusal','refusal'=>'No']];rejects(fn()=>tt_ai_parse_openai_response(json_encode($bad),'test',$schema),'Refusal accepted');
    foreach(['not json','{}','{"buyer":"X","amount":"100","bags":[]}'] as $invalid){$bad=$response;$bad['output'][0]['content'][0]['text']=$invalid;rejects(fn()=>tt_ai_parse_openai_response(json_encode($bad),'test',$schema),'Malformed extraction accepted');}
    $strict=tt_ai_strict_schema($schema);verify($strict['additionalProperties']===false&&$strict['properties']['bags']['items']['required']===['label'],'Nested strict schema');
    $parts=tt_ai_openai_content([['text'=>'Read me'],['inline_data'=>['mime_type'=>'application/pdf','data'=>'UEZERg==']],['inline_data'=>['mime_type'=>'image/png','data'=>'UE5H']]]);
    verify($parts[0]['type']==='input_text'&&$parts[1]['type']==='input_file'&&$parts[1]['file_data']==='data:application/pdf;base64,UEZERg=='&&$parts[2]['type']==='input_image','Document payload types');
    $settings=['enabled'=>true,'fallback'=>true,'tiers'=>[['provider'=>'openai','model'=>'example-mini','enabled'=>true],['provider'=>'gemini','model'=>'example-flash','enabled'=>true]]];
    $key='sk-test-'.str_repeat('a',30);$user=['id'=>1,'username'=>'test-owner'];
    $saved=tt_ai_save_settings($settings,0,$user,$key);
    verify($saved['version']===1&&!isset($saved['credentials'])&&!isset(tt_ai_read_settings()['credentials']),'No secret in public settings');
    verify(tt_ai_provider_key('openai')===$key,'Credential saved privately');
    verify(strpos(implode('',$GLOBALS['audits']),$key)===false,'Secret leaked to audit');
    rejects(fn()=>tt_ai_save_settings($settings,0,$user),'Stale version accepted');
    $saved=tt_ai_save_settings($settings,1,$user);
    verify($saved['version']===2&&tt_ai_provider_key('openai')===$key,'Blank key must preserve credential');
    rejects(fn()=>tt_ai_save_settings($settings,2,$user,'invalid-key'),'Invalid credential accepted');
    verify(tt_ai_read_settings()['version']===2,'Invalid save altered settings');
    echo "Document backend tests passed\n";
}finally{foreach(glob(TT_DATA_DIR.'/*')?:[] as $file)unlink($file);if(is_dir(TT_DATA_DIR))rmdir(TT_DATA_DIR);}
