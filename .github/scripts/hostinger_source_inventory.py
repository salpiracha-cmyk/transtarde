"""Read production file metadata and compare it with the Git release tree."""
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys

RUNTIME = {'data', 'uploads', 'storage', 'cache', 'logs'}
EXCLUDED = RUNTIME | {'.git', '.github', 'tests', 'docs', 'ops', 'node_modules'}
CODE = re.compile(r'\.(php[0-9]?|phtml|phar|js|mjs|css|html?|py|sh)(\.|$)|^\.(htaccess|user\.ini)$', re.I)

def inventory(root):
    if not re.fullmatch(r'/home/[\w-]+/domains/app\.transtradeinternational\.com/public_html', str(root)) or root.resolve() != root:
        raise SystemExit('Unexpected host path')
    marker = root / '.transtrade-release'
    release = marker.read_text().strip() if marker.is_file() else None
    if release is not None and not re.fullmatch(r'[a-f0-9]{40}', release):
        raise SystemExit('Unexpected release marker')
    files = {}
    links = []
    for directory, folders, names in os.walk(root, onerror=lambda error: sys.exit(str(error))):
        relative = Path(directory).relative_to(root)
        for name in folders[:]:
            if (Path(directory) / name).is_symlink():
                links.append(str(relative / name))
                folders.remove(name)
            elif relative == Path('.') and name in RUNTIME:
                folders.remove(name)
        for name in names:
            path = Path(directory) / name
            if path.is_symlink():
                links.append(str(relative / name))
            elif path.is_file():
                size = path.stat().st_size
                digest = None
                if size <= 100 * 1024**2:
                    with path.open('rb') as source:
                        checksum = hashlib.sha256()
                        for block in iter(lambda: source.read(1024**2), b''):
                            checksum.update(block)
                        digest = checksum.hexdigest()
                files[str(relative / name)] = [size, digest]
    cron = subprocess.run(['crontab', '-l'], text=True, capture_output=True) if shutil.which('crontab') else subprocess.CompletedProcess([], 127, '')
    targets = re.findall(r'(?:public_html|staging)/([\w./-]+\.(?:php|sh|py))\b', cron.stdout)
    siblings = sorted(p.name for p in root.parent.iterdir() if p.is_dir() and not p.is_symlink())
    print(json.dumps(dict(release=release, files=files, links=links, cronReadable=cron.returncode == 0,
                          cronTargets=sorted(set(targets)), siblingDirectories=siblings)))

def compare(report):
    from original_source_package import manifest
    live = report.pop('files')
    reference = report['release'] or '2c900eb72e60e0a993cb169c79916e77ab11d4d7'
    expected = manifest(reference)
    missing = sorted(expected.keys() - live.keys())
    changes = sorted(name for name in expected.keys() & live.keys() if live[name][1] != expected[name])
    extra = live.keys() - expected.keys()
    extra_code = {name: live[name][0] for name in sorted(extra) if CODE.search(Path(name).name)}
    identical = {}
    for name, digest in expected.items():
        identical.setdefault(digest, []).append(name)
    duplicates = {name: identical[live[name][1]] for name in extra_code if live[name][1] in identical}
    by_folder = {}
    for name in extra:
        key = name.split('/')[0] if '/' in name else '(root)'
        count, size = by_folder.get(key, (0, 0))
        by_folder[key] = (count + 1, size + live[name][0])
    print(json.dumps(dict(**report, reference=reference, expected=len(expected), matched=len(expected)-len(missing)-len(changes),
                          missing=missing, changed=changes, extraCode=extra_code, exactCopies=duplicates,
                          extraByFolder=by_folder), indent=2, sort_keys=True))

if sys.argv[-1] == '--compare':
    compare(json.loads(Path(sys.argv[1]).read_text()))
else:
    inventory(Path(sys.argv[1]))
