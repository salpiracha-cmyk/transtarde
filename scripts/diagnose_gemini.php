<?php
declare(strict_types=1);

/** Read-only production Gemini health check. Never prints the API key. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/auth_store.php';

function gemini_health_env(string $name): string {
    foreach (['TT_'.$name,$name] as $envName) {
        $value = getenv($envName);
        if ($value !== false && trim((string)$value) !== '') return trim((string)$value);
    }
    foreach ([dirname(__DIR__) . '/private/env.php', dirname(__DIR__) . '/data/private/env.php'] as $path) {
        if (!is_file($path)) continue;
        $config = require $path;
        if (is_array($config) && isset($config[$name])) return trim((string)$config[$name]);
    }
    if ($name === 'GEMINI_API_KEY') {
        $keyPath = rtrim((string)TT_DATA_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'gemini.key';
        if (is_file($keyPath) && is_readable($keyPath)) return trim((string)file_get_contents($keyPath));
    }
    return '';
}

function gemini_health_out(array $data, int $code = 0): never {
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($code);
}

$key = gemini_health_env('GEMINI_API_KEY');
$model = gemini_health_env('GEMINI_MODEL') ?: 'gemini-2.5-flash';
if ($key === '') gemini_health_out(['ok'=>false,'configured'=>false,'model'=>$model,'error'=>'GEMINI_API_KEY is not configured.'],2);
if (!preg_match('/^[A-Za-z0-9._-]{3,80}$/', $model)) gemini_health_out(['ok'=>false,'configured'=>true,'model'=>'invalid','error'=>'GEMINI_MODEL is invalid.'],2);
if (!function_exists('curl_init')) gemini_health_out(['ok'=>false,'configured'=>true,'model'=>$model,'error'=>'PHP cURL is unavailable.'],2);

$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
$payload = json_encode([
    'contents'=>[['role'=>'user','parts'=>[
        ['text'=>'Inspect the attached image and return JSON with ok=true.'],
        ['inline_data'=>[
            'mime_type'=>'image/png',
            'data'=>'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2M0AAAAASUVORK5CYII=',
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
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>15, CURLOPT_TIMEOUT=>60,
    CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4, CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-goog-api-key: '.$key], CURLOPT_POSTFIELDS=>$payload,
]);
$raw = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$error = curl_error($ch);
curl_close($ch);
if ($raw === false || $error !== '') gemini_health_out(['ok'=>false,'configured'=>true,'model'=>$model,'httpStatus'=>$status,'error'=>'Gemini connection failed.'],2);
$response = json_decode((string)$raw, true);
if (!is_array($response)) {
    $snippet = preg_replace('/\s+/', ' ', trim((string)$raw)) ?? '';
    error_log('Gemini health check returned non-JSON HTTP '.$status.' ('.json_last_error_msg().'): '.mb_substr($snippet, 0, 2000));
    gemini_health_out(['ok'=>false,'configured'=>true,'model'=>$model,'httpStatus'=>$status,'error'=>'Gemini returned an invalid server response.'],2);
}
if ($status < 200 || $status >= 300) {
    $provider = preg_replace('/\s+/', ' ', trim((string)($response['error']['message'] ?? 'Request failed.')));
    gemini_health_out(['ok'=>false,'configured'=>true,'model'=>$model,'httpStatus'=>$status,'error'=>mb_substr($provider,0,240)],2);
}
$text = '';
foreach ((array)($response['candidates'][0]['content']['parts'] ?? []) as $part) {
    if (isset($part['text'])) $text .= (string)$part['text'];
}
$data = $text !== '' ? json_decode($text, true) : null;
if (!is_array($data) || ($data['ok'] ?? false) !== true) {
    gemini_health_out(['ok'=>false,'configured'=>true,'model'=>$model,'httpStatus'=>$status,'error'=>'Gemini multimodal JSON validation failed.'],2);
}
gemini_health_out(['ok'=>true,'configured'=>true,'model'=>$model,'httpStatus'=>$status,'multimodal'=>true,'structuredJson'=>true]);
