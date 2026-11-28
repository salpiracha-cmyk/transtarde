<?php
declare(strict_types=1);

require_once __DIR__.'/mill_source_amend_core.php';
require_once __DIR__.'/mill_liabilities_store.php';

/** Only newly appended, committed labour bills trigger automatic posting. */
function ml_post_appended_labour(string $before,string $after,array $actor): array {
    $collection='tt38labourbills';
    $rows=ml_rows([$collection=>$after],$collection);
    $existing=array_fill_keys(array_map('strval',array_column(ml_rows([$collection=>$before?:'[]'],$collection),'id')),true);
    $added=array_values(array_filter($rows,static fn($row)=>is_array($row)&&isset($row['id'])&&!isset($existing[(string)$row['id']])));
    if(!$added)return [];
    $results=[];
    try {
        $masters=tt_list_masters();
        return ml_with_sources(static function(array $values)use($added,$actor,$masters,&$results):array {
            tt_ensure_data_dir();
            $handle=fopen(TT_DATA_DIR.'/accounts.json','c+');
            if($handle===false)throw new RuntimeException('Accounts storage unavailable.');
            try {
                if(!flock($handle,LOCK_EX))throw new RuntimeException('Accounts storage unavailable.');
                $raw=stream_get_contents($handle);
                $store=$raw===''?[]:json_decode($raw,true,512,JSON_THROW_ON_ERROR);
                if(!is_array($store))throw new RuntimeException('Invalid Accounts store.');
                $original=$store;
                foreach($added as $row){
                    $id=(string)$row['id'];
                    try{
                        msa_scope($actor,$row,'LABOUR','Create');
                        $source=ml_source($values,$masters,'LABOUR',$id,$row['entity']);
                        if(ml_fingerprint($source['original'])!==ml_fingerprint($row))throw new DomainException('The bill changed before posting. Review its saved version.');
                        $linked=$store['millLiabilities'][$source['key']]??null;
                        $postId=is_array($linked)&&($linked['status']??'')!=='Reversed'
                            ?(string)($linked['journalId']??'')
                            :tt_next_post_id((array)($store['journals']??[]),'Accounts','Journal',$source['date']);
                        $out=ml_post($store,$source,$actor,$source['fingerprint'],$postId,gmdate('c'));
                        $results[$id]=['status'=>'Posted','postId'=>$out['record']['journalId']];
                    }catch(DomainException $error){$results[$id]=['status'=>'Needs Accounts review','error'=>$error->getMessage()];}
                }
                if($original!==$store){
                    $store['revision']=(int)($store['revision']??0)+1;
                    $json=json_encode($store,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                    rewind($handle);
                    if(!ftruncate($handle,0)||fwrite($handle,$json)!==strlen($json)||!fflush($handle))throw new RuntimeException('Labour posting could not be confirmed.');
                }
                return $results;
            }finally{flock($handle,LOCK_UN);fclose($handle);}
        });
    }catch(Throwable $error){
        error_log('Automatic labour liability: '.$error->getMessage());
        return array_fill_keys(array_column($added,'id'),['status'=>'Needs Accounts review','error'=>'Accounts posting could not be confirmed. Check Mill Bill Review.']);
    }
}
