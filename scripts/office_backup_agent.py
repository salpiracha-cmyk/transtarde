#!/usr/bin/env python3
"""Transtrade Office Agent. Python 3.10+ standard library only.

Runs on the permanent office/server PC. It pulls encrypted system backups and
processes shipment archive jobs created by Transtrade without exposing SMB to
the hosted application or browser clients.
"""
import argparse
import getpass
import hashlib
import json
import os
from pathlib import Path
import shutil
import socket
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


def sha256_file(path):
    digest = hashlib.sha256()
    with open(path, 'rb') as source:
        while True:
            chunk = source.read(1024 * 1024)
            if not chunk: break
            digest.update(chunk)
    return digest.hexdigest()


def initialize(path):
    if path.exists(): raise RuntimeError('Configuration already exists. Rotate the server credential and remove the old configuration before initializing again.')
    print('Generate an Office Agent credential in Super Admin > Backup & Data Export first.')
    token = getpass.getpass('Office Agent credential: ').strip()
    password = getpass.getpass('Recovery ZIP password (12+ characters): ')
    confirm = getpass.getpass('Repeat recovery ZIP password: ')
    if len(token) != 64 or any(c not in '0123456789abcdef' for c in token): raise ValueError('Invalid backup-only credential.')
    if len(password) < 12 or password != confirm: raise ValueError('Recovery password is too short or does not match.')
    backup_folder = input('System backup folder: ').strip()
    shipment_folder = input('Shipment archive folder: ').strip()
    if not backup_folder or not shipment_folder: raise ValueError('Both backup and shipment archive folders are required.')
    backup_destination = Path(backup_folder).expanduser().resolve()
    shipment_destination = Path(shipment_folder).expanduser().resolve()
    backup_destination.mkdir(parents=True, exist_ok=True)
    shipment_destination.mkdir(parents=True, exist_ok=True)
    write_json(path, {
        'backupUrl': 'https://app.transtradeinternational.com/api/office_backup.php',
        'officeAgentUrl': 'https://app.transtradeinternational.com/api/office_agent.php',
        'backupDestination': str(backup_destination),
        'shipmentArchiveDestination': str(shipment_destination),
        'token': token,
        'password': password,
        'retention': {'daily': 7, 'weekly': 4, 'monthly': 12}
    }, sensitive=True)
    print('Configuration saved. Install this script as a Windows startup task/service and run it frequently for retries.')


