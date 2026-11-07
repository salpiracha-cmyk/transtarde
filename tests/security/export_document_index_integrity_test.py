"""Production document-index functions; private disposable files and concurrent PHP workers."""
import concurrent.futures
import json
import os
from pathlib import Path
import subprocess
import tempfile

ROOT=Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='document-index-integrity-') as tmp:
    root=Path(tmp);base=root/'export_documents';base.mkdir();index=base/'index.json'
    runner=root/'worker.php'
    runner.write_text(r'''<?php
define('TT_DATA_DIR',$argv[1]);function tt_ensure_data_dir(){}
$source=file_get_contents($argv[2]);$start=strpos($source,'function export_docs_respond(');$end=strpos($source,"\ntry{",$start);
$functions=substr($source,$start,$end-$start);
// Force the legacy read-unlock-write implementation to read the same version
// in both workers. A correctly locked mutation does not use this public read.
$functions=str_replace('function export_docs_index_read(): array','function fixture_original_index_read(): array',$functions);
eval($functions);
function export_docs_index_read():array {
    $rows=fixture_original_index_read();$barrier=getenv('INDEX_TEST_BARRIER');
    if($barrier){touch($barrier.'/ready-'.getmypid());$until=microtime(true)+5;while(count(glob($barrier.'/ready-*'))<2){if(microtime(true)>$until)throw new RuntimeException('Read barrier timeout');usleep(1000);}}
    return $rows;
}
$request=json_decode($argv[3],true,512,JSON_THROW_ON_ERROR);
if($request['action']==='insert')export_docs_file_insert($request['row']);
elseif($request['action']==='delete')echo export_docs_delete_contract($request['contract']);
else echo json_encode(export_docs_index_read(),JSON_THROW_ON_ERROR);
''')
    env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
    def call(body,barrier=None):
        return subprocess.run(['php',str(runner),str(root),str(ROOT/'api/export_documents.php'),json.dumps(body)],env={**env,**({'INDEX_TEST_BARRIER':str(barrier)} if barrier else {})},capture_output=True,timeout=10)
    def row(identity,contract='C1'):
        return {'id':identity,'contract_ref':contract,'stored_name':identity+'.pdf','original_name':identity+'.pdf','deleted_at':None}
    index.write_text('{}')
    with tempfile.TemporaryDirectory(prefix='read-barrier-',dir=root) as barrier:
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as workers:
            results=list(workers.map(lambda ident:call({'action':'insert','row':row(ident)},barrier),['A','B']))
    assert all(r.returncode==0 for r in results),[(r.stdout,r.stderr) for r in results]
    assert set(json.loads(index.read_text()))=={'A','B'},'Concurrent uploads lost a document reference'
    valid=index.read_bytes()
    assert call({'action':'insert','row':row('A','OTHER')}).returncode!=0 and index.read_bytes()==valid,'An existing document identity must not be overwritten'
    for raw in [b'',b'{broken',b'null',b'[{}]',b'{"A":{"metric":1e309}}',b'{"A":{"name":"\xff"}}']:
        index.write_bytes(raw);original=base/'A.pdf';original.write_bytes(b'%PDF-fixture-original')
        for body in [{'action':'read'},{'action':'insert','row':row('NEW')},{'action':'delete','contract':'C1'}]:
            assert call(body).returncode!=0 and index.read_bytes()==raw and original.read_bytes()==b'%PDF-fixture-original',(raw,body)
    index.write_bytes(valid)
    bad=row('BAD');bad['metric']=float('inf')
    # PHP decodes this finite JSON syntax as a nonfinite number; encoding must
    # fail before the old index is replaced or truncated.
    encoded=json.dumps({'action':'insert','row':bad}).replace('Infinity','1e309')
    p=subprocess.run(['php',str(runner),str(root),str(ROOT/'api/export_documents.php'),encoded],env=env,capture_output=True)
    assert p.returncode!=0 and index.read_bytes()==valid,'Encoder failure destroyed original document metadata'
    for ident in ['A','B']:(base/(ident+'.pdf')).write_bytes(b'%PDF-fixture-original')
    assert call({'action':'delete','contract':'C1'}).returncode==0
    deleted=json.loads(index.read_text());assert all(r['deleted_at'] for r in deleted.values())
    assert not (base/'A.pdf').exists() and not (base/'B.pdf').exists()
    assert call({'action':'insert','row':row('C','C2')}).returncode==0 and set(json.loads(index.read_text()))=={'A','B','C'}
    index.unlink();assert call({'action':'read'}).returncode==0 and not index.exists(),'A fresh index read must not create a data file'
    print('PASS document index: concurrent inserts retained, duplicate identity denied, damaged/overflow/encoding source preserved, scoped deletion, subsequent insert and missing-index read')
