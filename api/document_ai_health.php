<?php
declare(strict_types=1);
require_once __DIR__.'/gemini_runtime.php';
require_once __DIR__.'/document_ai_settings.php';
function tt_ai_health_path():string{return TT_DATA_DIR.'/document-ai-health.json';}
function tt_ai_health_context(string $provider,array $settings):array{
    $key=tt_ai_provider_key($provider);$model='';
    foreach($settings['tiers'] as $tier)if($tier['provider']===$provider&&$tier['enabled']){$model=$tier['model'];break;}
    return ['key'=>$key,'model'=>$model,'fingerprint'=>hash('sha256',$provider."\0".$key."\0".$model)];
}
function tt_ai_health_statuses():array{
    $settings=tt_ai_read_settings();$stored=[];
    if(is_file(tt_ai_health_path())){$data=json_decode((string)file_get_contents(tt_ai_health_path()),true);if(is_array($data))$stored=$data;}
    $out=[];
    foreach(['openai','gemini'] as $provider){
        $context=tt_ai_health_context($provider,$settings);$row=$stored[$provider]??[];
        $status=['linked'=>false,'label'=>'Not configured','model'=>$context['model'],'checkedAt'=>'','returnedModel'=>''];
        if($context['key']!==''){
            $status['label']=$context['model']===''?'No enabled tier':'Saved — not verified';
            if($context['model']!==''&&hash_equals($context['fingerprint'],(string)($row['fingerprint']??''))){
                $status['checkedAt']=(string)($row['checkedAt']??'');
                $status['returnedModel']=(string)($row['returnedModel']??'');
                $recent=(int)($row['checkedTimestamp']??0)>=time()-86400;
                $status['linked']=!empty($row['linked'])&&$recent;
                $status['label']=$recent?($status['linked']?'Linked — verified':(string)($row['label']??'Check failed')):'Recheck connection';
            }
        }
        $out[$provider]=$status;
    }
    return $out;
}
function tt_ai_check_connections(?callable $extract=null):array{
    if(!defined('TT_DOCUMENT_AI_FUNCTIONS_ONLY'))define('TT_DOCUMENT_AI_FUNCTIONS_ONLY',true);
    require_once __DIR__.'/document_ai.php';
    $settings=tt_ai_read_settings();$schema=ai_contract_schema();
    $parts=[['text'=>ai_prompt('contract')],['inline_data'=>['mime_type'=>'application/pdf','data'=>require __DIR__.'/document_ai_sample.php']]];
    $out=[];
    foreach(['openai','gemini'] as $provider){
        $context=tt_ai_health_context($provider,$settings);
        $row=['linked'=>false,'label'=>'Not configured','model'=>$context['model'],'fingerprint'=>$context['fingerprint'],'checkedAt'=>gmdate('c'),'checkedTimestamp'=>time(),'returnedModel'=>''];
        if($context['key']!==''&&$context['model']!==''){
            try{
                $result=$extract?$extract($provider,$context['key'],$context['model'],$parts,$schema):($provider==='openai'?tt_ai_call_openai($context['key'],$context['model'],$parts,$schema):ai_call_gemini($context['key'],$context['model'],$parts,$schema));
                $data=$result['data']??null;
                if(!ai_validate_extraction($data,$schema)||strtoupper(trim($data['ref']))!=='TT-CONNECTION-CHECK'||strtoupper(trim($data['buyer']))!=='TRANSTRADE CONNECTION TEST')throw new RuntimeException('Sample extraction failed validation.');
                $row['linked']=true;$row['label']='Linked — verified';$row['returnedModel']=(string)($result['returnedModel']??$context['model']);
            }catch(Throwable $e){
                $row['label']=match($e->getCode()){401,403=>'Key rejected or access denied',404=>'Selected model unavailable',429=>'Quota or rate limit reached',default=>'Connection or document check failed'};
            }
        }elseif($context['key']!=='')$row['label']='No enabled tier';
        $out[$provider]=$row;
    }
    tt_ensure_data_dir();$temp=TT_DATA_DIR.'/.document-ai-health.'.bin2hex(random_bytes(8)).'.tmp';
    if(file_put_contents($temp,json_encode($out,JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('Could not save connection check.');
    chmod($temp,0600);
    if(!rename($temp,tt_ai_health_path())){unlink($temp);throw new RuntimeException('Could not save connection check.');}
    return tt_ai_health_statuses();
}
