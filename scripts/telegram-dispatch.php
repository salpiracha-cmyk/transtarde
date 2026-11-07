<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);
if(is_file($root.'/.user.ini')) {
    $ini=parse_ini_file($root.'/.user.ini',false,INI_SCANNER_RAW);
    $prepend=$ini['auto_prepend_file']??'';
    if(is_string($prepend)&&$prepend!==''&&$prepend!=='none') {
        $prepend=str_starts_with($prepend,'/')?$prepend:$root.'/'.$prepend;
        if(is_file($prepend))require_once $prepend;
    }
}
require_once $root.'/auth_store.php';
require_once dirname(__DIR__).'/inventory_reconciliation.php';
require_once dirname(__DIR__).'/api/telegram_dispatch_core.php';
try {
    $result=tg_dispatch(in_array('--initialize',$argv,true));
    echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
    exit(empty($result['errors'])?0:1);
} catch(Throwable) {
    fwrite(STDERR,"Telegram automatic delivery failed. Check Telegram Alerts in Super Admin.\n");
    exit(1);
}
