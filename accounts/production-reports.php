<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
$user=tt_require_login();
if(!tt_user_can_open_module($user,'Accounts')&&!tt_user_can_open_module($user,'Mill')){http_response_code(403);exit('Production report access is required.');}
tt_release_read_session();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Production & Recovery Reports</title><link rel="stylesheet" href="/brand-theme.css"><style>body{font-family:Arial;margin:0;background:#f4f7f5;color:#183e30}header{background:#173d31;color:white;padding:16px 22px;display:flex;gap:20px;align-items:center}header a{color:white}header a:last-child{margin-left:auto}main{padding:20px;max-width:1600px;margin:auto;background:white}button{cursor:pointer}</style><script src="/brand-theme.js" defer></script><script src="/accounts/mill-recovery-ui.js" defer></script></head><body><header><a href="/accounts/index.php">← Accounts</a><strong>Production & Recovery Reports</strong><a href="/logout.php" aria-label="Sign out">⏻</a></header><main id="productionRecoveryReport"></main><script>document.addEventListener('DOMContentLoaded',()=>TT_MILL_RECOVERY_REPORTS.mount(document.getElementById('productionRecoveryReport')));</script></body></html>
