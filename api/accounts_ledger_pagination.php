<?php
declare(strict_types=1);

// Called only after authorization, filtering and full-period running balances.
// A posting is indivisible: its lines never straddle pages.
function alb_page(array $rows, int $page, int $size): array {
 $groups=[];$totals=['debit'=>0.0,'credit'=>0.0];$register=[];
 foreach($rows as $row){
  $groups[(string)$row['voucher']][]=$row;
  $totals['debit']+=(float)$row['debit'];$totals['credit']+=(float)$row['credit'];
  $owner=(string)($row['entity']??$row['accountName']??'');
  $currency=(string)($row['bookCurrency']??($owner==='TG'?'AED':'PKR'));$key=$owner.'|'.$currency;
  if(!isset($register[$key]))$register[$key]=['company'=>$owner,'currency'=>$currency,'debit'=>0,'credit'=>0,'count'=>0];
  $register[$key]['debit']+=(int)round((float)$row['debit']*100);
  $register[$key]['credit']+=(int)round((float)$row['credit']*100);$register[$key]['count']++;
 }
 $keys=array_keys($groups);
 usort($keys,static fn($a,$b)=>strcmp((string)$groups[$b][0]['date'],(string)$groups[$a][0]['date'])?:strnatcmp((string)$b,(string)$a));
 $size=max(1,min(200,$size));$pages=max(1,(int)ceil(count($keys)/$size));$page=max(1,min($pages,$page));$visible=[];
 foreach(array_slice($keys,($page-1)*$size,$size) as $key)foreach($groups[$key] as $row)$visible[]=$row;
 foreach($register as &$total){$total['debit']/=100;$total['credit']/=100;}unset($total);
 return ['rows'=>$visible,'pagination'=>['page'=>$page,'pages'=>$pages,'pageSize'=>$size,'totalPostings'=>count($keys)],'periodTotals'=>$totals,'registerTotals'=>array_values($register)];
}
