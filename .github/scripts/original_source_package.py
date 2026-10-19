"""Build a read-only stdin verifier from exact Git objects; no host writes."""
import base64
import hashlib
import json
import pathlib
import subprocess

EXCLUDED = {'.github', 'tests', 'docs', 'data', 'uploads', 'storage', 'cache', 'logs'}
BASELINE = '39e505c3152572feae96350124b00b372ff07138'


def git(*args):
    return subprocess.check_output(['git', *args])


def manifest(revision):
    result = {}
    for name in git('ls-tree', '-r', '--name-only', '-z', revision).decode().split('\0'):
        if not name or name == 'AGENTS.md' or name.split('/')[0] in EXCLUDED:
            continue
        result[name] = hashlib.sha256(git('show', revision + ':' + name)).hexdigest()
    return result


def build():
    head = git('rev-parse', 'HEAD').decode().strip()
    current = manifest(head)
    manifests = {head: current}
    # The last accepted deployment may precede the separately queued login repair.
    # Accept one WHOLE known tree only when its sole app differences are those two
    # authentication files. A mixed old/new tree never satisfies either candidate.
    baseline = manifest(BASELINE)
    changed = {name for name in current.keys() | baseline.keys() if current.get(name) != baseline.get(name)}
    if current.keys() == baseline.keys() and changed <= {'auth_store.php', 'session_store.php'}:
        manifests[BASELINE] = baseline
    encoded = base64.b64encode(json.dumps(manifests).encode()).decode()
    core = pathlib.Path('.github/scripts/original_source_match.php').read_text()
    return core + "\nos_match_main((string)getenv('TT_SOURCE_ROOT'), json_decode(base64_decode('" + encoded + "'),true,512,JSON_THROW_ON_ERROR));\n"


if __name__ == '__main__':
    print(build())
