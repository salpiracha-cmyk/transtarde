"""Real HTTP regression for buffered/compressed ZIP corruption; fixture only."""
import hashlib
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
from urllib import request


@unittest.skipUnless(shutil.which('php'), 'PHP CLI is required for real HTTP verification')
class BackupStreamHTTPTest(unittest.TestCase):
    def test_existing_buffers_and_gzip_negotiation_do_not_change_zip_bytes(self):
        library = Path(__file__).resolve().parents[2] / 'backup_lib.php'
        payload = b'PK-fixture-byte-exact-transfer-' * 110000
        expected = hashlib.sha256(payload).hexdigest()
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / 'fixture.zip').write_bytes(payload)
            code = "<?php require '" + str(library).replace("'", "\\'") + "'; ob_start(); echo 'buffered-junk-must-not-leak'; tt_backup_stream_file(__DIR__.'/fixture.zip','fixture.zip',hash_file('sha256',__DIR__.'/fixture.zip'));"
            (root / 'index.php').write_text(code)
            with socket.socket() as listener:
                listener.bind(('127.0.0.1', 0))
                port = listener.getsockname()[1]
            server = subprocess.Popen(['php','-d','zlib.output_compression=1','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            try:
                deadline = time.monotonic() + 10
                while True:
                    try:
                        with socket.create_connection(('127.0.0.1',port),timeout=0.2): break
                    except OSError:
                        if time.monotonic() >= deadline: raise
                        time.sleep(0.05)
                for encoding in ['identity','gzip']:
                    with self.subTest(encoding=encoding):
                        req = request.Request(f'http://127.0.0.1:{port}/',headers={'Accept-Encoding':encoding})
                        with request.urlopen(req,timeout=10) as response:
                            self.assertEqual(response.status,200)
                            self.assertEqual(int(response.headers['Content-Length']),len(payload))
                            self.assertEqual(response.headers['X-Backup-SHA256'],expected)
                            self.assertEqual(response.headers.get('Content-Encoding'),'identity')
                            self.assertIn('no-transform',response.headers['Cache-Control'])
                            self.assertEqual(response.read(),payload)
            finally:
                server.terminate()
                server.wait(timeout=10)


if __name__ == '__main__': unittest.main()
