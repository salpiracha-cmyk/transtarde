<?php
declare(strict_types=1);

require __DIR__ . '/../runtime_html.php';

function check(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException($message);
}

$html=(string)file_get_contents(__DIR__.'/../exports/index.html');
$js=(string)file_get_contents(__DIR__.'/../exports/app.js');
foreach(['TTI_header.png','TTI_sign.png','BRM_header.png','BRM_sign.png','TG_header.png','TG_footer.png','TG_sign.png'] as $asset){
    $path=__DIR__.'/../exports/assets/'.$asset;
    $mime='image/png';
    $js=str_replace('assets/'.$asset,'data:'.$mime.';base64,'.base64_encode((string)file_get_contents($path)),$js);
}
$expected=str_replace('</script','<\/script',$js);
$served=tt_replace_html_once(
    '~<script\b[^>]*src=["\'](?:exports/)?app\.js[^"\']*["\'][^>]*>\s*</script>~i',
    static fn():string=>'<script id="exports-app-js">'.$expected.'</script>',
    $html
);
$open='<script id="exports-app-js">';
$start=strpos($served,$open);$end=$start===false?false:strpos($served,'</script>',$start+strlen($open));
check($start!==false&&$end!==false,'The served Export script was not found.');
$actual=substr($served,$start+strlen($open),$end-($start+strlen($open)));
check(hash_equals(hash('sha256',$expected),hash('sha256',$actual)),'PHP changed Export JavaScript while assembling the page.');
check(str_contains($actual,'$1<div class="commercialCopyLabel">'),'Commercial copy title replacement was corrupted.');
check(str_contains($actual,"\\\\s*"),'JavaScript regular-expression backslashes were corrupted.');

$probe='<html><head data-x="1"></head><body></body></html>';
$bootstrap='<script>window.TEST={"name":"A $1 \\\\ Staff"};</script>';
$probe=tt_replace_html_once('/<head(\s[^>]*)?>/i',static fn(array $m):string=>$m[0].$bootstrap,$probe);
check(str_contains($probe,$bootstrap),'Accounts bootstrap content was interpreted as a replacement string.');

echo "PASS literal runtime delivery: PHP preserves JavaScript, JSON, dollars and backslashes.\n";
