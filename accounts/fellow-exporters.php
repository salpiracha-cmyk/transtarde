<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
$user=tt_require_login();
if (!tt_user_can_master($user,'business_parties','View')) {http_response_code(403);exit('Parties master permission required.');}
header('Location: /index.php?view=masters&master=business_parties',true,302);
