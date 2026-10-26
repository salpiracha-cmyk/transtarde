"""Universal app behavior and real PHP -> Python encrypted recovery acceptance."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
import uuid
import zipfile
import xml.etree.ElementTree as ET

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT))
from scripts import windows_backup_app as app
from scripts import backup_recovery_reader as reader

class UniversalAppTest(unittest.TestCase):
    def test_paths_and_existing_password_preserved_without_prompt(self):
        with tempfile.TemporaryDirectory() as folder:
            path=Path(folder)/'config/office-backup.json';root=Path(folder)/'backups'
            with patch.object(app.agent,'config_path',return_value=path):
                value=app.configure(root,'a'*64,{'password':'fixture-keep-old-archive-password','oldSetting':True})
            self.assertEqual(value['password'],'fixture-keep-old-archive-password')
            self.assertTrue(value['oldSetting']);self.assertEqual(value['token'],'a'*64)
            for name in ['SystemBackups/Daily','SystemBackups/Monthly','SoftwareBackups','ShipmentArchives','RecoveryTests']:self.assertTrue((root/name).is_dir())
            self.assertEqual(json.loads(path.read_text()),value)
            sentinel=root/'SystemBackups/Daily/existing.zip';sentinel.write_bytes(b'keep')
            with patch.object(app.agent,'config_path',return_value=path):app.configure(root,'b'*64,value)
            self.assertEqual(sentinel.read_bytes(),b'keep')
    def test_program_cleanup_is_bounded_and_never_touches_backup_or_unknown_files(self):
        with tempfile.TemporaryDirectory() as folder:
            root=Path(folder);known=[]
            for i in range(4):
                data=('fixture program '+str(i)).encode();path=root/('TranstradeBackup-'+hashlib.sha256(data).hexdigest()[:12]+'.exe');path.write_bytes(data);os.utime(path,(i+1,i+1));known.append(path)
            unknown=root/'TranstradeBackup-unknown.exe';unknown.write_bytes(b'keep')
            archive=root/'original-backup.zip';archive.write_bytes(b'keep archive')
            app.prune_installed_apps(root,known[-1])
            self.assertTrue(known[-1].exists() and known[-2].exists());self.assertFalse(known[0].exists() or known[1].exists())
            self.assertEqual(unknown.read_bytes(),b'keep');self.assertEqual(archive.read_bytes(),b'keep archive')
    def test_task_has_no_plaintext_password_and_uses_pk_utc_slot(self):
        tree=ET.fromstring(app.task_xml(Path('C:/fixture/Backup.exe'),'backup','S-1-5-21-123'))
        n={'t':'http://schemas.microsoft.com/windows/2004/02/mit/task'}
        self.assertTrue(tree.find('.//t:CalendarTrigger/t:StartBoundary',n).text.endswith('T13:00:00Z'))
        self.assertEqual(tree.find('.//t:LogonType',n).text,'InteractiveToken')
        self.assertIn('--scheduled',tree.find('.//t:Arguments',n).text)
        self.assertEqual(tree.find('.//t:MultipleInstancesPolicy',n).text,'IgnoreNew')
        software=ET.fromstring(app.task_xml(Path('C:/fixture/Backup.exe'),'software','S-1-5-21-123'))
        self.assertIsNotNone(software.find('.//t:ScheduleByWeek/t:DaysOfWeek/t:Monday',n))
        self.assertIsNone(software.find('.//t:LogonTrigger',n))
        self.assertIn('--software --force',software.find('.//t:Arguments',n).text)
    def test_untrusted_paths_refused(self):
        for value in ['../escape','/absolute','x\\y','C:/outside','a/../b','a//b','a./file','CON.txt','x/NUL','x\x00y']:
            with self.subTest(value=value),self.assertRaises(ValueError):reader.safe_name(value)

class PhpRecoveryTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not shutil.which('php'):raise RuntimeError('PHP is required for actual encrypted recovery interoperability tests.')
        cls.tmp=tempfile.TemporaryDirectory(prefix='tti-php-recovery-');cls.root=Path(cls.tmp.name);source=cls.root/'source';source.mkdir()
        for name in ['auth_store.php','session_store.php','master_store.php','product_stage.php','offline_idempotency.php','backup_lib.php','backup_report_exports.php']:shutil.copy(ROOT/name,source/name)
        cls.backup=cls.root/'fixture.zip'
        driver=source/'fixture.php';driver.write_text("""<?php