class NoRedirect(request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise RuntimeError('Backup endpoint redirected unexpectedly.')


def opener():
    return request.build_opener(request.HTTPSHandler(context=ssl.create_default_context()), NoRedirect())


def require_endpoint(url, path):
    parsed = parse.urlparse(url)
    if parsed.scheme != 'https' or parsed.hostname != 'app.transtradeinternational.com' or parsed.port not in (None, 443) or parsed.path != path or parsed.username or parsed.password or parsed.fragment:
        raise ValueError('Endpoint must be the production HTTPS ' + path + ' URL.')
    return parsed


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
    url = config.get('backupUrl') or config.get('url')
    require_endpoint(url, '/api/office_backup.php')
    destination.mkdir(parents=True, exist_ok=True)
    payload = json.dumps({'password': config['password']}).encode('utf-8')
    req = request.Request(url, data=payload, headers={'Content-Type': 'application/json', 'X-TT-Backup-Token': config['token']}, method='POST')
    temp = None
    try:
        with opener().open(req, timeout=300) as response:
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
        stamp = datetime.now(timezone.utc)
        retention = config.get('retention') or {}
        daily_dir, weekly_dir, monthly_dir = destination / 'Daily', destination / 'Weekly', destination / 'Monthly'
        for folder in (daily_dir, weekly_dir, monthly_dir): folder.mkdir(parents=True, exist_ok=True)
        daily = daily_dir / ('TRANSTRADE_FULL_' + stamp.strftime('%Y-%m-%d_%H%M') + '_' + expected[:12] + '.zip')
        os.replace(temp, daily); temp = None
        if stamp.weekday() == 6 or not list(weekly_dir.glob('TRANSTRADE_FULL_' + stamp.strftime('%G-W%V') + '_*.zip')):
            weekly = weekly_dir / ('TRANSTRADE_FULL_' + stamp.strftime('%G-W%V_%H%M') + '_' + expected[:12] + '.zip')
            copy_verified(daily, weekly, expected)
        if not list(monthly_dir.glob('TRANSTRADE_FULL_' + stamp.strftime('%Y-%m') + '_*.zip')):
            monthly = monthly_dir / ('TRANSTRADE_FULL_' + stamp.strftime('%Y-%m_%H%M') + '_' + expected[:12] + '.zip')
            copy_verified(daily, monthly, expected)
        rotate(daily_dir, 'TRANSTRADE_FULL_*.zip', int(retention.get('daily', 7)))
        rotate(weekly_dir, 'TRANSTRADE_FULL_*.zip', int(retention.get('weekly', 4)))
        rotate(monthly_dir, 'TRANSTRADE_FULL_*.zip', int(retention.get('monthly', 12)))
        return daily, expected
    finally:
        if temp and os.path.exists(temp): os.unlink(temp)


def copy_verified(source, target, expected):
    fd, temp = tempfile.mkstemp(prefix='.' + target.name + '-', dir=target.parent)
    try:
        with open(source, 'rb') as src, os.fdopen(fd, 'wb') as copied:
            shutil.copyfileobj(src, copied, 1024 * 1024)
            copied.flush(); os.fsync(copied.fileno())
        if sha256_file(temp) != expected: raise ValueError('Backup copy checksum mismatch.')
        os.replace(temp, target)
    finally:
        if os.path.exists(temp): os.unlink(temp)


def rotate(folder, pattern, count):
    count = max(0, count)
    files = sorted(folder.glob(pattern), reverse=True)
    for old in files[count:]: old.unlink()


def safe_component(value):
    name = ''.join('-' if c in '<>:"/\\|?*' or ord(c) < 32 else c for c in str(value or '')).strip().rstrip('. ')[:120]
    if not name or set(name) <= {'.'}: raise ValueError('Archive path contains an invalid name.')
    if name.split('.')[0].lower() in {'con','prn','aux','nul','com1','com2','com3','com4','com5','com6','com7','com8','com9','lpt1','lpt2','lpt3','lpt4','lpt5','lpt6','lpt7','lpt8','lpt9'}:
        name = '_' + name
    return name


def host_name():
    return os.environ.get('COMPUTERNAME') or socket.gethostname()


def office_agent_request(config, action, payload=None, query=None, timeout=120):
    url = config.get('officeAgentUrl', 'https://app.transtradeinternational.com/api/office_agent.php')
    require_endpoint(url, '/api/office_agent.php')
    params = {'action': action}
    if query: params.update(query)
    full = url + '?' + parse.urlencode(params)
    data = None if payload is None else json.dumps(payload).encode('utf-8')
    headers = {'X-TT-Backup-Token': config['token'], 'Accept': 'application/json'}
    if data is not None: headers['Content-Type'] = 'application/json'
    req = request.Request(full, data=data, headers=headers, method='POST' if data is not None else 'GET')
    with opener().open(req, timeout=timeout) as response:
        raw = response.read()
        ctype = response.headers.get_content_type()
        if ctype == 'application/json':
            result = json.loads(raw.decode('utf-8'))
            if not result.get('ok'): raise RuntimeError(result.get('error') or 'Office Agent API failed.')
            return result
        return raw, response.headers


def download_job_file(config, job_id, row, temp_dir):
    raw, headers = office_agent_request(config, 'file', query={'id': job_id, 'file': row['storedName']}, timeout=300)
    expected = (headers.get('X-File-SHA256') or row.get('sha256') or '').lower()
    if len(expected) != 64: raise ValueError('Shipment archive file checksum is missing.')
    actual = hashlib.sha256(raw).hexdigest()
    if actual != expected: raise ValueError('Shipment archive file checksum mismatch.')
    target = temp_dir / row['storedName']
    target.write_bytes(raw)
    return target, actual


def process_shipment_job(config, job):
    root = Path(config['shipmentArchiveDestination']).expanduser().resolve()
    root.mkdir(parents=True, exist_ok=True)
    parts = [safe_component(x) for x in job.get('folderParts') or []]
    if len(parts) != 3: raise ValueError('Shipment archive folder structure is invalid.')
    final_dir = root
    for part in parts: final_dir = final_dir / part
    temp_dir = root / ('.office-agent-' + job['id'])
    if temp_dir.exists(): shutil.rmtree(temp_dir)
    temp_dir.mkdir(parents=True)
    verified = []
    try:
        files = job.get('files') or []
        if not files: raise ValueError('Shipment archive job has no files.')
        for row in files:
            source, actual = download_job_file(config, job['id'], row, temp_dir)
            folder = safe_component(row.get('folder', '')) if row.get('folder') else ''
            target_dir = final_dir / folder if folder else final_dir
            target_dir.mkdir(parents=True, exist_ok=True)
            target = target_dir / safe_component(row['name'])
            tmp = target.with_name('.' + target.name + '.tmp')
            shutil.copyfile(source, tmp)
            if sha256_file(tmp) != actual: raise ValueError('Written shipment archive checksum mismatch.')
            os.replace(tmp, target)
            if sha256_file(target) != actual: raise ValueError('Saved shipment archive checksum mismatch.')
            verified.append({'name': row['name'], 'folder': folder, 'sha256': actual, 'size': target.stat().st_size})
        office_agent_request(config, 'ack', {'id': job['id'], 'status': 'COMPLETE', 'host': host_name(), 'files': verified})
        return final_dir, len(verified)
    except Exception as exc:
        office_agent_request(config, 'ack', {'id': job.get('id'), 'status': 'ERROR', 'host': host_name(), 'error': str(exc)})
        raise
    finally:
        if temp_dir.exists(): shutil.rmtree(temp_dir, ignore_errors=True)


def process_shipments(config):
    if not config.get('shipmentArchiveDestination'): return 0
    result = office_agent_request(config, 'poll')
    processed = 0
    for job in result.get('jobs', []):
        if job.get('type') != 'shipment_archive': continue
        path, count = process_shipment_job(config, job)
        print('Verified shipment archive saved:', path, '(' + str(count) + ' files)')
        processed += 1
    return processed


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', type=Path, default=config_path())
    parser.add_argument('--init', action='store_true', help='Save backup-only credentials and destination securely')
    parser.add_argument('--force', action='store_true', help='Pull now even if a weekly backup succeeded recently')
    parser.add_argument('--shipments-only', action='store_true', help='Only process pending shipment archive jobs')
    parser.add_argument('--backup-only', action='store_true', help='Only pull the encrypted system backup')
    args = parser.parse_args()
    try:
        if args.init: initialize(args.config); return 0
        config = json.loads(args.config.read_text(encoding='utf-8'))
        if not args.backup_only:
            process_shipments(config)
            if args.shipments_only: return 0
        destination = Path(config.get('backupDestination') or config.get('destination')).expanduser().resolve()
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
