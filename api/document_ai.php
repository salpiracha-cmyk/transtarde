<?php
declare(strict_types=1);
if(!defined('TT_DOCUMENT_AI_FUNCTIONS_ONLY')||!TT_DOCUMENT_AI_FUNCTIONS_ONLY){
    require dirname(__DIR__) . '/auth_store.php';
    require dirname(__DIR__) . '/upload_validation.php';
}
require __DIR__ . '/gemini_runtime.php';
require __DIR__ . '/document_ai_settings.php';
require __DIR__ . '/openai_document_runtime.php';

function ai_respond(array $data,int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}
function ai_env(string $name): string {
    return tt_gemini_config_value($name);
}
function ai_can_write(array $user): bool {
    if(($user['role']??'')==='Super Admin') return true;
    $g=$user['permissions']['Exports']??[];
    if($g==='all') return true;
    if(!is_array($g)) return false;
    if(in_array('Create',$g,true)||in_array('Edit',$g,true)) return true;
    foreach(['contracts','contract','fi','active'] as $key){
        $a=$g[$key]??[];
        if($a==='all'||(is_array($a)&&(in_array('Create',$a,true)||in_array('Edit',$a,true)))) return true;
    }
    return false;
}
function ai_nullable_string(): array {return ['type'=>'string'];}
function ai_nullable_number(): array {return ['type'=>'number'];}
function ai_contract_schema(): array {
    return [
        'type'=>'object',
        'properties'=>[
            'buyer'=>ai_nullable_string(),'buyerAddress'=>ai_nullable_string(),'ref'=>ai_nullable_string(),'buyerPoNo'=>ai_nullable_string(),
            'date'=>ai_nullable_string(),'product'=>ai_nullable_string(),'qty'=>ai_nullable_number(),'containers'=>ai_nullable_number(),
            'size'=>ai_nullable_number(),'unit'=>['type'=>'string','enum'=>['KG','LB','']],'type'=>ai_nullable_string(),'brand'=>ai_nullable_string(),
            'tare'=>ai_nullable_number(),'price'=>ai_nullable_number(),'currency'=>ai_nullable_string(),'incoterm'=>['type'=>'string','enum'=>['FOB','CFR','CIF','']],
            'pol'=>ai_nullable_string(),'podPort'=>ai_nullable_string(),'destPort'=>ai_nullable_string(),'shipmentDate'=>ai_nullable_string(),
            'paymentCode'=>['type'=>'string','enum'=>['ADV_SCAN','ADV_CAD','ADV100','LC_SIGHT','LC_USANCE','CAD100','CUSTOM','']],
            'advancePct'=>ai_nullable_number(),'usanceDays'=>ai_nullable_number(),'customPayment'=>ai_nullable_string(),'quality'=>ai_nullable_string(),
            'packings'=>[
                'type'=>'array','items'=>[
                    'type'=>'object','properties'=>[
                        'type'=>ai_nullable_string(),'size'=>ai_nullable_number(),'unit'=>['type'=>'string','enum'=>['KG','LB','']],
                        'brand'=>ai_nullable_string(),'tare'=>ai_nullable_number(),'containers'=>ai_nullable_number(),'weightPer'=>ai_nullable_number(),
                        'price'=>ai_nullable_number(),'freight'=>ai_nullable_number(),'insurance'=>ai_nullable_number(),'extraBagPct'=>ai_nullable_number(),
                        'masterBag'=>['type'=>'object','properties'=>['enabled'=>['type'=>'boolean'],'qty'=>ai_nullable_number(),'tare'=>ai_nullable_number()],'required'=>['enabled','qty','tare']]
                    ],
                    'required'=>['type','size','unit','brand','tare','containers','weightPer','price','freight','insurance','extraBagPct','masterBag']
                ]
            ],
            'notes'=>['type'=>'array','items'=>['type'=>'string']]
        ],
        'required'=>['buyer','buyerAddress','ref','buyerPoNo','date','product','qty','containers','size','unit','type','brand','tare','price','currency','incoterm','pol','podPort','destPort','shipmentDate','paymentCode','advancePct','usanceDays','customPayment','quality','packings','notes']
    ];
}
function ai_prompt(string $kind): string {
    return <<<'PROMPT'
You are extracting an uploaded Sales Contract or P/C for an export-document workflow.
Read the entire document visually and textually, including tables, scans, stamps and continuation pages.
Return only the requested structured JSON fields.
Rules:
- Never guess. If a value is not stated, return an empty string, 0, or an empty array as appropriate.
- Dates must be YYYY-MM-DD when an exact date is stated; otherwise return an empty string.
- Quantity is total metric tons. containers is the total number of containers.
- price is the unit price per metric ton, not the total contract value.
- Normalize C&F/CNF to CFR. Use only FOB, CFR or CIF when explicitly supported by the contract.
- paymentCode mapping: ADV100=100% advance; CAD100=100% CAD; ADV_CAD=advance plus CAD; ADV_SCAN=advance plus balance against scan copies; LC_SIGHT=sight L/C; LC_USANCE=usance L/C; CUSTOM=other clearly stated terms.
- Extract every packing type separately. Keep bag size, unit, brand/marking, tare, number of containers and master-bag details separate.
- Do not invent a product, buyer address, port, quality, packing or payment term.
- notes should list material ambiguities or fields that require human checking.
This extraction is only a proposal for human review.
PROMPT;
}
function ai_part_from_upload(array $file): array {
    $error=(int)($file['error']??UPLOAD_ERR_NO_FILE);
    if($error!==UPLOAD_ERR_OK) throw new InvalidArgumentException('Choose a readable document.');
    $size=(int)($file['size']??0);
    if($size<1||$size>12*1024*1024) throw new InvalidArgumentException('Document must be 12 MB or smaller.');
    $tmp=(string)($file['tmp_name']??'');
    if(!is_uploaded_file($tmp)||filesize($tmp)!==$size)throw new InvalidArgumentException('The uploaded document could not be verified.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp)?:'';
    $allowed=['application/pdf','image/png','image/jpeg','image/webp','text/plain','text/csv','text/rtf','text/html'];
    if(!in_array($mime,$allowed,true)) throw new InvalidArgumentException('Document reader accepts PDF, PNG, JPG, WebP or text documents. DOCX can still be read through the local fallback.');
    if(!str_starts_with($mime,'text/')&&!tt_upload_signature_valid($tmp,$mime))throw new InvalidArgumentException('Upload a valid PDF, PNG, JPG or WebP document.');
    $raw=file_get_contents($tmp);
    if($raw===false) throw new RuntimeException('The uploaded document could not be read.');
    if(str_starts_with($mime,'text/')) return ['text'=>(string)$raw];
    return ['inline_data'=>['mime_type'=>$mime,'data'=>base64_encode($raw)]];
}
function ai_schema_example(array $schema): mixed {
    $type=(string)($schema['type']??'string');
    if($type==='object') {
        $out=[];
        foreach((array)($schema['properties']??[]) as $name=>$property) $out[(string)$name]=ai_schema_example((array)$property);
        return $out;
    }
    if($type==='array') return [];
    if($type==='number'||$type==='integer') return 0;
    if($type==='boolean') return false;
    return '';
}
function ai_validate_extraction(mixed $value,array $schema): bool {
    switch($schema['type']??'string'){
        case 'object':
            if(!is_array($value))return false;
            foreach(($schema['properties']??[]) as $name=>$property)if(!array_key_exists($name,$value)||!ai_validate_extraction($value[$name],$property))return false;
            return true;
        case 'array':
            if(!is_array($value)||!array_is_list($value))return false;
            foreach($value as $item)if(!ai_validate_extraction($item,$schema['items']))return false;
            return true;
        case 'number':case 'integer':return (is_int($value)||is_float($value))&&is_finite((float)$value);
        case 'boolean':return is_bool($value);
        default:return is_string($value)&&(!isset($schema['enum'])||in_array($value,$schema['enum'],true));
    }
}
function ai_normalize_schema(mixed $value,array $schema): mixed {
    $type=(string)($schema['type']??'string');
    if($type==='object') {
        $source=is_array($value)?$value:[];$out=[];
        foreach((array)($schema['properties']??[]) as $name=>$property) $out[(string)$name]=ai_normalize_schema($source[$name]??null,(array)$property);
        return $out;
    }
    if($type==='array') {
        if(!is_array($value)) return [];
        return array_values(array_map(fn($item)=>ai_normalize_schema($item,(array)($schema['items']??[])),$value));
    }
    if($type==='number'||$type==='integer') return is_numeric($value)?(float)$value:0;
    if($type==='boolean') return filter_var($value,FILTER_VALIDATE_BOOLEAN);
    $text=is_scalar($value)?trim((string)$value):'';
    $enum=(array)($schema['enum']??[]);
    if($enum&&!in_array($text,$enum,true)) return in_array('',$enum,true)?'':(string)($enum[0]??'');
    return $text;
}
function ai_gemini_request(string $url,string $key,array $payload): array {
    $ch=curl_init($url);
    if($ch===false) throw new RuntimeException('Gemini connection could not be initialized.');
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>60,
        CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-goog-api-key: '.$key],
        CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
    ]);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);
    if($raw===false||$err!=='') throw new RuntimeException('Gemini request failed: '.$err);
    $response=json_decode((string)$raw,true);
    if(!is_array($response)) {
        $snippet=preg_replace('/\s+/',' ',trim((string)$raw))??'';
        error_log('Gemini document reader returned non-JSON HTTP '.$status.' ('.json_last_error_msg().'): '.mb_substr($snippet,0,2000));
        return ['status'=>$status,'response'=>[],'invalidJson'=>true];
    }
    return ['status'=>$status,'response'=>$response,'invalidJson'=>false];
}
function ai_call_gemini(string $key,string $model,array $parts,array $schema): array {
    $url='https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent';
    $guide="Return JSON only, using exactly these fields and value types. Use empty strings, zero, false or empty arrays where the document is silent:\n".json_encode(ai_schema_example($schema),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $guidedParts=$parts;
    $guidedParts[0]['text']=(string)($guidedParts[0]['text']??'')."\n\n".$guide;
    $attempts=[
        ['parts'=>$parts,'config'=>['temperature'=>0,'responseMimeType'=>'application/json','responseSchema'=>$schema]],
        ['parts'=>$guidedParts,'config'=>['temperature'=>0,'responseMimeType'=>'application/json']],
        ['parts'=>$guidedParts,'config'=>['temperature'=>0]],
    ];
    $response=[];$status=0;
    foreach($attempts as $index=>$attempt){
        $result=ai_gemini_request($url,$key,['contents'=>[['role'=>'user','parts'=>$attempt['parts']]],'generationConfig'=>$attempt['config']]);
        $status=(int)$result['status'];$response=is_array($result['response'])?$result['response']:[];
        if(($result['invalidJson']??false)===true) {
            throw new RuntimeException('Gemini returned an invalid server response. Please retry.',$status);
        }
        if($status>=200&&$status<300) break;
        $message=(string)($response['error']['message']??'Gemini could not read this document.');
        error_log('Gemini document reader attempt '.($index+1).' HTTP '.$status.': '.$message);
        if($status===400&&$index<count($attempts)-1) continue;
        error_log('Gemini document reader HTTP '.$status.': '.$message);
        $safeMessage=match($status){
            400=>'Gemini rejected the document request. Check the file and try again.',
            401,403=>'Gemini server credentials are not authorized. Ask Super Admin to check the protected API key.',
            404=>'The configured Gemini model is unavailable. Ask Super Admin to check GEMINI_MODEL.',
            429=>'Gemini usage limit reached. Try again shortly.',
            default=>'Gemini could not read this document.',
        };
        throw new RuntimeException($safeMessage,$status);
    }
    $text='';
    foreach((array)($response['candidates'][0]['content']['parts']??[]) as $part){if(isset($part['text']))$text.=(string)$part['text'];}
    $text=preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/i','',trim($text))??trim($text);
    $data=$text!==''?json_decode($text,true):null;
    if(!ai_validate_extraction($data,$schema)) {
        $providerBody=json_encode($response,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        error_log('Gemini extraction JSON parse failed ('.json_last_error_msg().'): '.mb_substr((string)$providerBody,0,4000));
        throw new RuntimeException('Gemini returned an unreadable extraction. Please retry.');
    }
    return ['data'=>ai_normalize_schema($data,$schema),'returnedModel'=>(string)($response['modelVersion']??$model),'usage'=>$response['usageMetadata']??[]];
}

if(defined('TT_DOCUMENT_AI_FUNCTIONS_ONLY')&&TT_DOCUMENT_AI_FUNCTIONS_ONLY)return;

try{
    $user=tt_current_user();
    if($user&&tt_managed_qa_write_blocked($user)) ai_respond(['ok'=>false,'error'=>'The production QA account is read-only.'],403);
    if(!$user){$_SESSION=[];ai_respond(['ok'=>false,'error'=>'Your login session expired. Please sign in again.'],401);}
    if(!tt_user_can_open_module($user,'Exports')) ai_respond(['ok'=>false,'error'=>'Exports access is required.'],403);
    if($_SERVER['REQUEST_METHOD']!=='POST') ai_respond(['ok'=>false,'error'=>'Method not allowed.'],405);
    if(!ai_can_write($user)) ai_respond(['ok'=>false,'error'=>'Create or Edit permission is required.'],403);
    if(!tt_verify_csrf((string)($_POST['csrf']??''))) ai_respond(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
    $kind=strtolower(trim((string)($_POST['kind']??'')));
    if(!in_array($kind,['contract','pc'],true)) ai_respond(['ok'=>false,'error'=>'AI reading is restricted to Sales Contract and P/C uploads.'],422);
    $settings=tt_ai_read_settings();
    $parts=[['text'=>ai_prompt($kind)]];
    $text=trim((string)($_POST['text']??''));
    if($text!==''){
        if(strlen($text)>250000) ai_respond(['ok'=>false,'error'=>'Pasted text is too long. Upload the original PDF instead.'],413);
        $parts[]=['text'=>$text];
    }elseif(isset($_FILES['file'])&&is_array($_FILES['file'])){
        $parts[]=ai_part_from_upload($_FILES['file']);
    }else{
        ai_respond(['ok'=>false,'error'=>'Choose a document or paste its text.'],422);
    }
    tt_release_read_session();
    $result=tt_ai_route($settings,function(array $tier)use($parts){
        $key=tt_ai_provider_key($tier['provider']);
        if($key==='')throw new RuntimeException('Provider credentials are missing.');
        if($tier['provider']==='openai')return tt_ai_call_openai($key,$tier['model'],$parts,ai_contract_schema());
        return ai_call_gemini($key,$tier['model'],$parts,ai_contract_schema());
    });
    tt_audit(isset($user['id'])?(int)$user['id']:null,(string)($user['username']??'user'),
        'Document '.$kind.' extracted for review: '.$result['provider'].' / '.$result['model'].' (returned '.($result['returnedModel']??$result['model']).') tier '.$result['tier'].' usage '.json_encode($result['usage']??[]));
    ai_respond(['ok'=>true]+$result);
}catch(InvalidArgumentException $e){ai_respond(['ok'=>false,'error'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('Transtrade document reader: '.$e->getMessage());
    $safeMessages=[
        'Document reading is turned off. Use manual entry.',
        'All selected document readers failed. Use manual entry or ask Super Admin to check the reader settings.',
        'Gemini rejected the document request. Check the file and try again.',
        'Gemini server credentials are not authorized. Ask Super Admin to check the protected API key.',
        'The configured Gemini model is unavailable. Ask Super Admin to check GEMINI_MODEL.',
        'Gemini usage limit reached. Try again shortly.',
        'Gemini could not read this document.',
        'Gemini connection could not be initialized.',
        'Gemini returned an invalid server response. Please retry.',
        'Gemini returned an unreadable extraction. Please retry.',
        'No Gemini document-capable model is available for the configured API key.',
    ];
    $message=in_array($e->getMessage(),$safeMessages,true)?$e->getMessage():'AI document reading is temporarily unavailable. Use the local fallback or try again.';
    $status=in_array($e->getCode(),[400,401,403,404,429],true)?$e->getCode():500;
    ai_respond(['ok'=>false,'error'=>$message,'code'=>'DOCUMENT_AI_REQUEST_FAILED'],$status);
}