require __DIR__.'/auth_store.php';require __DIR__.'/backup_lib.php';
tt_mutate_store(static function(array &$s){$s['settings']['fixture']=true;});
file_put_contents(TT_DATA_DIR.'/accounts.json',json_encode(['journals'=>['FIXTURE'=>['amount'=>17]]]));
file_put_contents(TT_DATA_DIR.'/office-backup-token.sha256',str_repeat('a',64));
mkdir(TT_DATA_DIR.'/documents');file_put_contents(TT_DATA_DIR.'/documents/fixture.pdf','original fixture bytes');
$built=tt_build_download_backup(true,'fixture-backup-password-2026');copy($built['path'],$argv[1]);unlink($built['path']);
""",encoding='utf-8')
        env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
        subprocess.run(['php',str(driver),str(cls.backup)],check=True,env=env,capture_output=True)
    @classmethod
    def tearDownClass(cls):cls.tmp.cleanup()
    def test_actual_php_complete_backup_recovers_and_preserves_source(self):
        before=hashlib.sha256(self.backup.read_bytes()).hexdigest()
        result=reader.recover_copy(self.backup,'fixture-backup-password-2026',self.root/'recovered')
        self.assertEqual((result/'Complete Website Restore/transtrade_private/documents/fixture.pdf').read_bytes(),b'original fixture bytes')
        self.assertEqual(json.loads((result/'Complete Website Restore/transtrade_private/accounts.json').read_text())['journals']['FIXTURE']['amount'],17)
        self.assertFalse((result/'Complete Website Restore/transtrade_private/office-backup-token.sha256').exists())
        self.assertEqual(hashlib.sha256(self.backup.read_bytes()).hexdigest(),before)
        self.assertFalse(json.loads((result/'RECOVERY_RESULT.json').read_text())['liveWebsiteChanged'])
    def test_wrong_password_and_tamper_never_publish_partial_recovery(self):
        parent=self.root/'failed'
        with self.assertRaises(ValueError):reader.recover_copy(self.backup,'wrong-backup-password-2026',parent)
        self.assertEqual(list(parent.iterdir()),[])
        altered=self.root/'tampered.zip'
        with zipfile.ZipFile(self.backup) as src,zipfile.ZipFile(altered,'w') as dst:
            for info in src.infolist():
                data=src.read(info)
                if info.filename=='Encrypted/000001.bin':data=bytes([data[0]^1])+data[1:]
                dst.writestr(info,data)
        with self.assertRaises(ValueError):reader.recover_copy(altered,'fixture-backup-password-2026',parent)
        self.assertEqual(list(parent.iterdir()),[])
    def test_traversal_is_refused_before_creating_destination(self):
        altered=self.root/'traversal.zip'
        with zipfile.ZipFile(self.backup) as src,zipfile.ZipFile(altered,'w') as dst:
            for info in src.infolist():
                data=src.read(info)
                if info.filename=='backup-manifest.json':
                    manifest=json.loads(data);manifest['encryptedEntries']['../outside.json']=next(iter(manifest['encryptedEntries'].values()));data=json.dumps(manifest).encode()
                dst.writestr(info,data)
        parent=self.root/'traversal-result'
        with self.assertRaises(ValueError):reader.recover_copy(altered,'fixture-backup-password-2026',parent)
        self.assertFalse((self.root/'outside.json').exists());self.assertEqual(list(parent.iterdir()),[])

@unittest.skipUnless(os.name=='nt' and os.environ.get('TT_QA_WINDOWS_TASKS')=='1','Disposable Windows CI task acceptance only')
class WindowsTaskTest(unittest.TestCase):
    def test_actual_disposable_tasks_and_shortcut_targets(self):
        # Test names cannot collide with the office tasks, and no task is started.
        name='Transtrade_QA_Universal_'+uuid.uuid4().hex[:12]
        original=app.task_xml
        def future_xml(*args,**kwargs):
            value=original(*args,**kwargs)
            import re
            return re.sub(r'<StartBoundary>.*?</StartBoundary>','<StartBoundary>2099-01-01T13:00:00Z</StartBoundary>',value)
        with patch.object(app,'TASK_NAME',name),patch.object(app,'SHIPMENT_TASK',name+'_Shipments'),patch.object(app,'SOFTWARE_TASK',name+'_Software'),patch.object(app,'task_xml',side_effect=future_xml):
            try:
                app.register_tasks(Path(sys.executable))
                rows=app.tasks_preflight((name,name+'_Shipments',name+'_Software'))
                self.assertEqual({x['name'] for x in rows},{name,name+'_Shipments',name+'_Software'})
                with tempfile.TemporaryDirectory(prefix='tti-shortcuts-') as folder:
                    app.shortcuts(Path(sys.executable),[Path(folder)])
                    result=app.ps(r"$shell=New-Object -ComObject WScript.Shell;$result=@();Get-ChildItem -LiteralPath $d.folder -Filter '*.lnk' | ForEach-Object {$result+=@{target=$shell.CreateShortcut($_.FullName).TargetPath;arguments=$shell.CreateShortcut($_.FullName).Arguments}};ConvertTo-Json -InputObject @($result) -Compress",{'folder':folder})
                    self.assertEqual(len(result),3)
                    self.assertEqual({row['arguments'] for row in result},{'--backup-now','--restore','--status'})
                    self.assertTrue(all(Path(row['target'])==Path(sys.executable) for row in result))
            finally:app.ps("foreach($name in $d.names){Unregister-ScheduledTask -TaskName $name -Confirm:$false -ErrorAction SilentlyContinue};'{}'",{'names':[name,name+'_Shipments',name+'_Software']})

if __name__=='__main__':unittest.main()
