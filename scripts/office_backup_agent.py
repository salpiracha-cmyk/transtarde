#!/usr/bin/env python3
"""Transtrade office backup agent. Python 3.10+ standard library only."""
import argparse
import getpass
import hashlib
import json
import os
from pathlib import Path
import shutil
import ssl
import sys
import tempfile
import time
from datetime import datetime, timezone
from urllib import error, parse, request
import zipfile


def config_path():
    root = Path(os.environ.get('APPDATA') or Path.home() / '.config') / 'Transtrade'
    return root / 'office-backup.json'


def protect(path):
    if os.name == 'nt':
        import subprocess
        identity = os.environ.get('USERDOMAIN', '') + '\\' + os.environ.get('USERNAME', '')
        result = subprocess.run(['icacls', str(path), '/inheritance:r', '/grant:r', identity + ':F'], capture_output=True, text=True)
        if result.returncode: raise RuntimeError('Could not restrict configuration permissions: ' + result.stderr.strip())
    else:
        path.chmod(0o600)


def write_json(path, value, sensitive=False):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temp = tempfile.mkstemp(prefix='.office-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as out:
            json.dump(value, out, indent=2)
            out.flush(); os.fsync(out.fileno())
        if sensitive: protect(Path(temp))
        os.replace(temp, path)
        if sensitive: protect(path)
    finally:
        if os.path.exists(temp): os.unlink(temp)


def initialize(path):
    if path.exists(): raise RuntimeError('Configuration already exists. Rotate the server credential and remove the old configuration before initializing again.')
    print('Generate a backup-only credential in Super Admin > Backup & Data Export first.')
    token = getpass.getpass('Backup-only credential: ').strip()
    password = getpass.getpass('Recovery ZIP password (12+ characters): ')
    confirm = getpass.getpass('Repeat recovery ZIP password: ')
    if len(token) != 64 or any(c not in '0123456789abcdef' for c in token): raise ValueError('Invalid backup-only credential.')
    if len(password) < 12 or password != confirm: raise ValueError('Recovery password is too short or does not match.')
    folder = input('Backup folder: ').strip()
    if not folder: raise ValueError('Backup folder required.')
    destination = Path(folder).expanduser().resolve()
    destination.mkdir(parents=True, exist_ok=True)
    write_json(path, {'url': 'https://app.transtradeinternational.com/api/office_backup.php', 'destination': str(destination), 'token': token, 'password': password}, sensitive=True)
    print('Configuration saved. Schedule this script at startup/login and weekly; enable hourly retry on failure.')


class NoRedirect(request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise RuntimeError('Backup endpoint redirected unexpectedly.')


def validate_zip(path):
    with zipfile.ZipFile(path) as archive:
        names = set(archive.namelist())
        if 'backup-manifest.json' not in names: raise ValueError('Missing recovery manifest.')
        info = archive.getinfo('backup-manifest.json')
        if info.file_size > 64 * 1024 * 1024: raise ValueError('Recovery manifest is too large.')
        manifest = json.loads(archive.read(info))
        if manifest.get('application') != 'Transtrade' or manifest.get('type') != 'full_recovery': raise ValueError('Not a complete Transtrade recovery backup.')
        if manifest.get('encryption', {}).get('algorithm') != 'AES-256-GCM': raise ValueError('Recovery backup is not encrypted.')
        entries = manifest.get('encryptedEntries', {})
        if not entries or 'System_Recovery/private/auth.json' not in entries: raise ValueError('Recovery data is missing.')
        if any(not name.startswith('Encrypted/') and name not in ('README.txt', 'backup-manifest.json') for name in names):
            raise ValueError('Unexpected unencrypted recovery entry.')


def pull(config, destination):
    url = config['url']; parsed = parse.urlparse(url)
    if parsed.scheme != 'https' or parsed.hostname != 'app.transtradeinternational.com' or parsed.port not in (None, 443) or parsed.path != '/api/office_backup.php' or parsed.username or parsed.password or parsed.query or parsed.fragment:
        raise ValueError('Backup endpoint must be the production HTTPS backup URL.')
    destination.mkdir(parents=True, exist_ok=True)
    payload = json.dumps({'password': config['password']}).encode('utf-8')
    req = request.Request(url, data=payload, headers={'Content-Type': 'application/json', 'X-TT-Backup-Token': config['token']}, method='POST')
    opener = request.build_opener(request.HTTPSHandler(context=ssl.create_default_context()), NoRedirect())
    temp = None
    try:
        with opener.open(req, timeout=300) as response:
            if response.status != 200 or response.headers.get_content_type() != 'application/zip': raise ValueError('Unexpected backup response.')
            expected = response.headers.get('X-Backup-SHA256', '').lower()
            if len(expected) != 64 or any(c not in '0123456789abcdef' for c in expected): raise ValueError('Missing transfer checksum.')
            length = int(response.headers.get('Content-Length', '0'))
            if length <= 0 or shutil.disk_usage(destination).free < length * 2: raise RuntimeError('Not enough free disk space for verified backup.')
            fd, temp = tempfile.mkstemp(prefix='.downloading-', dir=destination)
            digest = hashlib.sha256(); received = 0
            with os.fdopen(fd, 'wb') as out:
                while True:
                    chunk = response.read(1024 * 1024)
                    if not chunk: break
                    out.write(chunk); digest.update(chunk); received += len(chunk)
                out.flush(); os.fsync(out.fileno())
            if received != length or digest.hexdigest() != expected: raise ValueError('Backup transfer checksum mismatch.')
        validate_zip(temp)
        stamp = datetime.now(timezone.utc).strftime('%Y%m%d-%H%M%S')
        weekly = destination / ('weekly-' + stamp + '-' + expected[:12] + '.zip')
        os.replace(temp, weekly); temp = None
        monthly = destination / ('monthly-' + stamp[:6] + '-' + expected[:12] + '.zip')
        if not list(destination.glob('monthly-' + stamp[:6] + '-*.zip')):
            shutil.copy2(weekly, monthly)
            with open(monthly, 'rb') as copied:
                copied_digest = hashlib.file_digest(copied, 'sha256').hexdigest()
            if copied_digest != expected:
                monthly.unlink(missing_ok=True); raise ValueError('Monthly copy checksum mismatch.')
        for pattern, count in [('weekly-*.zip', 4), ('monthly-*.zip', 3)]:
            files = sorted(destination.glob(pattern), reverse=True)
            for old in files[count:]: old.unlink()
        return weekly, expected
    finally:
        if temp and os.path.exists(temp): os.unlink(temp)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', type=Path, default=config_path())
    parser.add_argument('--init', action='store_true', help='Save backup-only credentials and destination securely')
    parser.add_argument('--force', action='store_true', help='Pull now even if a weekly backup succeeded recently')
    args = parser.parse_args()
    try:
        if args.init: initialize(args.config); return 0
        config = json.loads(args.config.read_text(encoding='utf-8'))
        destination = Path(config['destination']).expanduser().resolve()
        state_path = args.config.with_name('office-backup-state.json')
        state = json.loads(state_path.read_text(encoding='utf-8')) if state_path.exists() else {}
        if not args.force and time.time() - float(state.get('last_success', 0)) < 7 * 86400:
            print('Weekly backup is current.'); return 0
        for attempt in range(3):
            try:
                path, digest = pull(config, destination)
                write_json(state_path, {'last_success': time.time(), 'last_file': path.name, 'sha256': digest})
                print('Verified encrypted backup saved:', path.name)
                return 0
            except (error.URLError, OSError, ValueError, RuntimeError, zipfile.BadZipFile) as exc:
                if attempt == 2: raise
                print('Backup attempt failed; retrying shortly:', type(exc).__name__, file=sys.stderr)
                time.sleep(2 ** attempt * 5)
    except (error.URLError, OSError, ValueError, RuntimeError, KeyError, json.JSONDecodeError, zipfile.BadZipFile) as exc:
        print('Office backup failed:', type(exc).__name__, str(exc) if not isinstance(exc, error.HTTPError) else f'HTTP {exc.code}', file=sys.stderr)
        return 1


if __name__ == '__main__': sys.exit(main())
