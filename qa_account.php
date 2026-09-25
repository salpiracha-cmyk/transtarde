<?php
declare(strict_types=1);

/**
 * Transtrade's dedicated operational QA account.
 *
 * The plaintext password is deliberately NOT stored in GitHub. Only the
 * original seed hash is committed. Once the account exists, this file never
 * changes its password hash; the live-qa secret must match the live password.
 *
 * The QA profile itself is system-managed so an ordinary User & Permissions
 * edit cannot silently turn the automated test identity into Director,
 * Super Admin, or another staff role.
 */
function tt_qa_account_profile(): array {
    return [
        'full_name'=>'Transtrade QA',
        'username'=>'qa.assistant',
        'role'=>'QA Tester',
        'location'=>'All authorized locations',
        'permissions'=>['Mill'=>'all','Exports'=>'all','Accounts'=>'all'],
        'master_access'=>false,
        'master_permissions'=>[],
        'active'=>true,
        'must_change_password'=>false,
        'system_qa'=>true,
        'test_data_only'=>true,
    ];
}

function tt_is_managed_qa_account(array $user): bool {
    return strcasecmp((string)($user['username'] ?? ''),'qa.assistant')===0
        && (!empty($user['system_qa']) || !empty($user['test_data_only']));
}

/** Apply the stable QA profile while intentionally preserving password/history. */
function tt_apply_qa_account_profile(array &$user): bool {
    $changed=false;
    foreach (tt_qa_account_profile() as $key=>$value) {
        if (($user[$key] ?? null)===$value) continue;
        $user[$key]=$value;
        $changed=true;
    }
    return $changed;
}

/**
 * Seed the QA account once and continuously protect its operational profile.
 *
 * Deleting the account remains an explicit Super Admin action: the historical
 * qa_account_seeded marker still prevents silent recreation after deletion.
 */
function tt_ensure_qa_account(): void {
    $store=tt_read_store();
    $settings=is_array($store['settings'] ?? null) ? $store['settings'] : [];

    foreach ((array)($store['users'] ?? []) as $existing) {
        if (strcasecmp((string)($existing['username'] ?? ''),'qa.assistant')!==0) continue;

        // Never adopt an unrelated manually-created account merely because it
        // happens to use the reserved username.
        if (!tt_is_managed_qa_account($existing)) {
            tt_mutate_store(function (&$data): void {
                if (!isset($data['settings']) || !is_array($data['settings'])) $data['settings']=[];
                $data['settings']['qa_account_seeded']=true;
                $data['settings']['qa_account_seeded_at']=$data['settings']['qa_account_seeded_at'] ?? gmdate('c');
            });
            return;
        }

        tt_mutate_store(function (&$data): void {
            $changed=false;
            if (!isset($data['users']) || !is_array($data['users'])) $data['users']=[];
            foreach ($data['users'] as &$user) {
                if (!tt_is_managed_qa_account((array)$user)) continue;
                $changed=tt_apply_qa_account_profile($user);
                unset($user);
                break;
            }
            unset($user);

            if (!isset($data['settings']) || !is_array($data['settings'])) $data['settings']=[];
            $data['settings']['qa_account_seeded']=true;
            $data['settings']['qa_account_seeded_at']=$data['settings']['qa_account_seeded_at'] ?? gmdate('c');
            $data['settings']['qa_account_profile_version']=2;

            if ($changed) {
                if (!isset($data['audit']) || !is_array($data['audit'])) $data['audit']=[];
                array_unshift($data['audit'],[
                    'user_id'=>null,
                    'username'=>'System',
                    'action'=>'Restored operational QA account profile for qa.assistant',
                    'ip_address'=>$_SERVER['REMOTE_ADDR'] ?? '',
                    'created_at'=>gmdate('c'),
                ]);
            }
        });
        return;
    }

    if (!empty($settings['qa_account_seeded'])) return;

    tt_mutate_store(function (&$data): void {
        foreach ((array)($data['users'] ?? []) as $user) {
            if (strcasecmp((string)($user['username'] ?? ''),'qa.assistant')===0) return;
        }
        $id=random_int(100000,999999999);
        $profile=tt_qa_account_profile();
        $profile['id']=$id;
        $profile['password_hash']='$2y$12$Ws2okSRgQhqwASFVJv3gK.GcenpIYp94ssXiY4KKrInPG8q/VU/k.';
        $profile['created_at']=gmdate('c');
        $profile['last_login_at']=null;
        $data['users'][]=$profile;

        if (!isset($data['settings']) || !is_array($data['settings'])) $data['settings']=[];
        $data['settings']['qa_account_seeded']=true;
        $data['settings']['qa_account_seeded_at']=gmdate('c');
        $data['settings']['qa_account_profile_version']=2;
        if (!isset($data['audit']) || !is_array($data['audit'])) $data['audit']=[];
        array_unshift($data['audit'],[
            'user_id'=>null,
            'username'=>'System',
            'action'=>'Created operational QA test account qa.assistant',
            'ip_address'=>$_SERVER['REMOTE_ADDR'] ?? '',
            'created_at'=>gmdate('c'),
        ]);
    });
}
