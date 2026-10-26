from datetime import datetime, timezone
import hashlib
import io
import json
from pathlib import Path
import tempfile
import subprocess
import sys
import unittest
from unittest.mock import patch
import zipfile
import importlib.util

SCRIPT = Path(__file__).parents[2] / 'scripts' / 'office_backup_agent.py'
spec = importlib.util.spec_from_file_location('office_agent', SCRIPT)
agent = importlib.util.module_from_spec(spec)
spec.loader.exec_module(agent)


class FakeResponse(io.BytesIO):
    status = 200
    def __init__(self, payload, digest, advertised_length=None, encoding='identity'):
        super().__init__(payload)
        self.headers = self
        self.digest = digest
        self.advertised_length = len(payload) if advertised_length is None else advertised_length
        self.encoding = encoding
    def get_content_type(self): return 'application/zip'
    def get(self, name, default=''):
        return {'X-Backup-SHA256': self.digest, 'Content-Length': str(self.advertised_length), 'Content-Encoding': self.encoding}.get(name, default)
    def __enter__(self): return self
    def __exit__(self, *_): self.close()


class OfficeAgentTest(unittest.TestCase):
    def test_setup_connect_preserves_config_and_never_prints_secrets(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); config = root / 'office-backup.json'; setup = root / 'connection.json'
            original = {'url': 'https://app.transtradeinternational.com/api/office_backup.php', 'token': 'a'*64,
                        'password': 'fixture-keep-password', 'destination': 'E:/keep', 'retention': {'monthly': 12}}
            config.write_text(json.dumps(original)); setup.write_text(json.dumps({'ticket': 'b'*64}))
            output = io.StringIO()
            response = FakeResponse(json.dumps({'ok': True, 'token': 'c'*64}).encode(), '')
            with patch.object(agent, 'opener') as op, patch.object(agent.sys, 'stdout', output):
                op.return_value.open.return_value = response
                agent.connect_setup(config, setup)
                sent = op.return_value.open.call_args.args[0]
                self.assertEqual(sent.full_url, 'https://app.transtradeinternational.com/api/office_backup_connect.php')
                self.assertEqual(json.loads(sent.data), {'ticket': 'b'*64})
            self.assertEqual(json.loads(config.read_text()), {**original, 'token': 'c'*64})
            self.assertNotIn('c'*64, output.getvalue()); self.assertNotIn(original['password'], output.getvalue())

    def test_setup_preflight_failure_does_not_consume_ticket(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); config = root / 'config.json'; setup = root / 'setup.json'
            config.write_text(json.dumps({'url': 'https://app.transtradeinternational.com/api/office_backup.php', 'token': 'a'*64}))
            setup.write_text(json.dumps({'ticket': 'b'*64}))
            with patch.object(agent, 'write_json', side_effect=PermissionError('fixture locked')), patch.object(agent, 'opener') as op:
                with self.assertRaises(PermissionError): agent.connect_setup(config, setup)
                op.assert_not_called()

    def test_duplicate_process_preserves_active_log_and_lock_releases(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            config = root / 'office-backup.json'
            config.write_text('{}')
            log = root / 'latest.log'
            log.write_text('active backup evidence')
            with agent.backup_run_lock(config) as acquired:
                self.assertTrue(acquired)
                child = subprocess.run([sys.executable, str(SCRIPT), '--config', str(config), '--backup-only', '--log-file', str(log)], capture_output=True, text=True, timeout=20)
                self.assertEqual(child.returncode, 0, child.stderr)
                self.assertIn('duplicate run was skipped', child.stdout)
                self.assertEqual(log.read_text(), 'active backup evidence')
            # A persistent lock file must not block the next process once the
            # OS lock has been released, including on Windows.
            code = '''import importlib.util,sys
from pathlib import Path
spec=importlib.util.spec_from_file_location('agent',sys.argv[1])
mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
with mod.backup_run_lock(Path(sys.argv[2])) as acquired:
    sys.exit(0 if acquired else 2)
'''
            child = subprocess.run([sys.executable, '-c', code, str(SCRIPT), str(config)], capture_output=True, text=True, timeout=20)
            self.assertEqual(child.returncode, 0, child.stderr)

    def test_state_write_failure_never_repeats_verified_download(self):
        for preflight_fails in (False, True):
            with self.subTest(preflight=preflight_fails), tempfile.TemporaryDirectory() as folder:
                root = Path(folder)
                config = root / 'office-backup.json'
                config.write_text(json.dumps({'destination': str(root)}))
                state = root / 'office-backup-state.json'
                state.write_text('{"last_success":1}')
                verified = root / 'verified.zip'
                verified.write_bytes(b'completed verified fixture')
                failures = [PermissionError('fixture locked')] if preflight_fails else [None, PermissionError('fixture locked')]
                argv = ['agent', '--config', str(config), '--backup-only', '--force']
                output, errors = io.StringIO(), io.StringIO()
                with patch.object(agent.sys, 'argv', argv), patch.object(agent, 'write_json', side_effect=failures), patch.object(agent, 'pull', return_value=(verified, 'a'*64)) as pull, patch.object(agent.time, 'sleep') as sleep, patch.object(agent.sys, 'stdout', output), patch.object(agent.sys, 'stderr', errors):
                    self.assertEqual(agent.main(), 1)
                self.assertEqual(pull.call_count, 0 if preflight_fails else 1)
                sleep.assert_not_called()
                self.assertEqual(verified.read_bytes(), b'completed verified fixture')
                self.assertEqual(state.read_text(), '{"last_success":1}')
                self.assertNotIn('retrying shortly', errors.getvalue())
                if not preflight_fails:
                    self.assertIn('Verified encrypted backup saved:', output.getvalue())
                    self.assertIn('No repeat download was started', errors.getvalue())

    def test_windows_state_commit_retries_are_bounded_and_atomic(self):
        for failures in (2, 5):
            with self.subTest(failures=failures), tempfile.TemporaryDirectory() as folder:
                root = Path(folder)
                state = root / 'office-backup-state.json'
                state.write_text('{"previous":true}')
                real_replace = agent.os.replace
                attempts = []
                def replace(source, target):
                    attempts.append(1)
                    if len(attempts) <= failures:
                        exc = PermissionError('fixture Windows sharing conflict')
                        exc.winerror = 32
                        raise exc
                    return real_replace(source, target)
                with patch.object(agent.os, 'replace', side_effect=replace), patch.object(agent.time, 'sleep') as sleep:
                    if failures == 5:
                        with self.assertRaises(PermissionError): agent.write_json(state, {'updated':True})
                        self.assertEqual(state.read_text(), '{"previous":true}')
                    else:
                        agent.write_json(state, {'updated':True})
                        self.assertEqual(json.loads(state.read_text()), {'updated':True})
                self.assertEqual(len(attempts), min(5, failures+1))
                self.assertEqual(sleep.call_count, min(4, failures))
                self.assertFalse(list(root.glob('.office-*')))

    def test_closing_backup_and_missed_run_catchup(self):
        cases = [
            ('2026-10-20T18:00:00+05:00', '2026-10-20T15:00:00+05:00', None, 1),
            ('2026-10-20T18:30:00+05:00', '2026-10-20T18:10:00+05:00', '2026-10-20T18:00:00+05:00', 0),
            ('2026-10-21T08:00:00+05:00', '2026-10-20T18:10:00+05:00', '2026-10-20T18:00:00+05:00', 0),
            ('2026-10-21T18:00:00+05:00', '2026-10-20T18:10:00+05:00', '2026-10-20T18:00:00+05:00', 1),
            ('2026-10-20T08:00:00+05:00', '2026-10-18T18:10:00+05:00', '2026-10-18T18:00:00+05:00', 1),
        ]
        for now_text, last_text, slot, expected_pulls in cases:
            with self.subTest(now=now_text, slot=slot), tempfile.TemporaryDirectory() as folder:
                root = Path(folder)
                config = root / 'office-backup.json'
                config.write_text(json.dumps({'destination':str(root)}))
                daily = root / 'Daily' / 'verified.zip'
                daily.parent.mkdir()
                daily.write_bytes(b'previous verified fixture')
                state = {'last_success':datetime.fromisoformat(last_text).timestamp(),'last_file':daily.name}
                if slot: state['scheduled_for'] = slot
                state_path = root / 'office-backup-state.json'
                state_path.write_text(json.dumps(state))
                now = datetime.fromisoformat(now_text).timestamp()
                argv = ['agent','--config',str(config),'--backup-only','--scheduled']
                with patch.object(agent.sys,'argv',argv), patch.object(agent.time,'time',return_value=now), patch.object(agent,'pull',return_value=(daily,'a'*64)) as pull:
                    self.assertEqual(agent.main(),0)
                    self.assertEqual(pull.call_count,expected_pulls)
                    if expected_pulls:
                        due = pull.call_args.kwargs['scheduled_for']
                        self.assertEqual(due.hour,18)
                        self.assertLessEqual(due.timestamp(),now)
                        self.assertLess(now-due.timestamp(),86400)
                        self.assertEqual(json.loads(state_path.read_text())['scheduled_for'],due.isoformat())

    def test_interrupted_or_transformed_download_preserves_completed_backups(self):
        config = {'url': 'https://app.transtradeinternational.com/api/office_backup.php', 'token': 'fixture-token', 'password': 'fixture-password'}
        for response, message in [(FakeResponse(b'', '0' * 64, 1000), 'received 0 of 1,000'),
                                  (FakeResponse(b'partial', '0' * 64, 1000), 'received 7 of 1,000'),
                                  (FakeResponse(b'compressed', '0' * 64, encoding='gzip'), 'HTTP compression')]:
            with self.subTest(message=message), tempfile.TemporaryDirectory() as folder:
                root = Path(folder)
                daily = root / 'Daily'
                daily.mkdir()
                survivor = daily / 'TRANSTRADE_FULL_2026-10-09_1004_verified.zip'
                survivor.write_bytes(b'previous verified recovery copy')
                with patch.object(agent, 'open_backup_response') as opener:
                    opener.return_value = response
                    with self.assertRaisesRegex(ValueError, message):
                        agent.pull(config, root)
                self.assertEqual(survivor.read_bytes(), b'previous verified recovery copy')
                self.assertEqual(list(daily.iterdir()), [survivor])
                self.assertFalse(list(root.glob('.downloading-*')))

    def test_daily_schedule_and_missing_local_copy(self):
        for age, exists, force, pulls in [(3600, True, False, 0),
                                          (23 * 3600, True, False, 1),
                                          (2 * 86400, True, False, 1),
                                          (3600, False, False, 1),
                                          (-3600, True, False, 1),
                                          (3600, True, True, 1)]:
            with self.subTest(age=age, exists=exists, force=force), tempfile.TemporaryDirectory() as folder:
                root = Path(folder)
                config = root / 'office-backup.json'
                config.write_text(json.dumps({'destination': str(root)}))
                daily = root / 'Daily' / 'verified.zip'
                daily.parent.mkdir()
                if exists: daily.write_bytes(b'previous verified fixture')
                (root / 'office-backup-state.json').write_text(json.dumps({
                    'last_success': 1_000_000 - age, 'last_file': daily.name}))
                argv = ['agent', '--config', str(config), '--backup-only'] + (['--force'] if force else [])
                with patch.object(agent.sys, 'argv', argv), patch.object(agent.time, 'time', return_value=1_000_000), patch.object(agent, 'pull', return_value=(daily, 'a' * 64)) as pull:
                    self.assertEqual(agent.main(), 0)
                    self.assertEqual(pull.call_count, pulls)
                if pulls:
                    self.assertEqual(json.loads((root / 'office-backup-state.json').read_text())['last_success'], 1_000_000)

    def test_verified_pull_and_retention(self):
        contents = io.BytesIO()
        with zipfile.ZipFile(contents, 'w') as archive:
            archive.writestr('README.txt', 'Encrypted recovery')
            archive.writestr('Encrypted/000001.bin', b'ciphertext')
            archive.writestr('backup-manifest.json', json.dumps({'application': 'Transtrade', 'type': 'full_recovery', 'encryption': {'algorithm': 'AES-256-GCM'}, 'encryptedEntries': {'System_Recovery/private/auth.json': {'stored': 'Encrypted/000001.bin'}}}))
        payload = contents.getvalue(); digest = hashlib.sha256(payload).hexdigest()
        with tempfile.TemporaryDirectory() as folder:
            dest = Path(folder)
            for n in range(6): (dest / f'weekly-2026010{n}-000000-{n}.zip').write_bytes(b'old')
            for n in range(4): (dest / f'monthly-20250{n}-{n}.zip').write_bytes(b'old')
            response = lambda *_args, **_kw: FakeResponse(payload, digest)
            with patch.object(agent, 'open_backup_response') as opener:
                opener.side_effect = response
                path, actual = agent.pull({'url': 'https://app.transtradeinternational.com/api/office_backup.php', 'token': 'x', 'password': 'password123456'}, dest)
            self.assertEqual(len(list((dest / 'Daily').glob('*.zip'))), 1)
            self.assertEqual(len(list((dest / 'Weekly').glob('*.zip'))), 1)
            self.assertEqual(len(list((dest / 'Monthly').glob('*.zip'))), 1)
            # Two Sunday pulls must not create two weekly retention points.
            with patch.object(agent, 'open_backup_response') as opener, patch.object(agent, 'datetime') as clock:
                opener.side_effect = response
                for hour in (10, 11):
                    clock.now.return_value = datetime(2026, 10, 25, hour, tzinfo=timezone.utc)
                    agent.pull({'url': 'https://app.transtradeinternational.com/api/office_backup.php', 'token': 'x', 'password': 'password123456'}, dest)
            self.assertEqual(len(list((dest / 'Weekly').glob('TRANSTRADE_FULL_2026-W43_*.zip'))), 1)
            with patch.object(agent, 'open_backup_response') as opener, patch.object(agent, 'datetime') as clock:
                opener.side_effect = response
                clock.now.return_value = datetime(2026, 10, 25, 12, tzinfo=timezone.utc)
                agent.pull({'url': 'https://app.transtradeinternational.com/api/office_backup.php', 'token': 'x', 'password': 'password123456'}, dest, refresh_tiers=True)
            self.assertEqual(len(list((dest / 'Weekly').glob('TRANSTRADE_FULL_2026-W43_*.zip'))), 2)
            self.assertEqual(len(list((dest / 'Monthly').glob('TRANSTRADE_FULL_2026-10_*.zip'))), 2)
            self.assertEqual(actual, digest)
            self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(), digest)
            self.assertEqual(len(list(dest.glob('weekly-*.zip'))), 4)
            self.assertEqual(len(list(dest.glob('monthly-*.zip'))), 3)
            with patch.object(agent, 'open_backup_response') as opener:
                opener.return_value = FakeResponse(payload, '0' * 64)
                with self.assertRaisesRegex(ValueError, 'checksum mismatch'):
                    agent.pull({'url': 'https://app.transtradeinternational.com/api/office_backup.php', 'token': 'x', 'password': 'password123456'}, dest)
            self.assertFalse(list(dest.glob('.downloading-*')))

        # November starts on Sunday. Catching up that slot on Monday morning
        # must not create Monday's weekly/monthly point before closing time.
        with tempfile.TemporaryDirectory() as folder:
            dest = Path(folder)
            config = {'url':'https://app.transtradeinternational.com/api/office_backup.php','token':'fixture','password':'fixture-password'}
            with patch.object(agent,'open_backup_response') as opener, patch.object(agent,'datetime') as clock:
                opener.side_effect = response
                clock.now.return_value = datetime.fromisoformat('2026-11-02T08:00:00+05:00')
                agent.pull(config,dest,scheduled_for=datetime.fromisoformat('2026-11-01T18:00:00+05:00'))
                self.assertFalse(list((dest/'Monthly').glob('*.zip')))
                self.assertFalse(list((dest/'Weekly').glob('TRANSTRADE_FULL_2026-W45_*.zip')))
                clock.now.return_value = datetime.fromisoformat('2026-11-02T18:00:00+05:00')
                agent.pull(config,dest,scheduled_for=datetime.fromisoformat('2026-11-02T18:00:00+05:00'))
                self.assertEqual(len(list((dest/'Monthly').glob('TRANSTRADE_FULL_2026-11_*.zip'))),1)
                self.assertEqual(len(list((dest/'Weekly').glob('TRANSTRADE_FULL_2026-W45_*.zip'))),1)


if __name__ == '__main__': unittest.main()
