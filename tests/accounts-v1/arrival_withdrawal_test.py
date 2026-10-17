"""Apply/restore the actual CLI on disposable stores, including fail-closed cases."""
import hashlib
import json
import pathlib
import shutil
import subprocess
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
digest = lambda b: hashlib.sha256(b).hexdigest()
with tempfile.TemporaryDirectory(prefix='arrival-withdrawal-') as tmp:
    root = pathlib.Path(tmp) / 'public_html'
    root.mkdir()
    shutil.copytree(ROOT / 'scripts', root / 'scripts')
    (root / 'api').mkdir()
    shutil.copy(ROOT / 'api/arrival_withdrawal_core.php', root / 'api')
    (root / 'auth_store.php').write_text('<?php // not loaded by the CLI')
    data = pathlib.Path(tmp) / 'transtrade_private'
    data.mkdir()
    expected = [('PH-00001','ABC-123','26002',30500,400,1790152499385,1790152272787,'6m0l1s'),
                ('PH-00002','ABC-345','26001',39000,400,1790152521608,1790152314316,'njcg2b'),
                ('PH-00003','TK-123','26002',50000,500,1790152550416,1790152351681,'6ad87u')]
    slips = [dict(slip=ph,truck=truck,soda=soda,payableWeight=kg,bags=bags,id=id,queueId=qid,bridgeId=f'PHB-{id}-{suffix}',broker='shams broker',unloadingDate='2026-09-23') for ph,truck,soda,kg,bags,id,qid,suffix in expected]
    real = {'id': 'REAL-ARRIVAL', 'slip': 'PH-00004', 'soda': '26002', 'payableWeight': 9000}
    rows = {'tt30slips': slips + [real], 'tt30queue': [{'id': x['queueId']} for x in slips]+[{'id':'REAL-QUEUE'}],
            'tt39accountsoutbox': [{'body':{'sourceKey':x['bridgeId']},'journalId':f'OLD-{i}'} for i,x in enumerate(slips*2)],
            'tt30ship': [{'id':'REAL-SHIP','soda':'26002'}]}
    ops = {'revision': 7, 'values': {k: json.dumps(v) for k,v in rows.items()}, 'meta': {}}
    books = {'revision': 19, 'journals': {'REAL-POST': {'reference': 'unrelated'}},'purchaseSodas':[{'sodaNo':'26002','locationName':'indus rice mill'}]}
    op, ac = data/'operations.json', data/'accounts.json'
    op.write_text(json.dumps(ops)); ac.write_text(json.dumps(books))
    before, account_bytes = op.read_bytes(), ac.read_bytes()
    command = ['php',str(root/'scripts/withdraw_test_arrivals.php'),'--root='+str(root)]
    def run(*args, ok=True):
        p = subprocess.run(command+list(args),text=True,capture_output=True)
        assert (p.returncode == 0) == ok, p.stdout+p.stderr
        return json.loads(p.stdout) if ok else p.stderr
    assert run()['dryRun']
    assert op.read_bytes()==before and not (data/'arrival-recovery').exists()
    run('--apply','--expect-operations=bad','--expect-accounts=bad',ok=False)
    assert op.read_bytes()==before
    linked = json.loads(before)
    linked['values']['tt38labourbills'] = json.dumps([{'id':'REAL-BILL','sourceSlips':[slips[0]['id']]}])
    op.write_text(json.dumps(linked)); linked_bytes=op.read_bytes()
    run(ok=False)
    assert op.read_bytes()==linked_bytes, 'Dependency refusal changed stock'
    op.write_bytes(before)
    financial = dict(books, journals={'REAL-POST':{'reference':'PH-00001'}})
    ac.write_text(json.dumps(financial)); run(ok=False)
    assert op.read_bytes()==before
    ac.write_bytes(account_bytes)
    args = ['--apply','--expect-operations='+digest(before),'--expect-accounts='+digest(account_bytes)]
    result = run(*args)
    assert result['counts']=={'tt30slips':3,'tt30queue':3,'tt39accountsoutbox':6}
    assert ac.read_bytes()==account_bytes
    after = op.read_bytes(); saved=json.loads(after)
    assert json.loads(saved['values']['tt30slips'])==[real]
    assert saved['values']['tt30ship']==ops['values']['tt30ship']
    assert run(*args)['alreadyApplied'] and op.read_bytes()==after
    archive=data/'arrival-recovery/withdrawal-20261017.json'
    assert archive.stat().st_mode & 0o777 == 0o600
    run('--restore')
    assert op.read_bytes()==before and ac.read_bytes()==account_bytes, 'Restore did not recover exact before-image'
    op.write_bytes(after+b' ')
    run('--restore',ok=False)
    assert op.read_bytes()==after+b' ', 'Restore overwrote later changes'
    guard = root/'guard.php'
    guard.write_text('''<?php
require __DIR__.'/api/arrival_withdrawal_core.php';
foreach (tt_withdrawn_arrivals() as $row) {
  try {tt_guard_withdrawn_arrival(['sourceKey'=>'POHANCH|1|'.$row['bridgeId']]);exit(1);}catch(DomainException $e){}
  try {tt_guard_arrival_collection('tt30slips','[]',json_encode([$row]));exit(2);}catch(DomainException $e){}
  tt_guard_arrival_collection('tt30slips',json_encode([$row]),json_encode([$row]));
}
tt_guard_withdrawn_arrival(['slip'=>'PH-00001','soda'=>'26002','bridgeId'=>'NEW-REAL-RECEIPT']);
''')
    subprocess.run(['php',str(guard)],check=True)
    print('PASS actual withdrawal dry-run, stale-snapshot refusal, 12 exact records, protected archive, unchanged books/Sodas, retry, byte-exact restore, later-change refusal and cached-source guards')
