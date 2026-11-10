<?php
declare(strict_types=1);
require_once __DIR__.'/social_alerts_settings.php';

class DiscordDeliveryUncertain extends RuntimeException {}
class DiscordDeliveryRateLimited extends DomainException {
    public function __construct(public readonly int $seconds) {
        parent::__construct('Discord is limiting requests. The worker will try again later.');
    }
}

function tt_discord_webhook_url(string $url): string {
    if (!preg_match('~^https://discord\.com/api(?:/v10)?/webhooks/([0-9]{17,20})/([A-Za-z0-9_-]{32,150})$~D',$url,$matches)) {
        throw new DomainException('Enter the Discord webhook URL copied from the channel integration.');
    }
    return 'https://discord.com/api/v10/webhooks/'.$matches[1].'/'.$matches[2];
}

function tt_discord_request(string $url, ?array $payload=null, ?CURLFile $attachment=null): array {
    $url=tt_discord_webhook_url($url);
    $curl=curl_init($url.($payload===null?'':'?wait=true'));
    $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>$attachment?60:15];
    if ($payload!==null) {
        $payload['allowed_mentions']=['parse'=>[]];
        $json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
        $options[CURLOPT_POST]=true;
        $options[CURLOPT_POSTFIELDS]=$attachment?['payload_json'=>$json,'files[0]'=>$attachment]:$json;
        if (!$attachment) $options[CURLOPT_HTTPHEADER]=['Content-Type: application/json'];
    }
    curl_setopt_array($curl,$options);
    $body=curl_exec($curl);
    $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $data=is_string($body)?json_decode($body,true):null;
    if ($body===false || $status>=500 || ($status===200 && !is_array($data))) {
        throw new DiscordDeliveryUncertain('Discord could not confirm the request.');
    }
    if ($status===429) throw new DiscordDeliveryRateLimited(max(1,(int)ceil((float)($data['retry_after']??60))));
    if ($status!==200 || empty($data['id'])) {
        throw new DomainException(match ($status) {
            401,403,404=>'Discord rejected the webhook. Check the channel integration.',
            413=>'The attachment is too large for this Discord server.',
            default=>'Discord could not accept the request.',
        });
    }
    return $data;
}

function tt_discord_save(string $url): void {
    $url=tt_discord_webhook_url($url);
    $data=tt_discord_request($url);
    if (($data['type']??null)!==1 || empty($data['guild_id']) || empty($data['channel_id'])) {
        throw new DomainException('Use an incoming webhook for a Discord server text channel.');
    }
    // A replacement destination must be deliberately enabled.
    tt_social_update_config('discord',['webhook'=>$url,'channelId'=>(string)$data['channel_id'],'enabled'=>false]);
}

function tt_discord_deliver(string $content, ?string $bytes=null, string $filename='Alert.txt', string $mime='text/plain', ?string $expectedChannel=null): void {
    $config=tt_social_config('discord');
    if (!tt_social_enabled('discord',$config)) throw new DomainException('Discord delivery is disabled.');
    if ($expectedChannel!==null && (string)$config['channelId']!==$expectedChannel) throw new DomainException('Discord settings changed before delivery.');
    $url=(string)$config['webhook'];
    try {
        $destination=tt_discord_request($url);
    } catch (DiscordDeliveryUncertain) {
        throw new DomainException('Discord could not verify the channel before delivery.');
    }
    if (($destination['type']??null)!==1 || (string)($destination['channel_id']??'')!==(string)$config['channelId']) {
        throw new DomainException('The Discord webhook was moved. Verify the channel and save the connection again.');
    }
    if ($bytes===null && mb_strlen($content,'UTF-8')>1900) {
        $bytes=$content;
        $content=mb_substr($content,0,1700,'UTF-8')."\n\nFull update attached.";
    }
    $temp=null;
    try {
        $attachment=null;
        if ($bytes!==null) {
            $temp=tempnam(TT_DATA_DIR,'.discord-file-');
            if ($temp===false || !chmod($temp,0600) || file_put_contents($temp,$bytes)!==strlen($bytes)) {
                throw new RuntimeException('Could not prepare the Discord attachment.');
            }
            $attachment=new CURLFile($temp,$mime,$filename);
        }
        $latest=tt_social_config('discord');
        if (!tt_social_enabled('discord',$latest) || $latest!==$config) throw new DomainException('Discord settings changed before delivery.');
        $data=tt_discord_request($url,['content'=>$content],$attachment);
        if ((string)($data['channel_id']??'')!==(string)$config['channelId']) {
            throw new DiscordDeliveryUncertain('Discord did not confirm delivery to the saved channel.');
        }
    } finally {
        if (is_string($temp) && is_file($temp)) unlink($temp);
    }
}

function tt_discord_send_pdf(array $report,string $name,string $caption,string $expectedChannel): void {
    require_once __DIR__.'/mill_recovery_pdf.php';
    tt_discord_deliver($caption,mr_pdf_render($report),$name,'application/pdf',$expectedChannel);
}
