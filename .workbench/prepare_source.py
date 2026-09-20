"""QA transport only. Reconstruct the exact locally-tested edits on the pinned main source."""
import base64
import hashlib
import json
import lzma
import os
from pathlib import Path
import subprocess

work = Path(os.environ['RUNNER_TEMP'])
raw = base64.b64decode(''.join((work / f'source-0{i}.b64').read_text().strip() for i in range(4)), validate=True)
assert hashlib.sha256(raw).hexdigest() == 'c109e596ea8433210c26878254a491919fd22bb2b6b95eae2a264bdc7683eb76', 'Source transport checksum mismatch'
bundle = json.loads(lzma.decompress(raw))
assert bundle['base'] == '92c03efbfd1dcdc80920dd671bfe013e02c4ca4f'
assert subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip() == bundle['base']
assert len(bundle['files']) == 30
manifest = []
for entry in bundle['files']:
    name = entry['path']
    path = Path(name)
    assert not path.is_absolute() and '..' not in path.parts and not name.startswith('.')
    assert path.parts[0] in ['accounts', 'api', 'milling', 'tests'] or name in ['auth_store.php', 'module.php', 'product_stage.php']
    if entry['before'] is None:
        assert not path.exists(), name
        previous = ''
    else:
        old = path.read_bytes()
        assert hashlib.sha256(old).hexdigest() == entry['before'], name
        previous = old.decode('utf-8')
    result = previous
    end_before = len(previous) + 1
    for start, end, replacement in reversed(entry['edits']):
        assert 0 <= start <= end <= len(previous) and end <= end_before, name
        result = result[:start] + replacement + result[end:]
        end_before = start
    output = result.encode('utf-8')
    assert hashlib.sha256(output).hexdigest() == entry['after'], name
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(output)
    manifest.append({k: v for k, v in entry.items() if k != 'edits'})
(work / 'source-manifest.json').write_text(json.dumps(manifest, indent=2))
print('Verified direct source edits reconstructed for', len(manifest), 'files; base', bundle['base'])
