<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/outside_export_loading_core.php';
require_once __DIR__.'/shipment_profitability_core.php';
require_once __DIR__.'/operations_scope.php';
require_once dirname(__DIR__).'/upload_validation.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
function oel_out(array $value,int $status=200):never {
    http_response_code($status);
    echo json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    exit;
}

$storedFile=null;$saved=false;
try{
    $user=tt_require_login();
    $module=($_GET['origin']??'')==='mill'?'Mill':'Exports';
    if(!tt_user_can_open_module($user,$module)||!tt_user_can_module_action($user,$module,$module==='Mill'?'export':'loading','View'))oel_out(['ok'=>false,'error'=>'Loading access is required.'],403);
    $method=$_SERVER['REQUEST_METHOD'];
    if(!in_array($method,['GET','POST'],true))oel_out(['ok'=>false,'error'=>'Unsupported request.'],405);
    $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d');
    $deals=sp_read_books(TT_DATA_DIR.'/accounts.json')['exporterDeals']??[];
    if($method==='GET'&&isset($_GET['contract'])){
        if($module!=='Exports')oel_out(['ok'=>false,'error'=>'Exports access is required.'],403);
        $ref=oel_text($_GET['contract'],160);
        $root=tt_accounts_exports_root();
        $contract=array_column($root['contracts']??[],null,'ref')[$ref]??[];
        $process=[];
        foreach($root['shipments']??[] as $shipment)if(($shipment['contractRef']??'')===$ref&&($shipment['kind']??'')!=='lot'){$process=$shipment;break;}
        $entity=oel_company($process,$contract);
        if(!$contract||!in_array($entity,['TTI','BRM'],true)||!oel_right($user,'Exports',$entity,'View'))oel_out(['ok'=>false,'error'=>'This sales contract is unavailable.'],403);
        $mills=oel_mill_choices(tt_list_masters());
        $choices=oel_deal_choices($root,$deals,$mills,$entity,$ref,$today);
        oel_out(['ok'=>true,'choices'=>array_values(array_filter($choices,static fn($choice)=>os_location($user,$choice['millName'])))]);
    }
    if($method==='POST'){
        $multipart=str_starts_with((string)($_SERVER['CONTENT_TYPE']??''),'multipart/form-data');
        $body=$multipart?$_POST:json_decode((string)file_get_contents('php://input'),true,64,JSON_THROW_ON_ERROR);
        if(!is_array($body)||!tt_verify_csrf((string)($body['csrf']??'')))oel_out(['ok'=>false,'error'=>'Refresh the page and sign in before saving.'],419);
        $file=[];$validated=null;
        $upload=$_FILES['evidenceUpload']??null;
        if(is_array($upload)&&($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
            if(($body['action']??'')!=='container')throw new DomainException('Attach evidence while saving a container.');
            $validated=tt_validate_document_upload($upload,10*1024*1024,['application/pdf'=>'pdf','image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp']);
            $file=['id'=>'outside-'.bin2hex(random_bytes(16)).'.'.$validated['extension'],
                'name'=>tt_upload_original_name((string)($upload['name']??''),'Loading evidence.'.$validated['extension']),
                'mime'=>$validated['mime']];
        }
        $root=tt_mutate_exports_root(static function(array $root)use($user,$module,$body,$deals,$today,$file,$validated,&$storedFile):array{
            $next=oel_change($root,$user,$module,$body,$deals,$today,$file);
            if($file){
                $dir=TT_DATA_DIR.'/export_documents';
                if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Evidence storage unavailable.');
                $path=$dir.'/'.$file['id'];
                if(!move_uploaded_file($validated['path'],$path))throw new RuntimeException('Evidence upload could not be saved.');
                $storedFile=$path;
            }
            return $next;
        },$user,$module);
        $saved=true;
    }else $root=tt_accounts_exports_root();
    $rows=oel_rows($root,$user,$module);
    if(isset($_GET['evidence'])){
        $id=(string)$_GET['evidence'];
        if(!preg_match('/^outside-[a-f0-9]{32}\.(pdf|png|jpg|webp)$/D',$id))oel_out(['ok'=>false,'error'=>'Evidence is unavailable.'],404);
        foreach($rows as $row)foreach($row['actuals'] as $actual){
            $file=$actual['evidenceFile']??[];
            if(($file['id']??'')!==$id)continue;
            $path=TT_DATA_DIR.'/export_documents/'.$id;
            if(!is_file($path))break 2;
            header('Content-Type: '.$file['mime']);
            header('X-Content-Type-Options: nosniff');
            header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($file['name']));
            readfile($path);
            exit;
        }
        oel_out(['ok'=>false,'error'=>'Evidence is unavailable.'],404);
    }
    $staff=[];
    if($module==='Exports'&&array_filter($rows,static fn($row)=>$row['canEdit']&&!$row['closed'])){
        foreach(tt_read_store()['users'] as $candidate){
            if(empty($candidate['active'])||tt_managed_qa_write_blocked($candidate))continue;
            $entities=array_values(array_filter(['TTI','BRM'],static fn($entity)=>oel_right($candidate,'Mill',$entity,'View')&&oel_right($candidate,'Mill',$entity,'Edit')));
            if($entities)$staff[]=['id'=>(int)$candidate['id'],'name'=>$candidate['full_name']??$candidate['username'],'entities'=>$entities];
        }
    }
    foreach($rows as &$row){
        $row['deals']=[];
        if($module!=='Exports')continue;
        foreach($deals as $id=>$deal)if(oel_open_deal($deal,$row['entity'],$row['contract'])&&
            ($row['plannedDealId']===''||($row['plannedDealId']===(string)$id&&(empty($deal['outsideMillId'])||$row['plannedMillId']===$deal['outsideMillId'])))){
            $row['deals'][]=['id'=>(string)$id,'label'=>$deal['party'].' · '.(($deal['reference']??'')?:$row['contract'])];
        }
    }
    unset($row);
    oel_out(['ok'=>true,'rows'=>$rows,'staff'=>$staff,'today'=>$today,'userId'=>(int)$user['id']]);
}catch(DomainException|InvalidArgumentException|JsonException $e){
    if(!$saved&&$storedFile!==null)@unlink($storedFile);
    oel_out(['ok'=>false,'error'=>$e->getMessage()],422);
}catch(Throwable $e){
    if(!$saved&&$storedFile!==null)@unlink($storedFile);
    error_log('Outside export loading: '.$e->getMessage());
    oel_out(['ok'=>false,'error'=>'The outside loading could not be read or saved. Refresh to check its current state.'],500);
}
