from datetime import datetime, timezone
import hashlib
import io
import json
from pathlib import Path
import tempfile
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
                with patch.object(agent.request, 'build_opener') as opener:
                    opener.return_value.open.return_value = response
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
            with patch.object(agent.request, 'build_opener') as opener:
                opener.return_value.open.side_effect = response
                path, actual = agent.pull({'url': 'https://app.transtradeinternational.com/api/office_backup.php', 'token': 'x', 'password': 'password123456'}, dest)
            self.assertEqual(len(list((dest / 'Daily').glob('*.zip'))), 1)
            self.assertEqual(len(list((dest / 'Weekly').glob('*.zip'))), 1)
            self.assertEqual(len(list((dest / 'Monthly').glob('*.zip'))), 1)
            # Two Sunday pulls must not create two weekly retention points.
            with patch.object(agent.request, 'build_opener') as opener, patch.object(agent, 'datetime') as clock:
                opener.return_value.open.side_effect = response
                for hour in (10, 11):
                    clock.now.return_value = datetime(2026, 10, 25, hour, tzinfo=timezone.utc)
                    agent.pull({'url': 'https://app.transtradeinternational.com/api/office_backup.php', 'token': 'x', 'password': 'password123456'}, dest)
            self.assertEqual(len(list((dest / 'Weekly').glob('TRANSTRADE_FULL_2026-W43_*.zip'))), 1)
            self.assertEqual(actual, digest)
            self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(), digest)
            self.assertEqual(len(list(dest.glob('weekly-*.zip'))), 4)
            self.assertEqual(len(list(dest.glob('monthly-*.zip'))), 3)
            with patch.object(agent.request, 'build_opener') as opener:
                opener.return_value.open.return_value = FakeResponse(payload, '0' * 64)
                with self.assertRaisesRegex(ValueError, 'checksum mismatch'):
                    agent.pull({'url': 'https://app.transtradeinternational.com/api/office_backup.php', 'token': 'x', 'password': 'password123456'}, dest)
            self.assertFalse(list(dest.glob('.downloading-*')))


if __name__ == '__main__': unittest.main()
