<?php
declare(strict_types=1);

/** Deliver original artwork on demand; never duplicate its bytes in every page. */
function tt_export_runtime_script(?string $root=null): string {
    $root=$root??__DIR__;
    $path=$root.'/app.js';
    if(!is_file($path))throw new RuntimeException('Export module assets are unavailable.');
    $js=file_get_contents($path);
    if($js===false)throw new RuntimeException('Export module assets are unavailable.');
    foreach(['TTI_header.png','TTI_sign.png','BRM_header.png','BRM_sign.png','TG_header.png','TG_footer.png','TG_sign.png','KCCI_COO_letterpad.jpg'] as $asset){
        $path=$root.'/assets/'.$asset;
        if(!is_file($path)||($hash=hash_file('sha256',$path))===false)throw new RuntimeException('Export document artwork is unavailable.');
        $url='/export-assets.php?name='.$asset.'&v='.substr($hash,0,16);
        $js=str_replace('assets/'.$asset,$url,$js);
    }
    return $js;
}
