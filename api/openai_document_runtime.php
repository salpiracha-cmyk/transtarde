<?php
declare(strict_types=1);
function tt_ai_strict_schema(array $schema): array {
    if(($schema['type']??'')==='object') {
        $schema['additionalProperties']=false;
        $schema['required']=array_keys($schema['properties']);
        foreach($schema['properties'] as &$p)$p=tt_ai_strict_schema($p);unset($p);
    }elseif(($schema['type']??'')==='array')$schema['items']=tt_ai_strict_schema($schema['items']);
    return $schema;
}
function tt_ai_openai_content(array $parts): array {
    $content=[];
    foreach($parts as $part){
        if(isset($part['text'])){$content[]=['type'=>'input_text','text'=>$part['text']];continue;}
        $file=$part['inline_data'];$mime=$file['mime_type'];$url='data:'.$mime.';base64,'.$file['data'];
        if($mime==='application/pdf')$content[]=['type'=>'input_file','filename'=>'document.pdf','file_data'=>$url,'detail'=>'high'];
        else $content[]=['type'=>'input_image','image_url'=>$url,'detail'=>'high'];
    }
    return $content;
}
function tt_ai_call_openai(string $key,string $model,array $parts,array $schema): array {
    $payload=['model'=>$model,'store'=>false,'max_output_tokens'=>6000,
        'input'=>[['role'=>'user','content'=>tt_ai_openai_content($parts)]],
        'text'=>['format'=>['type'=>'json_schema','name'=>'contract_fields','strict'=>true,'schema'=>tt_ai_strict_schema($schema)]]];
    $ch=curl_init('https://api.openai.com/v1/responses');
    if($ch===false)throw new RuntimeException('OpenAI connection failed.');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$key],
        CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR)]);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if($raw===false||$status<200||$status>=300){
        $message=match($status){401,403=>'OpenAI credentials are not authorized.',404=>'The selected OpenAI model is unavailable.',429=>'OpenAI quota or rate limit reached.',default=>'OpenAI document request failed.'};
        throw new RuntimeException($message,$status);
    }
    return tt_ai_parse_openai_response((string)$raw,$model,$schema);
}
function tt_ai_parse_openai_response(string $raw,string $model,array $schema): array {
    $response=json_decode($raw,true);
    if(($response['status']??'')!=='completed')throw new RuntimeException('OpenAI extraction did not complete.');
    $text='';foreach(($response['output']??[]) as $item)foreach(($item['content']??[]) as $part){
        if(($part['type']??'')==='refusal')throw new RuntimeException('OpenAI declined extraction.');
        if(($part['type']??'')==='output_text')$text.=$part['text'];
    }
    $data=json_decode($text,true);
    if(!ai_validate_extraction($data,$schema))throw new RuntimeException('OpenAI returned an unreadable extraction.');
    return ['data'=>ai_normalize_schema($data,$schema),'returnedModel'=>(string)($response['model']??$model),'usage'=>$response['usage']??[]];
}
