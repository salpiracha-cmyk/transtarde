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
    def __init__(self, payload, digest):
        super().__init__(payload)
        self.headers = self
        self.digest = digest
    def get_content_type(self): return 'application/zip'
    def get(self, name, default=''):
        return {'X-Backup-SHA256': self.digest, 'Content-Length': str(len(self.getvalue()))}.get(name, default)
    def __enter__(self): return self
    def __exit__(self, *_): self.close()


class OfficeAgentTest(unittest.TestCase):
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
