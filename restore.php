<?php
declare(strict_types=1);
define('TT_RESTORE_CONTROL',true);
require __DIR__.'/auth_store.php';
$user=tt_require_login();if(($user['role']??'')!=='Super Admin'){http_response_code(403);exit('Super Admin access required.');}
$csrf=tt_csrf();tt_release_read_session();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Restore website data · Transtrade</title>
<link rel="stylesheet" href="admin/auth.css"><style>body{display:block;place-items:normal;margin:0;padding:0;background:#f1f5f8}main{max-width:800px;margin:32px auto;padding:28px;background:white;border-radius:12px}header{width:100%;margin:0;display:flex;align-items:center;justify-content:space-between;background:#123044;color:white;padding:18px}header a{color:white;border-left:1px solid #557080;padding-left:18px;margin-left:18px;text-decoration:none}label{display:block;margin:18px 0}input{display:block;width:100%;box-sizing:border-box;padding:10px;border:1px solid #b9c8d3;border-radius:7px;font:inherit}button{margin:8px 8px 8px 0;padding:12px 16px;border:0;border-radius:7px;background:#1769d2;color:white;font:inherit;cursor:pointer}button:disabled{opacity:.5;cursor:wait}#restoreVerification{white-space:pre-line;background:#edf5f9;padding:16px;overflow-wrap:anywhere}.error{color:#9f1717}#restoreBackupNow{background:#9f1717;color:white}[hidden]{display:none!important}@media(max-width:840px){main{margin:16px;padding:20px}header{padding:16px}header strong{font-size:14px}}</style>
<script>window.TT_SESSION={csrf:<?=json_encode($csrf,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>};</script></head>
<body data-standalone-restore><header><strong>TRANSTRADE · Restore website data</strong><a href="logout.php" aria-label="Sign out">⏻</a></header><main>
<a href="index.php?view=backup">Back to Backup &amp; Recovery</a>
<h1>Restore website data</h1><p>Select one password-protected <strong>Data</strong> or older <strong>Complete</strong> ZIP. Software-only backups belong in hosting setup.</p>
<p>Uploading and checking the ZIP will not replace your records. After verification, review its date and click <strong>Confirm Restore</strong>. This replaces Accounts, Mill, Exports, Directors, masters and uploaded documents with the selected snapshot. Current login accounts and security credentials remain in place.</p>
<label>Data or Complete backup ZIP<input id="restoreBackupFile" type="file" accept=".zip,application/zip"></label>
<label>Backup ZIP password<input id="restoreBackupPassword" type="password" autocomplete="off"></label>
<button id="verifyBackupFile" type="button">Upload &amp; verify / Resume upload</button>
<button id="continueRestore" type="button" hidden>Continue recovery</button>
<button id="clearRestore" type="button" hidden>Clear prepared upload</button>
<div id="restoreVerification" role="status" aria-live="polite" hidden></div>
<div id="restoreConfirmArea" hidden><label>Type RESTORE to confirm replacement<input id="restoreConfirmation" autocomplete="off" placeholder="RESTORE"></label><button id="restoreBackupNow" type="button" disabled>Confirm Restore</button></div>
<p>Keep this page open and the internet connected. If interrupted, return here and continue. Before confirmation, select the same ZIP and enter its password again. After confirmation, Continue recovery uses the saved progress. A verified safety copy is kept before records are replaced.</p>
<p id="restoreResult"></p>
</main><script src="admin/restore.js?v=20261026-1"></script></body></html>
