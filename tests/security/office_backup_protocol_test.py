import base64, hashlib, io, json, unittest
from email.message import Message
from unittest.mock import patch
from office_backup_agent_test import agent

class Response(io.BytesIO):
    status = 200
    def __init__(self, data, content_type='application/octet-stream', digest=None, encoding='identity'):
        super().__init__(data)
        self.headers = Message()
        self.headers['Content-Type'] = content_type
        self.headers['Content-Length'] = str(len(data))
        self.headers['Content-Encoding'] = encoding
        self.headers['X-Backup-Chunk-SHA256'] = digest or hashlib.sha256(data).hexdigest()
    def __enter__(self): return self
    def __exit__(self, *_): self.close()

class ProtocolTest(unittest.TestCase):
    def test_prepare_poll_and_chunk_retry(self):
        payload = b'piece bytes' * 10000
        metadata = {'ok': True, 'job': 'a'*32, 'state': 'ready', 'length': len(payload), 'sha256': hashlib.sha256(payload).hexdigest()}
        preparing = dict(metadata, state='preparing', stage='building')
        with patch.object(agent, 'backup_job_json', side_effect=[preparing, dict(preparing, stage='verifying'), metadata]), patch.object(agent.time, 'sleep'):
            response = agent.open_backup_response({})
        calls = []
        def send(config, action, values):
            calls.append((action, values.copy()))
            if len(calls) == 1: return Response(payload, digest='0'*64)
            return Response(payload)
        with patch.object(agent, 'backup_job_request', side_effect=send), patch.object(agent.time, 'sleep'):
            result = bytearray()
            while True:
                chunk = response.read(4096)
                if not chunk: break
                result.extend(chunk)
        self.assertEqual(bytes(result), payload)
        self.assertEqual(len(calls), 2)
        self.assertEqual(calls[0][1]['offset'], calls[1][1]['offset'])
    def test_failed_job_and_corrupt_chunks_refused(self):
        with patch.object(agent, 'backup_job_json', return_value={'ok': True, 'state': 'failed'}):
            with self.assertRaises(RuntimeError): agent.open_backup_response({})
        response = agent.PreparedBackupResponse({}, {'job':'a'*32, 'length':4, 'sha256':'0'*64})
        with patch.object(agent, 'backup_job_request', side_effect=lambda *_: Response(b'bad!', encoding='gzip')), patch.object(agent.time, 'sleep'):
            with self.assertRaisesRegex(ValueError, 'HTTP compression'): response.read(4)
    def test_delivery_independent_unicode_password_header(self):
        config={'token':'a'*64,'password':'fixture-existing-§-password'}
        class Client:
            def open(self, req, timeout):
                self.req=req
                return Response(b'{"ok":true}', content_type='application/json')
        client=Client()
        with patch.object(agent, 'opener', return_value=client):
            self.assertTrue(agent.office_agent_request(config,'poll')['ok'])
        encoded=client.req.get_header('X-tt-office-recovery')
        self.assertEqual(base64.b64decode(encoded).decode('utf-8'),config['password'])
        self.assertNotIn(config['password'],client.req.full_url)
    def test_progress_job_id_is_validated(self):
        with patch.object(agent, 'backup_job_json', return_value={'ok':True, 'state':'preparing', 'job':'../bad'}):
            with self.assertRaises(ValueError): agent.open_backup_response({})

if __name__ == '__main__': unittest.main()
