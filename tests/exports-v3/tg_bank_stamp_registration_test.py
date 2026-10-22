"""Register the owner-supplied stamp once in disposable master storage; preserve other identities."""
import json, os, pathlib, shutil, subprocess, tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tg-bank-stamp-') as temp:
 root=pathlib.Path(temp);app=root/'app';app.mkdir();private=root/'transtrade_private';private.mkdir()
 for source in ROOT.glob('*.php'):shutil.copy(source,app/source.name)
 (app/'scripts').mkdir();shutil.copy(ROOT/'scripts/install_tg_pakistan_bank_stamp.php',app/'scripts/install_tg_pakistan_bank_stamp.php')
 (app/'exports/assets').mkdir(parents=True);shutil.copy(ROOT/'exports/assets/TG_pakistan_bank_stamp.png',app/'exports/assets/TG_pakistan_bank_stamp.png')
 old={'id':'existing-stamp','type':'Stamp','label':'Normal TG stamp','status':'Active','isDefault':True}
 values=['Trans Grains Foodstuff Trading L.L.C','TG']+['']*12+[json.dumps([old])]
 company={'id':'TG-fixture','values':values};other={'id':'TTI-fixture','values':['Transtrade International','TTI']}
 store={'users':[],'settings':{},'masters':{'companies':[other,company]},'audit':[],'sentinel':'unchanged'}
 path=private/'auth.json';path.write_text(json.dumps(store));before=path.read_bytes()
 env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
 def run(*args):
  r=subprocess.run(['php',str(app/'scripts/install_tg_pakistan_bank_stamp.php'),*args],env=env,text=True,capture_output=True);assert r.returncode==0,r.stdout+r.stderr;return r.stdout
 assert 'ready to register' in run();assert path.read_bytes()==before,'Preview must not write masters'
 assert 'registered' in run('--apply');saved=json.loads(path.read_text());assert saved['masters']['companies'][0]['id']==other['id'];assert saved['masters']['companies'][0]['values'][:2]==other['values'];assert saved['sentinel']=='unchanged';assert saved['users']==[]
 registered=json.loads(saved['masters']['companies'][1]['values'][14]);assert registered[0]==old;assert registered[1]['label']=='TG stamp for Pakistan bank';assert not registered[1]['isDefault'];assert registered[1]['purpose']=='Pakistan bank pack'
 assert (private/'master-documents'/registered[1]['storageName']).read_bytes()==(ROOT/'exports/assets/TG_pakistan_bank_stamp.png').read_bytes()
 after=path.read_bytes();assert 'already registered' in run('--apply');assert path.read_bytes()==after,'Repeated deployment must not rewrite master data or add backups'
 assert len(list((private/'master-documents').glob('*before*')))==1
 print('PASS TG stamp registration: exact PNG, named identity, preserved old defaults/other companies, preview and byte-identical retry')
