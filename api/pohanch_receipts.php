<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/pohanch_receipt_core.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function pr_out(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

try {
    $user = tt_require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') pr_out(['ok'=>false,'error'=>'Method not allowed.'], 405);
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body) || !tt_verify_csrf((string)($body['csrf'] ?? ''))) pr_out(['ok'=>false,'error'=>'Refresh and retry.'], 419);
    $entity = (string)($body['entity'] ?? '');
    if (!in_array($entity, ['TTI','BRM'], true) || !tt_user_can_access_entity($user, $entity, 'Create') ||
        !tt_user_can_module_action($user, 'Accounts', 'purchases', 'Create')) {
        pr_out(['ok'=>false,'error'=>'Accounts purchase posting permission is required for this company.'], 403);
    }
    $soda = trim((string)($body['soda'] ?? ''));
    $pohanch = trim((string)($body['pohanch'] ?? ''));
    if ($soda === '' || $pohanch === '') throw new DomainException('Choose the original SODA and Pohanch.');
    $result = pr_reconcile(compact('entity','soda','pohanch'),
        ['userId'=>(int)($user['id'] ?? 0),'name'=>(string)($user['full_name'] ?? $user['username'] ?? 'Accounts')])[0];
    pr_out(['ok'=>true,'receipt'=>$result]);
} catch (DomainException $error) {
    pr_out(['ok'=>false,'error'=>$error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('Pohanch receipt recovery: '.$error->getMessage());
    pr_out(['ok'=>false,'error'=>'Accounts could not confirm this receipt. Retry the same Pohanch.'], 500);
}
