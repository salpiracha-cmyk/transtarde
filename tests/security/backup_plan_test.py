"""Acceptance for split archives, seven distinct data days and combined offline hosting recovery."""
import hashlib,json,os,shutil,subprocess,sys,tempfile,unittest,zipfile
from datetime import datetime,timedelta,timezone
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT))
from scripts import office_backup_agent as agent
from scripts.backup_plan import commit_received
from scripts.backup_recovery_reader import recover_copy

class SplitRecoveryTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory();cls.root=Path(cls.temp.name);source=cls.root/'app';source.mkdir();cls.password='fixture-split-backup-password'
        for n in ['auth_store.php','session_store.php','master_store.php','product_stage.php','offline_idempotency.php','backup_lib.php','pending_uploads.php','backup_report_exports.php']:shutil.copy(ROOT/n,source/n)
        driver=source/'fixture.php';driver.write_text("""<?php
require __DIR__.'/auth_store.php';require __DIR__.'/backup_lib.php';
tt_mutate_store(static function(array &$s){$s['masters']['fixture']=[['values'=>['Matching master']]];});
file_put_contents(TT_DATA_DIR.'/accounts.json',json_encode(['journals'=>['DATA-ENTRY'=>['amount'=>19]],'accountSubaccounts'=>['MASTER-1'=>['name'=>'Fixture setup']]]));
mkdir(TT_DATA_DIR.'/documents');file_put_contents(TT_DATA_DIR.'/documents/original.pdf','preserved original');
foreach(['data','software']as$scope){$b=tt_build_download_backup(true,'fixture-split-backup-password',$scope);copy($b['path'],$argv[1].'/'.$scope.'.zip');unlink($b['path']);}
$before=[hash_file('sha256',TT_STORE_FILE),hash_file('sha256',TT_DATA_DIR.'/accounts.json')];$rejected=false;
try{tt_restore_complete_backup($argv[1].'/software.zip','fixture-split-backup-password');}catch(InvalidArgumentException $e){$rejected=str_contains($e->getMessage(),'Software and Setup only');}
if(!$rejected||$before!==[hash_file('sha256',TT_STORE_FILE),hash_file('sha256',TT_DATA_DIR.'/accounts.json')])throw new RuntimeException('Software-only restore must be refused before changing any store.');
""")
        subprocess.run(['php',str(driver),str(cls.root)],check=True,capture_output=True)
    @classmethod
    def tearDownClass(cls):cls.temp.cleanup()
    def test_scopes_preserve_all_data_and_match_masters_without_daily_code(self):
        with zipfile.ZipFile(self.root/'data.zip')as z:d=json.loads(z.read('backup-manifest.json'))
        with zipfile.ZipFile(self.root/'software.zip')as z:s=json.loads(z.read('backup-manifest.json'))
        self.assertEqual(d['scope'],'data');self.assertEqual(s['scope'],'software')
        self.assertIn('System_Recovery/private/accounts.json',d['encryptedEntries']);self.assertIn('System_Recovery/private/documents/original.pdf',d['encryptedEntries']);self.assertIn('System_Recovery/private/auth.json',d['encryptedEntries'])
        self.assertFalse(any(n.startswith('Application_Code/')for n in d['encryptedEntries']))
        self.assertIn('System_Recovery/setup/Accounts Masters.json',s['encryptedEntries']);self.assertEqual(d['softwareRevision'],s['softwareRevision']);self.assertNotIn('System_Recovery/private/accounts.json',s['encryptedEntries']);self.assertNotIn('System_Recovery/private/documents/original.pdf',s['encryptedEntries']);self.assertTrue(any(n.startswith('Application_Code/')for n in s['encryptedEntries']))
    def test_combined_recovery_has_five_folders_and_latest_private_data(self):
        originals={n:hashlib.sha256((self.root/n).read_bytes()).hexdigest()for n in ['data.zip','software.zip']}
        result=recover_copy(self.root/'data.zip',self.password,self.root/'recover',companion=self.root/'software.zip')
        self.assertEqual({p.name for p in result.iterdir()if p.is_dir()},{'Accounts','Mill','Exports','Directors','Complete Website Restore'})
        self.assertTrue((result/'Complete Website Restore/public_html/auth_store.php').exists())
        self.assertIn('DATA-ENTRY',(result/'Complete Website Restore/transtrade_private/accounts.json').read_text())
        self.assertEqual((result/'Complete Website Restore/transtrade_private/documents/original.pdf').read_text(),'preserved original')
        self.assertFalse((result/'Complete Website Restore/SOFTWARE COPY REQUIRED.txt').exists())
        self.assertIn('softwareSourceSha256',json.loads((result/'RECOVERY_RESULT.json').read_text()))
        for n,h in originals.items():self.assertEqual(hashlib.sha256((self.root/n).read_bytes()).hexdigest(),h)
    def test_data_alone_and_invalid_companion_never_claim_complete_hosting(self):
        result=recover_copy(self.root/'data.zip',self.password,self.root/'data-only')
        self.assertTrue((result/'Complete Website Restore/SOFTWARE COPY REQUIRED.txt').exists())
        parent=self.root/'invalid-companion'
        with self.assertRaises(ValueError):recover_copy(self.root/'data.zip',self.password,parent,companion=self.root/'data.zip')
        self.assertEqual(list(parent.iterdir()),[])
    def test_mismatched_software_version_stops_without_partial_recovery(self):
        bad=self.root/'wrong-version.zip'
        with zipfile.ZipFile(self.root/'software.zip')as source,zipfile.ZipFile(bad,'w')as target:
            for info in source.infolist():
                payload=source.read(info)
                if info.filename=='backup-manifest.json':
                    manifest=json.loads(payload);manifest['softwareRevision']='different-version';payload=json.dumps(manifest).encode()
                target.writestr(info,payload)
        parent=self.root/'wrong-version-recovery'
        with self.assertRaisesRegex(ValueError,'does not match'):
            recover_copy(self.root/'data.zip',self.password,parent,companion=bad)
        self.assertEqual(list(parent.iterdir()),[])

