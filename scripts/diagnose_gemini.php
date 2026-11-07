<?php
declare(strict_types=1);

/** Read-only production Gemini health check. Never prints the API key. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/auth_store.php';
require dirname(__DIR__) . '/api/gemini_runtime.php';

function gemini_health_out(array $data, int $code = 0): never {
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($code);
}

$key = tt_gemini_config_value('GEMINI_API_KEY');
$configuredModel = tt_gemini_config_value('GEMINI_MODEL');
$model = $configuredModel;
if ($key === '') gemini_health_out(['ok'=>false,'configured'=>false,'model'=>$model,'error'=>'GEMINI_API_KEY is not configured.'],2);
if (!function_exists('curl_init')) gemini_health_out(['ok'=>false,'configured'=>true,'model'=>$model,'error'=>'PHP cURL is unavailable.'],2);
try {
    $choice = tt_gemini_resolve_model($key,$configuredModel);
} catch (Throwable $e) {
    gemini_health_out(['ok'=>false,'configured'=>true,'model'=>$model,'error'=>$e->getMessage()],2);
}
$payload = json_encode([
    'contents'=>[['role'=>'user','parts'=>[
        ['text'=>'Read the attached PDF and return JSON with ok=true.'],
        ['inline_data'=>[
            'mime_type'=>'application/pdf',
            'data'=>'JVBERi0xLjMKJZOMi54gUmVwb3J0TGFiIEdlbmVyYXRlZCBQREYgZG9jdW1lbnQgKG9wZW5zb3VyY2UpCjEgMCBvYmoKPDwKL0YxIDIgMCBSCj4+CmVuZG9iagoyIDAgb2JqCjw8Ci9CYXNlRm9udCAvSGVsdmV0aWNhIC9FbmNvZGluZyAvV2luQW5zaUVuY29kaW5nIC9OYW1lIC9GMSAvU3VidHlwZSAvVHlwZTEgL1R5cGUgL0ZvbnQKPj4KZW5kb2JqCjMgMCBvYmoKPDwKL0NvbnRlbnRzIDcgMCBSIC9NZWRpYUJveCBbIDAgMCAyMDAgMjAwIF0gL1BhcmVudCA2IDAgUiAvUmVzb3VyY2VzIDw8Ci9Gb250IDEgMCBSIC9Qcm9jU2V0IFsgL1BERiAvVGV4dCAvSW1hZ2VCIC9JbWFnZUMgL0ltYWdlSSBdCj4+IC9Sb3RhdGUgMCAvVHJhbnMgPDwKCj4+IAogIC9UeXBlIC9QYWdlCj4+CmVuZG9iago0IDAgb2JqCjw8Ci9QYWdlTW9kZSAvVXNlTm9uZSAvUGFnZXMgNiAwIFIgL1R5cGUgL0NhdGFsb2cKPj4KZW5kb2JqCjUgMCBvYmoKPDwKL0F1dGhvciAoYW5vbnltb3VzKSAvQ3JlYXRpb25EYXRlIChEOjIwMjYwOTE4MTExMTMxLTA0JzAwJykgL0NyZWF0b3IgKGFub255bW91cykgL0tleXdvcmRzICgpIC9Nb2REYXRlIChEOjIwMjYwOTE4MTExMTMxLTA0JzAwJykgL1Byb2R1Y2VyIChSZXBvcnRMYWIgUERGIExpYnJhcnkgLSBcKG9wZW5zb3VyY2VcKSkgCiAgL1N1YmplY3QgKHVuc3BlY2lmaWVkKSAvVGl0bGUgKHVudGl0bGVkKSAvVHJhcHBlZCAvRmFsc2UKPj4KZW5kb2JqCjYgMCBvYmoKPDwKL0NvdW50IDEgL0tpZHMgWyAzIDAgUiBdIC9UeXBlIC9QYWdlcwo+PgplbmRvYmoKNyAwIG9iago8PAovRmlsdGVyIFsgL0FTQ0lJODVEZWNvZGUgL0ZsYXRlRGVjb2RlIF0gL0xlbmd0aCAxMTIKPj4Kc3RyZWFtCkdhcEEvMGFgRmInU0dCIzt1RUNPQlY0bnVNSCdlJVlkbz5DK1M+WjY8QCUmQytZZDVeSUBeYExkOCNGPWtlX2AtZDdSNXRIaSdeKWl1Q1tVM3IyIU9ga1dTJkFSQThpV21IYWpwST1aUWdTPlBwfj5lbmRzdHJlYW0KZW5kb2JqCnhyZWYKMCA4CjAwMDAwMDAwMDAgNjU1MzUgZiAKMDAwMDAwMDA2MSAwMDAwMCBuIAowMDAwMDAwMDkyIDAwMDAwIG4gCjAwMDAwMDAxOTkgMDAwMDAgbiAKMDAwMDAwMDM5MiAwMDAwMCBuIAowMDAwMDAwNDYwIDAwMDAwIG4gCjAwMDAwMDA3MjEgMDAwMDAgbiAKMDAwMDAwMDc4MCAwMDAwMCBuIAp0cmFpbGVyCjw8Ci9JRCAKWzw3NDUyNjNkZmMyN2Q3NzQ2NzE5Y2VhMGEyMzZhZDM0Nz48NzQ1MjYzZGZjMjdkNzc0NjcxOWNlYTBhMjM2YWQzNDc+XQolIFJlcG9ydExhYiBnZW5lcmF0ZWQgUERGIGRvY3VtZW50IC0tIGRpZ2VzdCAob3BlbnNvdXJjZSkKCi9JbmZvIDUgMCBSCi9Sb290IDQgMCBSCi9TaXplIDgKPj4Kc3RhcnR4cmVmCjk4MgolJUVPRgo=',
        ]],
    ]]],
    'generationConfig'=>[
        'temperature'=>0,
        'maxOutputTokens'=>32,
        'responseMimeType'=>'application/json',
        'responseSchema'=>[
            'type'=>'object',
            'properties'=>['ok'=>['type'=>'boolean']],
            'required'=>['ok'],
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$failures=[];
foreach((array)($choice['candidates']??[$choice['model']]) as $model) {
    $url='https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode((string)$model).':generateContent';
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>60,
        CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-goog-api-key: '.$key],CURLOPT_POSTFIELDS=>$payload,
    ]);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
    if($raw===false||$error!=='') {$failures[]=['model'=>$model,'httpStatus'=>$status,'error'=>'Connection failed'];continue;}
    $response=json_decode((string)$raw,true);
    if(!is_array($response)) {$failures[]=['model'=>$model,'httpStatus'=>$status,'error'=>'Invalid server response'];continue;}
    if($status<200||$status>=300) {
        $provider=preg_replace('/\s+/',' ',trim((string)($response['error']['message']??'Request failed.')))??'Request failed.';
        $failures[]=['model'=>$model,'httpStatus'=>$status,'error'=>mb_substr($provider,0,240)];
        if(in_array($status,[404,429,503],true)) continue;
        break;
    }
    $text='';
    foreach((array)($response['candidates'][0]['content']['parts']??[]) as $part) if(isset($part['text'])) $text.=(string)$part['text'];
    $data=$text!==''?json_decode($text,true):null;
    if(!is_array($data)||($data['ok']??false)!==true) {$failures[]=['model'=>$model,'httpStatus'=>$status,'error'=>'PDF JSON validation failed'];continue;}
    gemini_health_out(['ok'=>true,'configured'=>true,'configuredModel'=>$configuredModel,'model'=>$model,'modelVerified'=>(bool)$choice['verified'],'httpStatus'=>$status,'pdfInput'=>true,'structuredJson'=>true,'priorFailures'=>$failures]);
}
$last=$failures[count($failures)-1]??['model'=>$choice['model'],'httpStatus'=>0,'error'=>'No Gemini model succeeded.'];
gemini_health_out(['ok'=>false,'configured'=>true,'configuredModel'=>$configuredModel,'model'=>$last['model'],'httpStatus'=>$last['httpStatus'],'error'=>$last['error'],'attempts'=>$failures],2);
