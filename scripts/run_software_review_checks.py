"""Run existing CI test commands independently and retain an honest coverage ledger.

This runs disposable local tests, not live writes. Browser/database dependencies are
not provisioned here. A failure is retained for investigation, never silently skipped.
"""
import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import time


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--output', required=True)
    parser.add_argument('--timeout', type=int, default=150)
    parser.add_argument('--match', default='')
    args = parser.parse_args()
    root = Path(__file__).resolve().parent.parent
    output = Path(args.output).resolve()
    output.mkdir(parents=True, exist_ok=True)
    commands = {}
    for workflow in ('accounts-v1-qa.yml', 'milling-exports-qa.yml'):
        for line in (root / '.github/workflows' / workflow).read_text().splitlines():
            match = re.fullmatch(r'\s*(node|php|python3) (tests/[\w./-]+\.(?:js|php|py))\s*', line)
            if match:
                command = f'{match[1]} {match[2]}'
                commands.setdefault(command, {'command': command, 'file': match[2], 'workflows': []})['workflows'].append(workflow)
    results = []
    started = time.time()
    for command, record in commands.items():
        if args.match and not re.search(args.match, command):
            continue
        stamp = time.time()
        log = output / (record['file'].replace('/', '__') + '.log')
        try:
            with log.open('w') as stream:
                process = subprocess.run(command.split(), cwd=root, env=os.environ.copy(), stdout=stream, stderr=subprocess.STDOUT, timeout=args.timeout)
            status, code = ('passed' if process.returncode == 0 else 'failed'), process.returncode
        except subprocess.TimeoutExpired:
            status, code = 'timed_out', None
        result = {**record, 'status': status, 'exitCode': code, 'seconds': round(time.time()-stamp, 2), 'log': log.name}
        results.append(result)
        (output / 'results.json').write_text(json.dumps({'baseline': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root, text=True).strip(), 'elapsedSeconds': round(time.time()-started, 2), 'results': results}, indent=2)+'\n')
        print(f'{len(results):03d} {status:9s} {record["file"]} ({result["seconds"]}s)', flush=True)
    print(json.dumps({status: sum(r['status'] == status for r in results) for status in ('passed', 'failed', 'timed_out')}), flush=True)


if __name__ == '__main__':
    main()
