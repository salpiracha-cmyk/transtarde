<?php
declare(strict_types=1);
require_once __DIR__.'/opening_balance_core.php';
function acw_xml(string $s): string{return htmlspecialchars($s,ENT_XML1|ENT_QUOTES,'UTF-8');}
function acw_p(string $s,bool $bold=false,int $size=20): string{return '<w:p><w:pPr><w:spacing w:after="80"/></w:pPr><w:r><w:rPr>'.($bold?'<w:b/>':'').'<w:sz w:val="'.$size.'"/></w:rPr><w:t xml:space="preserve">'.acw_xml($s).'</w:t></w:r></w:p>';}
function acw_table(array $rows): string{
 $xml='<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/><w:tblBorders><w:bottom w:val="single" w:sz="4" w:color="D6DEE4"/><w:insideH w:val="single" w:sz="4" w:color="D6DEE4"/></w:tblBorders></w:tblPr><w:tblGrid><w:gridCol w:w="850"/><w:gridCol w:w="4600"/><w:gridCol w:w="1250"/><w:gridCol w:w="1250"/><w:gridCol w:w="900"/></w:tblGrid>';
 foreach($rows as $i=>$row){$xml.='<w:tr><w:trPr><w:cantSplit/>'.($i===0?'<w:tblHeader/>':'').'</w:trPr>';foreach($row as $j=>$cell)$xml.='<w:tc><w:tcPr>'.($i===0?'<w:shd w:fill="E9F1ED"/>':'').'<w:tcMar><w:top w:w="60" w:type="dxa"/><w:bottom w:w="60" w:type="dxa"/></w:tcMar></w:tcPr>'.acw_p((string)$cell,$i===0,18).'</w:tc>';$xml.='</w:tr>';}
 return $xml.'</w:tbl>';
}
/** Store-only ZIP avoids depending on the hosting server's optional Zip extension. */
function acw_zip(array $files): string{
 $data='';$central='';$offset=0;
 foreach($files as $name=>$content){$n=strlen($name);$len=strlen($content);$crc=crc32($content);$local=pack('VvvvvvVVVvv',0x04034b50,20,0,0,0,33,$crc,$len,$len,$n,0).$name.$content;$central.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,0,0,0,33,$crc,$len,$len,$n,0,0,0,0,0,$offset).$name;$data.=$local;$offset+=strlen($local);}
 return $data.$central.pack('VvvvvVVv',0x06054b50,0,0,count($files),count($files),strlen($central),strlen($data),0);
}
function acw_party(array $line,array $journal,array $store,array $masters): string {
 $meta=(array)($journal['meta']??[]);$sources=[$line,$meta];
 foreach(['candidateId'=>'exportCandidates','receiptId'=>'exportReceipts','supplierBillId'=>'supplierBills','bagBillId'=>'bagSupplierBills','purchaseId'=>'otherPurchases','billId'=>'commodityBills'] as $key=>$collection){$row=(array)($store[$collection][(string)($meta[$key]??'')]??[]);$sources[]=$row;$sources[]=(array)($row['meta']??[]);}
 foreach($sources as $source)foreach(['supplier','broker','customer','counterparty','party','vendor','subledger'] as $field){$name=trim((string)($source[$field]??''));if($name==='')continue;foreach(['business_parties','export_customers'] as $type)foreach((array)($masters[$type]??[]) as $master){$canonical=trim((string)($master['values'][0]??''));if($canonical!==''&&job_key($canonical)===job_key($name))return $canonical;}return $name;}
 return '';
}
function acw_document(array $master,array $store,array $masters,array $entities,string $date): string{
 $catalog=[];foreach(array_merge((array)($master['chart']??[]),(array)($master['peopleSubledgers']??[])) as $a)$catalog[(string)$a['code']]=$a;
 $body=acw_p('Transtrade Chart of Accounts and Balances',true,32).acw_p('Posted balances as at '.$date.'. Debit and credit balances are shown separately. Each company has its own books. Bank subaccounts show their account currency; TG account heads show AED book values. Bank subaccount figures in different currencies must not be added together.');
 foreach($entities as $e){
  $base=$e==='TG'?'AED':'PKR';$heads=[];$subs=[];$native=[];$nativeMissing=[];$banks=[];
  foreach((array)($masters['banks']??[]) as $b){$v=(array)($b['values']??[]);$link=strtoupper((string)($v[1]??''));$owner=str_contains($link,'TRANS GRAINS')||preg_match('/\bTG\b/',$link)?'TG':(str_contains($link,'BUKSH RICE')||preg_match('/\bBRM\b/',$link)?'BRM':(str_contains($link,'TRANSTRADE INTERNATIONAL')||preg_match('/\bTTI\b/',$link)?'TTI':''));if($owner!==$e||!in_array($v[0]??'',['Company Account','Proprietor / Owner Account','Personal Account'],true))continue;$id=(string)$b['id'];$banks[$id]=['name'=>trim(($v[4]??'').' / '.($v[3]??'').' / '.($v[7]??'').' / '.($v[8]??$v[9]??'')),'currency'=>strtoupper((string)($v[7]??$base))];$native[$id]=0.0;}
  foreach((array)($store['journals']??[]) as $j){if(($j['entity']??'')!==$e||($j['status']??'')!=='Posted'||(string)($j['date']??'')>$date)continue;foreach((array)($j['lines']??[]) as $l){$code=(string)($l['account']??'');if(!$code)continue;$catalog[$code]??=['code'=>$code,'name'=>$l['accountName']??$code];$delta=(float)($l['debit']??0)-(float)($l['credit']??0);$heads[$code]=($heads[$code]??0)+$delta;
   if($code==='1110'){$id=(string)($l['bankAccountId']??$j['meta']['bankAccountId']??'');if($id===''){$subs[$code]['Unassigned bank entries']=($subs[$code]['Unassigned bank entries']??0)+$delta;continue;}$banks[$id]??=['name'=>$l['bankName']??$id,'currency'=>$l['currency']??$base];if($banks[$id]['currency']===$base)$native[$id]=($native[$id]??0)+$delta;elseif(isset($l['bankDebit'])||isset($l['bankCredit']))$native[$id]=($native[$id]??0)+(float)($l['bankDebit']??0)-(float)($l['bankCredit']??0);else $nativeMissing[$id]=true;continue;}
   if(empty($catalog[$code]['subledger']))continue;$party=acw_party($l,$j,$store,$masters);if($party!=='')$subs[$code][$party]=($subs[$code][$party]??0)+$delta;
  }}
  if(function_exists('sac_accounts'))foreach(sac_accounts($store,$e) as $a){$subs[$a['parentCode']][$a['name']]??=0.0;}
  $map=job_role_accounts();
  foreach(['business_parties','export_customers'] as $type)foreach((array)($masters[$type]??[]) as $party){$v=(array)($party['values']??[]);$name=trim((string)($v[0]??''));if(!$name)continue;$codes=$type==='export_customers'?['1210']:array_filter(array_map(static fn($role)=>$map[trim($role)]??null,preg_split('/[;,|]/',(string)($v[2]??''))));foreach($codes as $code)$subs[$code][$name]??=0.0;}
  $body.=acw_p($e.' Company Books',true,26);$rows=[['Code','Account or subaccount','Debit balance','Credit balance','Currency']];$dr=0.0;$cr=0.0;$sorted=$catalog;ksort($sorted,SORT_NATURAL);
  $money=static fn($n)=>number_format(abs(round((float)$n,2)),2,'.',',');
  foreach($sorted as $code=>$a){$balance=round((float)($heads[$code]??0),2);$heading=($a['level']??'')==='heading';$rows[]=[(string)$code,(string)$a['name'],$heading?'':($balance>0?$money($balance):'0.00'),$heading?'':($balance<0?$money($balance):'0.00'),$heading?'':$base];if(!$heading){$dr+=max(0,$balance);$cr+=max(0,-$balance);}
   if((string)$code==='1110')foreach($banks as $id=>$bank){$n=isset($nativeMissing[$id])?null:($native[$id]??0);$rows[]=['','  '.$bank['name'],$n===null?'Unavailable':($n>0?$money($n):'0.00'),$n===null?'Unavailable':($n<0?$money($n):'0.00'),$bank['currency']];}
   $parties=$subs[$code]??[];uksort($parties,'strnatcasecmp');foreach($parties as $name=>$n)$rows[]=['','  '.$name,$n>0?$money($n):'0.00',$n<0?$money($n):'0.00',$base];
  }
  $rows[]=['','Account head totals',$money($dr),$money($cr),$base];$body.=acw_table($rows);
 }
 $xml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="850" w:right="850" w:bottom="850" w:left="850"/></w:sectPr></w:body></w:document>';
 return acw_zip(['[Content_Types].xml'=>'<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>','_rels/.rels'=>'<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>','word/document.xml'=>$xml]);
}

