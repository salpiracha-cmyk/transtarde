import base64
import importlib.util
import json
import pathlib
import re
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('source_package', pathlib.Path(__file__).with_name('original_source_package.py'))
package = importlib.util.module_from_spec(spec)
spec.loader.exec_module(package)


class CandidateBoundary(unittest.TestCase):
    def test_exact_release_never_accepts_or_reads_old_baseline(self):
        head = 'a' * 40
        current = {'accounts/accounting_master_v1.json': 'chart', 'api/bank_reconciliation.php': 'endpoint'}
        with patch.object(package, 'git', return_value=(head + '\n').encode()), patch.object(package, 'manifest', return_value=current) as manifest:
            result = package.build(exact=True)
        manifest.assert_called_once_with(head)
        encoded = re.search(r"base64_decode\('([^']+)'\)", result)[1]
        self.assertEqual(json.loads(base64.b64decode(encoded)), {head: current})

    def candidates(self, old, current):
        head = 'a' * 40
        with patch.object(package, 'git', return_value=(head + '\n').encode()), patch.object(package, 'manifest', side_effect=[current, old]):
            result = package.build()
        encoded = re.search(r"base64_decode\('([^']+)'\)", result)[1]
        return json.loads(base64.b64decode(encoded))

    def test_only_auth_change_allows_complete_known_baseline(self):
        old = {'auth_store.php': 'old', 'session_store.php': 'old', 'exports/app.js': 'same'}
        current = dict(old, **{'auth_store.php': 'new', 'session_store.php': 'new'})
        self.assertEqual(len(self.candidates(old, current)), 2)

    def test_other_app_change_cannot_accept_old_tree(self):
        old = {'auth_store.php': 'old', 'exports/app.js': 'old'}
        current = dict(old, **{'exports/app.js': 'new'})
        self.assertEqual(list(self.candidates(old, current)), ['a' * 40])

    def test_different_file_set_cannot_accept_old_tree(self):
        old = {'auth_store.php': 'old'}
        current = dict(old, **{'new-runtime.php': 'new'})
        self.assertEqual(list(self.candidates(old, current)), ['a' * 40])


if __name__ == '__main__':
    unittest.main()
