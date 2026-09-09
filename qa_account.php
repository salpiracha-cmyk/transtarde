<?php
declare(strict_types=1);

/**
 * One-time Transtrade QA account seed.
 *
 * The plaintext password is deliberately NOT stored in GitHub. Only the
 * password_hash() result is committed. The QA account is operational-test only:
 * Mill / Exports / Accounts access, no Super Admin / owner-master authority.
 *
 * The seed marker prevents a Super Admin deletion from silently recreating the
 * account on the next login-page visit.
 */
function tt_ensure_qa_account(): void {
    $store=tt_read_store();
    $settings=is_array($store['settings'] ?? null) ? $store['settings'] : [];
    if (!empty($settings['qa_account_seeded'])) return;

    foreach ((array)($store['users'] ?? []) as $user) {
        if (strcasecmp((string)($user['username'] ?? ''),'qa.assistant')!==0) continue;
        tt_mutate_store(function (&$data): void {
            if (!isset($data['settings']) || !is_array($data['settings'])) $data['settings']=[];
            $data['settings']['qa_account_seeded']=true;
            $data['settings']['qa_account_seeded_at']=$data['settings']['qa_account_seeded_at'] ?? gmdate('c');
        });
        return;
    }

    tt_mutate_store(function (&$data): void {
        foreach ((array)($data['users'] ?? []) as $user) {
            if (strcasecmp((string)($user['username'] ?? ''),'qa.assistant')===0) return;
        }
        $id=random_int(100000,999999999);
        $data['users'][]=[
            'id'=>$id,
            'full_name'=>'Transtrade QA',
            'username'=>'qa.assistant',
            'password_hash'=>'$2y$12$Ws2okSRgQhqwASFVJv3gK.GcenpIYp94ssXiY4KKrInPG8q/VU/k.',
            'role'=>'QA Tester',
            'location'=>'All authorized locations',
            'permissions'=>['Mill'=>'all','Exports'=>'all','Accounts'=>'all'],
            'active'=>true,
            'must_change_password'=>false,
            'system_qa'=>true,
            'test_data_only'=>true,
            'created_at'=>gmdate('c'),
            'last_login_at'=>null,
        ];
        if (!isset($data['settings']) || !is_array($data['settings'])) $data['settings']=[];
        $data['settings']['qa_account_seeded']=true;
        $data['settings']['qa_account_seeded_at']=gmdate('c');
        array_unshift($data['audit'],[
            'user_id'=>null,
            'username'=>'System',
            'action'=>'Created operational QA test account qa.assistant',
            'ip_address'=>$_SERVER['REMOTE_ADDR'] ?? '',
            'created_at'=>gmdate('c'),
        ]);
    });
}
