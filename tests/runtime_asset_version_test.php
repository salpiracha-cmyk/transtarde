<?php
declare(strict_types=1);
require dirname(__DIR__).'/runtime_html.php';
function asset_check(bool $condition,string $message):void { if(!$condition)throw new RuntimeException($message); }
$root=sys_get_temp_dir().'/tt-assets-'.bin2hex(random_bytes(8));
mkdir($root);mkdir($root.'/directors');
try{
    file_put_contents($root.'/app.js','window.value = "$1 \\\\";');
    file_put_contents($root.'/directors/style.css','body{color:green}');
    $inline='<script>const markup="<link href=\'/app.js?v=old\'>";'.str_repeat('/* literal $1 \\ */',80000).'</script>';
    $html='<script src="/app.js?v=old&amp;fix=keep"></script>'.$inline.'<link href="style.css?v=old"><script src="/app-bundle.php?v=current"></script><script src="https://example.test/app.js?v=old"></script>';
    $first=tt_version_local_assets($html,'/directors/',$root);
    asset_check(str_contains($first,$inline),'Inline JavaScript was changed.');
    asset_check(str_contains($first,'fix=keep&amp;v='.substr(hash_file('sha256',$root.'/app.js'),0,16)),'Other query arguments were lost.');
    asset_check(str_contains($first,'style.css?v='.substr(hash_file('sha256',$root.'/directors/style.css'),0,16)),'Relative CSS URL was resolved incorrectly.');
    asset_check(str_contains($first,'/app-bundle.php?v=current'),'Dynamic bundle URL changed.');
    asset_check(str_contains($first,'https://example.test/app.js?v=old'),'External URL changed.');
    asset_check($first===tt_version_local_assets($first,'/directors/',$root),'Versioning must be idempotent.');
    file_put_contents($root.'/app.js','window.value = "new release";');
    asset_check($first!==tt_version_local_assets($html,'/directors/',$root),'Changing script contents must change the delivered URL.');
    $outside='<script src="../outside.js?v=old"></script>';
    asset_check(tt_version_local_assets($outside,'/',$root)===$outside,'Missing/outside files must remain untouched.');
    echo "PASS asset content versions: changed scripts, relative CSS, queries, external/dynamic URLs and large literal inline scripts.\n";
}finally{
    unlink($root.'/app.js');unlink($root.'/directors/style.css');rmdir($root.'/directors');rmdir($root);
}
