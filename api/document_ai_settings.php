<?php
declare(strict_types=1);
// Credentials stay in the private data directory and are excluded from public settings and audit output.
function tt_ai_settings_path(): string { return TT_DATA_DIR.'/document-ai.json'; }
function tt_ai_provider_key(string $provider): string {
    if(!in_array($provider,['openai','gemini'],true))throw new InvalidArgumentException('Unknown provider.');
    if(is_file(tt_ai_settings_path())) {
        $raw=json_decode((string)file_get_contents(tt_ai_settings_path()),true,512,JSON_THROW_ON_ERROR);
        $key=trim((string)($raw['credentials'][$provider]??''));
        if($key!=='')return $key;
    }
    return tt_gemini_config_value($provider==='openai'?'OPENAI_API_KEY':'GEMINI_API_KEY');
}
function tt_ai_default_settings(): array {
    return ['version'=>0,'enabled'=>true,'fallback'=>true,'tiers'=>[['provider'=>'openai','model'=>'gpt-5.4-mini','enabled'=>true],['provider'=>'gemini','model'=>tt_gemini_config_value('GEMINI_MODEL')?:'gemini-3.5-flash-lite','enabled'=>true]]];
}
function tt_ai_validate_settings(array $input): array {
    $tiers=$input['tiers']??[];
    if(!is_array($tiers)||count($tiers)<1||count($tiers)>20) throw new InvalidArgumentException('Choose between 1 and 20 tiers.');
    $out=[];$seen=[];
    foreach($tiers as $tier) {
        if(!is_array($tier)) throw new InvalidArgumentException('Invalid tier.');
        $provider=(string)($tier['provider']??'');$model=trim((string)($tier['model']??''));
        if(!in_array($provider,['openai','gemini'],true)||!preg_match('/^[A-Za-z0-9._-]{3,100}$/D',$model)) throw new InvalidArgumentException('Choose OpenAI or Gemini and enter a valid model ID.');
        $identity=$provider.':'.$model;
        if(isset($seen[$identity])) throw new InvalidArgumentException('The same provider and model cannot appear twice.');
        $seen[$identity]=true;$out[]=['provider'=>$provider,'model'=>$model,'enabled'=>!empty($tier['enabled'])];
    }
    if(!empty($input['enabled'])&&!array_filter($out,fn($t)=>$t['enabled'])) throw new InvalidArgumentException('Enable at least one tier or turn document reading off.');
    return ['enabled'=>!empty($input['enabled']),'fallback'=>!empty($input['fallback']),'tiers'=>$out];
}
function tt_ai_read_settings(): array {
    $path=tt_ai_settings_path();
    if(!is_file($path))return tt_ai_default_settings();
    $data=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($data))throw new RuntimeException('Document reader settings are damaged.');
    return tt_ai_validate_settings($data)+['version'=>(int)($data['version']??0)];
}
function tt_ai_save_settings(array $input,int $expected,array $user,string $openaiKey='',string $geminiKey=''): array {
    $validated=tt_ai_validate_settings($input);
    $openaiKey=trim($openaiKey);
    if($openaiKey!==''&&!preg_match('/^sk-[A-Za-z0-9_-]{20,500}$/D',$openaiKey))throw new InvalidArgumentException('Enter a valid OpenAI API key.');
    $geminiKey=trim($geminiKey);
    if($geminiKey!==''&&!preg_match('/^[A-Za-z0-9_-]{20,200}$/D',$geminiKey))throw new InvalidArgumentException('Enter a valid Gemini API key.');
    tt_ensure_data_dir();
    $lock=fopen(TT_DATA_DIR.'/document-ai.lock','c');
    if($lock===false||!flock($lock,LOCK_EX))throw new RuntimeException('Could not lock reader settings.');
    try {
        $current=tt_ai_read_settings();
        if($current['version']!==$expected)throw new RuntimeException('Settings changed in another tab. Reopen this page before saving.');
        $next=$validated+['version'=>$expected+1];
        $credentials=[];
        if(is_file(tt_ai_settings_path())) {
            $stored=json_decode((string)file_get_contents(tt_ai_settings_path()),true,512,JSON_THROW_ON_ERROR);
            $credentials=is_array($stored['credentials']??null)?$stored['credentials']:[];
        }
        if($openaiKey!=='')$credentials['openai']=$openaiKey;
        if($geminiKey!=='')$credentials['gemini']=$geminiKey;
        $storedNext=$next+['credentials'=>$credentials];
        $temp=TT_DATA_DIR.'/.document-ai.'.bin2hex(random_bytes(8)).'.tmp';
        if(file_put_contents($temp,json_encode($storedNext,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT))===false)throw new RuntimeException('Could not write reader settings.');
        chmod($temp,0600);
        if(!rename($temp,tt_ai_settings_path())){unlink($temp);throw new RuntimeException('Could not save reader settings.');}
    }finally{flock($lock,LOCK_UN);fclose($lock);}
    tt_audit((int)$user['id'],(string)$user['username'],'Document reader settings updated: '.json_encode($next));
    return $next;
}
function tt_ai_route(array $settings,callable $attempt): array {
    if(!$settings['enabled'])throw new RuntimeException('Document reading is turned off. Use manual entry.');
    $attempts=[];$deadline=microtime(true)+120;
    foreach($settings['tiers'] as $i=>$tier) {
        if(!$tier['enabled'])continue;
        if(microtime(true)>=$deadline)break;
        try {
            $result=$attempt($tier);
            return $result+['provider'=>$tier['provider'],'model'=>$tier['model'],'tier'=>$i+1,'attempts'=>$attempts];
        }catch(RuntimeException $e){
            error_log('Document reader tier '.($i+1).' failed: '.$tier['provider'].' / '.$tier['model'].' code '.$e->getCode());
            $attempts[]=['tier'=>$i+1,'provider'=>$tier['provider'],'model'=>$tier['model'],'status'=>'failed','code'=>$e->getCode()];
            if(!$settings['fallback'])break;
        }
    }
    throw new RuntimeException('All selected document readers failed. Use manual entry or ask Super Admin to check the reader settings.');
}
