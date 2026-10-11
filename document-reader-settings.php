<?php
declare(strict_types=1);
require __DIR__.'/auth_store.php';
require_once __DIR__.'/runtime_html.php';
require __DIR__.'/api/gemini_runtime.php';
require __DIR__.'/api/document_ai_settings.php';
$user=tt_require_login();
if(($user['role']??'')!=='Super Admin'){http_response_code(403);exit('Super Admin access required.');}
header('Cache-Control: private, no-store');
$error='';$saved=isset($_GET['saved']);$settings=tt_ai_read_settings();
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!tt_verify_csrf((string)($_POST['csrf']??'')))throw new RuntimeException('Your session expired. Refresh and try again.');
        if(tt_managed_qa_write_blocked($user))throw new RuntimeException('This account is read-only.');
        $input=['enabled'=>isset($_POST['enabled']),'fallback'=>isset($_POST['fallback']),'tiers'=>json_decode((string)($_POST['tiers']??''),true,512,JSON_THROW_ON_ERROR)];
        $settings=tt_ai_save_settings($input,(int)($_POST['version']??-1),$user,(string)($_POST['openai_api_key']??''));$saved=true;
        header('Location: document-reader-settings.php?saved=1');exit;
    }catch(Throwable $e){$error=$e instanceof JsonException?'Invalid settings.':$e->getMessage();}
}
function ai_h(string $s): string{return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
ob_start();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Document Reader Settings | Transtrade</title><link rel="stylesheet" href="admin/document-reader.css"></head>
<body><header><a href="index.php">TT · TRANSTRADE</a><span>Super Admin <a href="logout.php" aria-label="Sign out">⏻</a></span></header>
<main><a href="index.php">← Control Centre</a><h1>Document Reader Settings</h1><p>Sales Contracts and customer Proforma Invoices only.</p>
<?php if($error):?><p role="alert" class="error"><?=ai_h($error)?></p><?php endif;?>
<?php if($saved):?><p role="status">Reader settings saved.</p><?php endif;?>
<form method="post" id="readerForm"><input type="hidden" name="csrf" value="<?=ai_h(tt_csrf())?>"><input type="hidden" name="version" value="<?=$settings['version']?>"><input type="hidden" name="tiers" id="tiersPayload" value="<?=ai_h(json_encode($settings['tiers'],JSON_THROW_ON_ERROR))?>">
<section><label class="tick"><input type="checkbox" name="enabled" <?=$settings['enabled']?'checked':''?>>Enable document reading</label><label class="tick"><input type="checkbox" name="fallback" <?=$settings['fallback']?'checked':''?>>Try the next enabled tier if a reader fails</label></section>
<section><h2>Reader order</h2><p>Tier 1 runs first. Each tier uses the exact provider and model you select.</p><div id="tiers"></div><button type="button" id="addTier">+ Add tier</button></section>
<section><h2>Server credentials</h2><p>OpenAI: <?=ai_env_placeholder('OPENAI_API_KEY')?> · Gemini: <?=ai_env_placeholder('GEMINI_API_KEY')?></p><label class="key-label">OpenAI API key<input type="password" name="openai_api_key" autocomplete="new-password" placeholder="Leave blank to keep the saved key" maxlength="503"></label><p>Enter your key here and press Save settings. It is stored privately on the server and never displayed again. Leave blank to retain it.</p></section>
<button type="submit" class="primary">Save settings</button></form>
<section><h2>When all readers fail</h2><p>Keep the document and use manual entry. Extracted fields always require review before the existing Save action.</p></section></main>
<script>window.READER_SETTINGS=<?=json_encode($settings,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;</script><script src="admin/document-reader-settings.js"></script>
<script>window.TT_SESSION={csrf:<?=json_encode(tt_csrf())?>};</script><script src="session-activity.js"></script></body></html>
<?php echo tt_version_local_assets((string)ob_get_clean());
function ai_env_placeholder(string $name):string{return tt_ai_provider_key($name==='OPENAI_API_KEY'?'openai':'gemini')!==''?'Configured':'Not configured';} ?>
