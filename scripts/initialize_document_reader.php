<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/auth_store.php';
require dirname(__DIR__).'/api/gemini_runtime.php';
require dirname(__DIR__).'/api/document_ai_settings.php';
try{
    if(!is_file(tt_ai_settings_path())){
        $settings=tt_ai_default_settings();$key=tt_ai_provider_key('gemini');
        // Snapshot the legacy reader's available models once during migration.
        // Subsequent calls obey only the saved, owner-editable tier order.
        if($key!==''&&function_exists('curl_init')){
            $choice=tt_gemini_resolve_model($key,tt_gemini_config_value('GEMINI_MODEL'));
            $settings['tiers']=[$settings['tiers'][0]];
            foreach(array_slice($choice['candidates'],0,3) as $model)$settings['tiers'][]=['provider'=>'gemini','model'=>$model,'enabled'=>true];
        }
        tt_ai_save_settings($settings,0,['id'=>0,'username'=>'deployment']);
    }
    $settings=tt_ai_read_settings();
    echo json_encode(['ok'=>true,'version'=>$settings['version'],'tiers'=>$settings['tiers'],'openaiConfigured'=>tt_ai_provider_key('openai')!=='','geminiConfigured'=>tt_ai_provider_key('gemini')!==''],JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,"Document reader initialization failed. Existing settings were retained.\n");exit(1);}
