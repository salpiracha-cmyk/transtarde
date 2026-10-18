<?php
declare(strict_types=1);

/** Explicit Maize work and agreed rates; never interpret a Rice agreement as Maize. */
function maize_labour_validate(array $record): void {
    if (($record['maizeLabourVersion']??null)!==1||($record['maizeRatesConfirmed']??false)!==true) throw new DomainException('Separate agreed Maize work and rates must be confirmed before saving a bill.');
    $from=ml_date((string)($record['from']??''));$to=ml_date((string)($record['till']??''));$date=ml_date((string)($record['date']??''));
    if ($to<$from||$date<$to) throw new DomainException('Review the Maize bill period and bill date.');
    $lines=$record['lines']??null;if (!is_array($lines)||!array_is_list($lines)||!$lines||count($lines)>500) throw new DomainException('Enter actual Maize labour work lines.');
    $seen=[];$total=0.0;
    foreach ($lines as $line) {
        if (!is_array($line)) throw new DomainException('Invalid Maize work line.');
        $route=(string)($line['scenario']??'');$key=(string)($line['key']??'');$basis=(string)($line['basis']??'');
        $allowed=match($route){'DIRECT'=>['maize-direct-load'],'WAREHOUSE_BULK'=>['maize-unload','maize-container-load'],'WAREHOUSE_PACKED'=>['maize-unload','maize-paltai50','maize-paltai25','maize-container-load'],default=>[]};
        if (!$allowed||(!in_array($key,$allowed,true)&&!str_starts_with($key,'maize-extra-'))) throw new DomainException('Choose a Maize route and its applicable work charges. Rice work cannot be reused.');
        if (!in_array($basis,['Per bag','Per container','Per kg','Per 100 kg','Per 50 kg','Per task'],true)) throw new DomainException('Select the agreed Maize rate basis.');
        if (($key==='maize-unload'&&$basis!=='Per bag')||(in_array($key,['maize-direct-load','maize-container-load'],true)&&$basis!=='Per container')) throw new DomainException('Maize unloading is charged per incoming bag; container loading is per container.');
        $qty=ml_quantity($line['qty']??null);$rate=ml_quantity($line['rate']??null);
        if (in_array($basis,['Per bag','Per container'],true)&&floor($qty)!==$qty) throw new DomainException('Maize bag and container quantities must be whole numbers.');
        $workDate=ml_date((string)($line['workDate']??''));$reference=trim((string)($line['workReference']??''));
        if ($workDate<$from||$workDate>$to||$reference===''||strlen($reference)>500||trim((string)($line['label']??''))==='') throw new DomainException('Each Maize charge needs a work date in the bill period and an actual work reference.');
        if (($line['auto']??false)!==false||isset($line['deduction'])||isset($line['adjustmentType'])) throw new DomainException('Enter actual Maize charges as additions only.');
        $chargeKey=in_array($key,['maize-direct-load','maize-container-load'],true)?'maize-loading':$key;
        $signature=$chargeKey.'|'.$workDate.'|'.ml_name($reference);
        if (isset($seen[$signature])) throw new DomainException('The same Maize work appears twice in this bill.');$seen[$signature]=true;
        $total+=$qty/($basis==='Per 100 kg'?100:($basis==='Per 50 kg'?50:1))*$rate;
    }
    if (!is_finite($total)||abs(round($total,2)-ml_money($record['amount']??null))>.01) throw new DomainException('Maize labour lines and the bill total disagree.');
}
