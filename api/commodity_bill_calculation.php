<?php
declare(strict_types=1);

/** Bill-only calculation. Milling weighbridge records are never changed here. */
function tt_ready_rice_bill_calculation(float $grossKg, float $ratePerKg, array $input, int $truckCount): array {
    foreach (['bags','emptyBagWeightGrams','kantaRate','loadingRatePerBag'] as $key) {
        $value=$input[$key]??0;
        if (!is_numeric($value)||!is_finite((float)$value)||(float)$value<0) throw new InvalidArgumentException('Enter a valid '.$key.'.');
    }
    $bags=(float)($input['bags']??0);$grams=(float)($input['emptyBagWeightGrams']??0);$kanta=(float)($input['kantaRate']??0);
    if ($bags!==floor($bags)) throw new InvalidArgumentException('Total bags must be a whole number.');
    $emptyKg=round($bags*$grams/1000,6);
    if ($grossKg<=0||$emptyKg>=$grossKg) throw new InvalidArgumentException('Empty-bag weight must be less than the weighbridge weight.');
    return ['weighbridgeWeightKg'=>$grossKg,'bags'=>(int)$bags,'emptyBagWeightGrams'=>$grams,'emptyBagWeightKg'=>$emptyKg,
        'netRiceWeightKg'=>round($grossKg-$emptyKg,6),'ratePerKg'=>$ratePerKg,
        'loadingRatePerBag'=>(float)($input['loadingRatePerBag']??0),'loadingBags'=>(int)($input['loadingBags']??0),'loadingAmount'=>round((float)($input['loadingBags']??0)*(float)($input['loadingRatePerBag']??0),2),
        'emptyBagDeduction'=>round($emptyKg*$ratePerKg,2),'kantaRate'=>$kanta,'truckCount'=>$truckCount,'kantaAmount'=>round($kanta*$truckCount,2)];
}

/** Exact operational identities only; never infer bag tare from a nearby shipment. */
function tt_bill_bag_defaults(array $meta, array $values): array {
    $grams=(float)($meta['emptyBagWeightGrams']??0);
    if ($grams>0) return ['emptyBagWeightGrams'=>$grams];
    $decode=static function(string $key)use($values):array{$v=$values[$key]??[];if(is_string($v))$v=json_decode($v,true);return is_array($v)?$v:[];};
    $containers=$decode('tt35exload');$instructions=$decode('tt40exinstructions');
    $container=strtoupper(str_replace('-','',trim((string)($meta['container']??$meta['pohanch']??''))));
    foreach ($containers as $load) {
        if (!is_array($load)||$container===''||strtoupper(str_replace('-','',(string)($load['container']??'')))!==$container) continue;
        foreach (['shipmentId','contractRef','lotRef'] as $key) if (!empty($meta[$key])&&(string)($load[$key]??'')!==(string)$meta[$key]) continue 2;
        foreach ($instructions as $instruction) {
            if (!is_array($instruction)||(string)($instruction['id']??'')!==(string)($load['instructionId']??'')) continue;
            if (!empty($meta['sourceSodaId'])&&(string)($instruction['sourceSodaId']??'')!==(string)$meta['sourceSodaId']) continue;
            $tare=trim((string)($instruction['bagTare']??''));
            if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*(g|grams?|kg|kgs?)$/i',$tare,$m)) return ['emptyBagWeightGrams'=>(float)$m[1]*(str_starts_with(strtolower($m[2]),'kg')?1000:1)];
        }
    }
    return ['emptyBagWeightGrams'=>0];
}

function tt_bill_operational_values(): array {
    $env=static fn(string $key):string=>defined('TT_'.$key)?(string)constant('TT_'.$key):(string)(getenv('TT_'.$key)?:'');
    if ($env('DB_HOST')!==''&&$env('DB_NAME')!==''&&$env('DB_USER')!=='') {
        $pdo=new PDO('mysql:host='.$env('DB_HOST').';dbname='.$env('DB_NAME').';charset=utf8mb4',$env('DB_USER'),$env('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $rows=$pdo->query("SELECT storage_key,payload FROM tt_operation_records WHERE storage_key IN ('tt35exload','tt40exinstructions')")->fetchAll(PDO::FETCH_KEY_PAIR);
        return is_array($rows)?$rows:[];
    }
    $file=TT_DATA_DIR.'/operations.json';if(!is_file($file))return [];
    $h=fopen($file,'r');if($h===false||!flock($h,LOCK_SH))throw new RuntimeException('Milling bag details are unavailable.');
    try{$raw=stream_get_contents($h);}finally{flock($h,LOCK_UN);fclose($h);}
    $data=$raw?json_decode($raw,true):null;return is_array($data)?(array)($data['values']??[]):[];
}

/** Save a future default without changing any posted bill or the selling profile. */
function tt_bill_save_buying_brokery(string $broker,float $amount,string $basis,string $effectiveFrom,string $billId,array $user): void {
    if ($broker===''||$amount<=0) return;
    if (!tt_user_can_master($user,'business_parties','Edit')) return; // transaction rate remains valid; global defaults are separately authorized
    tt_mutate_store(static function(array &$data)use($broker,$amount,$basis,$effectiveFrom,$billId,$user):void{
        foreach ($data['masters']['business_parties'] as &$row) {
            $v=array_values((array)($row['values']??[]));
            if (strcasecmp(trim((string)($v[0]??'')),$broker)!==0||!tt_business_party_has_category($v[2]??'','Broker')) continue;
            while(count($v)<13)$v[]='';
            $profile=json_decode((string)$v[12],true);if(!is_array($profile))$profile=['buying'=>[],'selling'=>[]];
            $rates=(array)($profile['buying']??[]);$next=['amount'=>$amount,'basis'=>$basis,'effectiveFrom'=>$effectiveFrom,'status'=>'Active','sourceBillId'=>$billId];
            $found=false;foreach($rates as &$rate)if(($rate['effectiveFrom']??'')===$effectiveFrom){$rate=$next;$found=true;break;}unset($rate);if(!$found)$rates[]=$next;
            $before=$v[12];$profile['buying']=$rates;$v[12]=json_encode($profile,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$row['values']=$v;
            $data['audit'][]=['at'=>gmdate('c'),'action'=>'BILL_BUYING_BROKERY','type'=>'business_parties','id'=>$row['id']??'','before'=>$before,'after'=>$v[12],'billId'=>$billId,'by'=>$user['username']??''];
            unset($row);return;
        }
        unset($row);throw new RuntimeException('The broker master could not be found.');
    });
}
