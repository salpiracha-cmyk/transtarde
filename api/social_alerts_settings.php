<?php
declare(strict_types=1);

function tt_social_platforms(): array {
    return ['telegram'=>'Telegram', 'discord'=>'Discord'];
}

function tt_social_file(string $platform, string $kind): string {
    if (!isset(tt_social_platforms()[$platform]) || !in_array($kind, ['alerts','dispatch'], true)) {
        throw new DomainException('Choose a supported social platform.');
    }
    return TT_DATA_DIR.'/'.$platform.'-'.$kind.'.json';
}

function tt_social_config(string $platform): array {
    $path=tt_social_file($platform,'alerts');
    if (!is_file($path)) return [];
    return json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
}

function tt_social_enabled(string $platform, array $config): bool {
    return $config!==[] && ($config['enabled'] ?? ($platform==='telegram'))===true;
}

function tt_social_update_config(string $platform, array $changes): void {
    $path=tt_social_file($platform,'alerts');
    tt_ensure_data_dir();
    $lock=fopen($path.'.lock','c');
    if ($lock===false) throw new RuntimeException('Social media settings are unavailable.');
    $temp=null;
    try {
        if (!flock($lock,LOCK_EX)) throw new RuntimeException('Social media settings are busy.');
        $old=tt_social_config($platform);
        if ($old===[] && array_keys($changes)===['enabled']) throw new DomainException('Save the connection first.');
        $data=json_encode(array_replace(['enabled'=>$platform==='telegram'],$old,$changes),JSON_THROW_ON_ERROR);
        $temp=tempnam(TT_DATA_DIR,'.social-alerts-');
        if ($temp===false || !chmod($temp,0600) || file_put_contents($temp,$data)!==strlen($data) || !rename($temp,$path)) {
            throw new RuntimeException('Could not save the social media settings.');
        }
    } finally {
        if (is_string($temp) && is_file($temp)) unlink($temp);
        flock($lock,LOCK_UN);
        fclose($lock);
    }
}
