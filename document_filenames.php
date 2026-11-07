<?php
declare(strict_types=1);
/** Safe presentation names. Never use these names as storage paths or identities. */
function tt_document_name_clean(mixed $value,int $limit=72):string {
    if(is_array($value))$value=$value['name']??$value['label']??'';
    if(!is_scalar($value)&&$value!==null)$value='';
    $s=preg_replace('/[\x00-\x1f\x7f-\x9f\x{202a}-\x{202e}\x{2066}-\x{2069}]/u','',(string)$value)??'';
    $s=preg_replace('~[<>:"/\\\\|?*]+~u','-',$s)??'';$s=trim(preg_replace('/\s+/u',' ',$s)??''," .-");$out='';
    foreach(preg_split('//u',$s,-1,PREG_SPLIT_NO_EMPTY)?:[] as $char){if(strlen($out.$char)>$limit)break;$out.=$char;}return rtrim($out," .-");
}
function tt_document_name(array $o):string {
    $party=tt_document_name_clean($o['party']??$o['company']??'Transtrade',64);if($party==='')$party=tt_document_name_clean($o['company']??'Transtrade',64)?:'Transtrade';
    $title=tt_document_name_clean($o['title']??'Document',56)?:'Document';$ref=tt_document_name_clean($o['reference']??'',64);
    $date=tt_document_name_clean(!empty($o['from'])||!empty($o['to'])?(($o['from']??'')?:'Beginning').' to '.(($o['to']??'')?:'Latest'):($o['date']??''),35);
    $company=!empty($o['party'])&&!empty($o['company'])&&tt_document_name_clean($o['party'])!==tt_document_name_clean($o['company'])?tt_document_name_clean($o['company'],16):'';
    $parts=array_values(array_filter([$party,$title,$ref,$date,$company,tt_document_name_clean($o['currency']??'',6)],static fn($x)=>$x!==''));$stem=implode(' - ',$parts);
    if(strlen($stem)>220){$parts[0]=tt_document_name_clean($parts[0],max(8,220-strlen(implode(' - ',array_slice($parts,1)))-3));$stem=implode(' - ',$parts);}
    if(preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i',$stem))$stem='Document - '.$stem;
    $ext=strtolower(ltrim((string)($o['extension']??''),'.'));return $stem.(preg_match('/^[a-z0-9]{1,10}$/D',$ext)?'.'.$ext:'');
}
function tt_document_disposition(string $name,bool $download=false):void {
    $ascii=preg_replace('/[^\x20-\x7e]/','_',$name)??'Document';$ascii=str_replace(['"','\\',"\r","\n"],'_',$ascii);
    header('Content-Disposition: '.($download?'attachment':'inline').'; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($name));
}
function tt_document_extension(string $original,string $mime):string {
    return ['application/pdf'=>'pdf','image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/svg+xml'=>'svg','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx','text/csv'=>'csv','application/zip'=>'zip'][$mime]??strtolower(pathinfo($original,PATHINFO_EXTENSION));
}
function tt_export_document_name(array $root,string $ref,string $lotRef,string $title,string $ext):string {
    $contract=[];$lot=[];$customer=[];foreach((array)($root['contracts']??[]) as $r)if(($r['ref']??'')===$ref){$contract=$r;break;}
    foreach((array)($root['shipments']??[]) as $r)if(($r['contractRef']??'')===$ref&&in_array($lotRef,[(string)($r['id']??''),(string)($r['lotId']??'')],true)){$lot=$r;break;}
    foreach((array)($root['customers']??[]) as $r)if(($r['id']??'')===($contract['customerId']??null)){$customer=$r;break;}
    $party=$lot['documentParties']['buyer']['name']??$lot['buyerSnapshot']['name']??$lot['buyer']??$customer['name']??'';
    $invoice=preg_match('/custom|phyto|tg|relationship/i',$title)?($lot['customs']['invoiceNo']??$ref):($lot['commercial']['invoiceNo']??$ref);
    return tt_document_name(['party'=>$party,'company'=>$contract['seller']??'Transtrade','title'=>$title,'reference'=>$invoice.($lotRef!==''?' - '.$lotRef:''),'extension'=>$ext]);
}