class RetentionTest(unittest.TestCase):
    def incoming(self,root,stamp,scope,revision='fixture-v1'):
        p=root/'incoming.zip'
        with zipfile.ZipFile(p,'w')as z:
            z.writestr('Encrypted/000001.bin',b'fixture')
            z.writestr('backup-manifest.json',json.dumps({'application':'Transtrade','type':'full_recovery','scope':scope,'softwareRevision':revision,'createdAt':stamp.isoformat(),'encryption':{'algorithm':'AES-256-GCM'},'encryptedEntries':{'System_Recovery/private/auth.json':{'stored':'Encrypted/000001.bin'}}}))
        return p,agent.sha256_file(p)
    def test_seven_distinct_days_monthly_separate_and_same_day_replacement(self):
        with tempfile.TemporaryDirectory()as folder:
            root=Path(folder);dest=root/'SystemBackups'
            for i in range(9):
                stamp=datetime(2026,1,1,18,tzinfo=timezone(timedelta(hours=5)))+timedelta(days=i)
                p,h=self.incoming(root,stamp,'data');commit_received(agent,p,dest,h,'data',stamp)
            self.assertEqual(len(list((dest/'Daily').glob('TRANSTRADE_DATA_*.zip'))),7);self.assertFalse((dest/'Weekly').exists());self.assertEqual(len(list((dest/'Monthly').glob('TRANSTRADE_DATA_*.zip'))),1)
            self.assertFalse(list((dest/'Daily').glob('*2026-01-01*')))
            unknown=dest/'Daily/leave-me.zip';unknown.write_bytes(b'unknown archive')
            stamp+=timedelta(minutes=1);p,h=self.incoming(root,stamp,'data');commit_received(agent,p,dest,h,'data',stamp)
            self.assertEqual(len(list((dest/'Daily').glob('TRANSTRADE_DATA_*.zip'))),7);self.assertEqual(unknown.read_bytes(),b'unknown archive')
            stamp=datetime(2026,2,2,18,tzinfo=timezone(timedelta(hours=5)));p,h=self.incoming(root,stamp,'data');commit_received(agent,p,dest,h,'data',stamp)
            self.assertEqual(len(list((dest/'Monthly').glob('TRANSTRADE_DATA_*.zip'))),2);self.assertEqual(len(list((dest/'Daily').glob('TRANSTRADE_DATA_*.zip'))),7)
    def test_required_software_revision_survives_rotation(self):
        with tempfile.TemporaryDirectory()as folder:
            root=Path(folder);software=root/'SoftwareBackups';data=root/'SystemBackups';stamp=datetime(2026,1,1,tzinfo=timezone.utc)
            p,h=self.incoming(root,stamp,'software','old-version');old=commit_received(agent,p,software,h,'software',stamp)
            p,h=self.incoming(root,stamp,'data','old-version');commit_received(agent,p,data,h,'data',stamp,True)
            for day in range(1,5):
                now=stamp+timedelta(days=day);p,h=self.incoming(root,now,'software','new-version');commit_received(agent,p,software,h,'software',now)
            self.assertTrue(old.exists(),'The monthly data point must retain compatible software')
            self.assertEqual(len(list(software.glob('TRANSTRADE_SOFTWARE_*.zip'))),3)
    def test_wrong_scope_cannot_replace_current_data(self):
        with tempfile.TemporaryDirectory()as folder:
            root=Path(folder);stamp=datetime(2026,1,1,tzinfo=timezone.utc);p,h=self.incoming(root,stamp,'software')
            with self.assertRaises(ValueError):commit_received(agent,p,root/'data',h,'data',stamp)
            self.assertFalse(list((root/'data').rglob('TRANSTRADE_DATA_*.zip')))

if __name__=='__main__':unittest.main()
