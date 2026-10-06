<?php
declare(strict_types=1);
require __DIR__.'/../runtime_html.php';
require __DIR__.'/../exports/runtime_assets.php';
function check(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException($message);
}
$assets=['TTI_header.png','TTI_sign.png','BRM_header.png','BRM_sign.png','TG_header.png','TG_footer.png','TG_sign.png','KCCI_COO_letterpad.jpg'];
$raw=(string)file_get_contents(__DIR__.'/../exports/app.js');
$expected=$raw;
foreach($assets as $asset){
    $path=__DIR__.'/../exports/assets/'.$asset;
    $expected=str_replace('assets/'.$asset,'/exports/assets/'.$asset.'?v='.substr(hash_file('sha256',$path),0,16),$expected);
}
$actual=tt_export_runtime_script();
check(hash_equals(hash('sha256',$expected),hash('sha256',$actual)),'Runtime delivery changed JavaScript beyond the artwork URLs.');
check(str_contains($actual,'</div>$1'),'Commercial copy title replacement was corrupted.');
check(strlen($actual)<strlen($raw)+1000,'Runtime delivery embedded artwork bytes.');
$fixture=sys_get_temp_dir().'/tt-runtime-'.bin2hex(random_bytes(6));
mkdir($fixture);mkdir($fixture.'/assets');
try{
    file_put_contents($fixture.'/app.js',$raw);
    foreach($assets as $asset)file_put_contents($fixture.'/assets/'.$asset,'original artwork '.$asset);
    $before=tt_export_runtime_script($fixture);
    file_put_contents($fixture.'/assets/TG_sign.png','updated signature');
    $after=tt_export_runtime_script($fixture);
    check($before!==$after,'Changing artwork must invalidate its runtime URL.');
    unlink($fixture.'/assets/TG_sign.png');
    $failed=false;
    try{tt_export_runtime_script($fixture);}catch(RuntimeException $e){$failed=true;}
    check($failed,'Missing artwork must fail closed.');
}finally{
    foreach($assets as $asset)if(is_file($fixture.'/assets/'.$asset))unlink($fixture.'/assets/'.$asset);
    rmdir($fixture.'/assets');unlink($fixture.'/app.js');rmdir($fixture);
}
$probe='<html><head data-x="1"></head><body></body></html>';
$bootstrap='<script>window.TEST={"name":"A $1 \\\\ Staff"};</script>';
$probe=tt_replace_html_once('/<head(\\s[^>]*)?>/i',static fn(array $m):string=>$m[0].$bootstrap,$probe);
check(str_contains($probe,$bootstrap),'Accounts bootstrap content was interpreted as a replacement string.');
echo "PASS runtime delivery: literal JavaScript, original artwork URL versions, missing-asset failure and bootstrap escaping.\\n";
