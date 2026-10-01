import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).parents[2] / 'scripts' / 'office_backup_agent.py'
spec = importlib.util.spec_from_file_location('office_agent', SCRIPT)
agent = importlib.util.module_from_spec(spec)
spec.loader.exec_module(agent)


class OfficeAgentShipmentJobTest(unittest.TestCase):
    def test_shipment_job_writes_and_verifies_configured_archive(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            payloads = {
                '001-master.pdf': b'%PDF-master',
                '002-cover.docx': b'PK-covering-letter',
                '003-customs.pdf': b'%PDF-customs',
            }
            job = {
                'id': 'a' * 32,
                'type': 'shipment_archive',
                'folderParts': ['QA Customer', 'SHIPMENT #01', 'LOT #02'],
                'files': [
                    {'storedName': name, 'name': out, 'folder': sub, 'sha256': hashlib.sha256(payloads[name]).hexdigest()}
                    for name, out, sub in [
                        ('001-master.pdf', 'Master Shipment Documents.pdf', ''),
                        ('002-cover.docx', 'Bank Covering Letter.docx', ''),
                        ('003-customs.pdf', 'Custom documents.pdf', 'Custom documents'),
                    ]
                ],
            }
            acknowledgements = []

            def fake_download(_config, _job_id, row, temp_dir):
                target = temp_dir / row['storedName']
                target.write_bytes(payloads[row['storedName']])
                return target, hashlib.sha256(payloads[row['storedName']]).hexdigest()

            def fake_request(_config, action, payload=None, **_kwargs):
                if action == 'ack':
                    acknowledgements.append(payload)
                    return {'ok': True}
                raise AssertionError(action)

            with patch.object(agent, 'download_job_file', side_effect=fake_download), patch.object(agent, 'office_agent_request', side_effect=fake_request):
                path, count = agent.process_shipment_job({'shipmentArchiveDestination': str(root)}, job)

            self.assertEqual(count, 3)
            self.assertEqual(path.resolve(), (root / 'QA Customer' / 'SHIPMENT #01' / 'LOT #02').resolve())
            self.assertTrue((path / 'Master Shipment Documents.pdf').is_file())
            self.assertTrue((path / 'Bank Covering Letter.docx').is_file())
            self.assertTrue((path / 'Custom documents' / 'Custom documents.pdf').is_file())
            self.assertEqual(acknowledgements[-1]['status'], 'COMPLETE')
            self.assertEqual(len(acknowledgements[-1]['files']), 3)


if __name__ == '__main__':
    unittest.main()
