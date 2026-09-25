<?php
declare(strict_types=1);

$GLOBALS['qa_profile_store']=[
    'settings'=>['qa_account_seeded'=>true],
    'users'=>[[
        'id'=>77,
        'full_name'=>'Transtrade QA',
        'username'=>'qa.assistant',
        'password_hash'=>'LIVE-PASSWORD-HASH-MUST-STAY',
        'role'=>'Director',
        'location'=>'Changed',
        'permissions'=>['Directors'=>'all'],
        'master_access'=>true,
        'master_permissions'=>['companies'=>['Edit']],
        'active'=>false,
        'must_change_password'=>true,
        'system_qa'=>true,
        'test_data_only'=>true,
        'created_at'=>'2026-01-01T00:00:00Z',
        'last_login_at'=>'2026-09-25T00:00:00Z',
    ]],
    'audit'=>[],
];

function tt_read_store(): array {
    return $GLOBALS['qa_profile_store'];
}

function tt_mutate_store(callable $callback): mixed {
    return $callback($GLOBALS['qa_profile_store']);
}

require __DIR__.'/../../qa_account.php';

function qa_assert(bool $condition,string $message): void {
    if (!$condition) {
        fwrite(STDERR,"FAIL: {$message}\n");
        exit(1);
    }
}

tt_ensure_qa_account();
$user=$GLOBALS['qa_profile_store']['users'][0];

qa_assert($user['role']==='QA Tester','QA role is restored to QA Tester');
qa_assert($user['password_hash']==='LIVE-PASSWORD-HASH-MUST-STAY','live QA password hash is preserved');
qa_assert($user['permissions']===['Mill'=>'all','Exports'=>'all','Accounts'=>'all'],'QA receives only the three operational module permissions');
qa_assert($user['master_access']===false,'QA does not inherit explicit owner-level Master control');
qa_assert($user['master_permissions']===[],'QA explicit Master matrix is cleared');
qa_assert($user['active']===true,'QA account remains active');
qa_assert($user['must_change_password']===false,'automated QA is not forced into password change');
qa_assert($user['system_qa']===true && $user['test_data_only']===true,'QA identity flags remain protected');
qa_assert(($GLOBALS['qa_profile_store']['settings']['qa_account_profile_version'] ?? null)===2,'QA profile version is recorded');
qa_assert(str_contains((string)($GLOBALS['qa_profile_store']['audit'][0]['action'] ?? ''),'Restored operational QA account profile'),'profile repair is audited');

// A later ordinary profile drift must self-heal without changing the password.
$GLOBALS['qa_profile_store']['users'][0]['role']='Director';
$GLOBALS['qa_profile_store']['users'][0]['permissions']=['Directors'=>'all'];
$GLOBALS['qa_profile_store']['users'][0]['password_hash']='NEW-LIVE-PASSWORD-HASH';
tt_ensure_qa_account();
$user=$GLOBALS['qa_profile_store']['users'][0];
qa_assert($user['role']==='QA Tester','QA role is continuously protected');
qa_assert($user['permissions']===['Mill'=>'all','Exports'=>'all','Accounts'=>'all'],'QA permissions are continuously protected');
qa_assert($user['password_hash']==='NEW-LIVE-PASSWORD-HASH','profile self-heal never replaces a changed live password');

// A manually-created unrelated account using the reserved name is not adopted.
$GLOBALS['qa_profile_store']=[
    'settings'=>[],
    'users'=>[[
        'id'=>88,
        'full_name'=>'Unrelated',
        'username'=>'qa.assistant',
        'password_hash'=>'OTHER',
        'role'=>'Staff',
        'permissions'=>[],
        'active'=>true,
    ]],
    'audit'=>[],
];
tt_ensure_qa_account();
$user=$GLOBALS['qa_profile_store']['users'][0];
qa_assert($user['role']==='Staff','unmanaged account is not silently elevated');
qa_assert($user['password_hash']==='OTHER','unmanaged account password is untouched');
qa_assert(!empty($GLOBALS['qa_profile_store']['settings']['qa_account_seeded']),'reserved-name collision still prevents silent reseeding');

echo "PASS stable operational QA account profile\n";
