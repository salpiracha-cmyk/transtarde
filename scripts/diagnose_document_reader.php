<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/auth_store.php';
require dirname(__DIR__).'/api/document_ai_health.php';
try{echo json_encode(['ok'=>true,'providers'=>tt_ai_check_connections()],JSON_UNESCAPED_SLASHES).PHP_EOL;}
catch(Throwable $e){fwrite(STDERR,"Document reader connection check could not be completed.\n");exit(1);}
