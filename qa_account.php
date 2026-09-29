<?php
declare(strict_types=1);

/**
 * Transtrade's dedicated operational QA account.
 *
 * No password or password hash is stored in GitHub. The live account must be
 * provisioned or reset by Super Admin and the GitHub live-qa secret must match
 * that independently managed password.
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
        // The production QA identity is intentionally read-only. It may open
        // every operational module for smoke testing but cannot create, edit,
        // approve, post, issue, complete, deactivate or delete records.
        'permissions'=>['Mill'=>['View'],'Exports'=>['View'],'Accounts'=>['View']],
        'master_access'=>false,
        'master_permissions'=>[],
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
 * Protect an existing QA account's identity and read-only profile.
 *
 * Deleting or disabling the account remains an explicit Super Admin action.
 * Missing accounts are never recreated from repository material.
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
            $data['settings']['qa_account_profile_version']=3;
            unset($data['settings']['qa_account_provisioning_required']);

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

    if (!empty($settings['qa_account_seeded']) && !empty($settings['qa_account_provisioning_required'])) return;
    tt_mutate_store(function (&$data): void {
        if (!isset($data['settings']) || !is_array($data['settings'])) $data['settings']=[];
        $data['settings']['qa_account_seeded']=true;
        $data['settings']['qa_account_provisioning_required']=true;
        $data['settings']['qa_account_profile_version']=3;
    });
}
