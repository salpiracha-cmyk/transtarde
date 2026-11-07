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

    def test_layout_cleanup_requires_verified_survivor_and_preserves_originals(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            bank = root / 'BANK DOCUMENTS'
            bank.mkdir()
            replacement = bank / 'Bank Shipment Documents INV-1.pdf'
            replacement.write_bytes(b'new verified combined pdf')
            digest = hashlib.sha256(replacement.read_bytes()).hexdigest()
            old = root / 'Bank Shipment Documents OLD.pdf'
            old.write_bytes(b'old generated pdf')
            master = root / 'Master Shipment Documents.pdf'
            master.write_bytes(b'master unchanged')
            legacy = root / 'TG docs'
            legacy.mkdir()
            (legacy / 'Pakistan → TG — Bank Covering Letter.docx').write_bytes(b'generated old letter')
            (legacy / 'Signed original.pdf').write_bytes(b'signed original')
            separate = root / 'SEPARATE BANK ATTACHMENTS'
            separate.mkdir()
            (separate / 'Protected phyto.pdf').write_bytes(b'protected original')
            row = {'folder':'BANK DOCUMENTS','name':replacement.name,'sha256':digest}
            with self.assertRaises(ValueError):
                agent.clean_legacy_bank_outputs(root, {'layoutVersion':2}, [{**row,'sha256':'0'*64}])
            self.assertTrue(old.exists())
            agent.clean_legacy_bank_outputs(root, {'layoutVersion':2}, [row])
            self.assertFalse(old.exists())
            self.assertFalse(legacy.exists())
            self.assertFalse(separate.exists())
            self.assertEqual((bank/'Signed original.pdf').read_bytes(),b'signed original')
            self.assertEqual((bank/'Protected phyto.pdf').read_bytes(),b'protected original')
            self.assertEqual(master.read_bytes(),b'master unchanged')
            agent.clean_legacy_bank_outputs(root, {'layoutVersion':2}, [row])
            self.assertEqual(replacement.read_bytes(),b'new verified combined pdf')


if __name__ == '__main__':
    unittest.main()
