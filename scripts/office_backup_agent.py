#!/usr/bin/env python3
"""Transtrade Office Agent. Python 3.10+ standard library only.

Runs on the permanent office/server PC. It pulls encrypted system backups and
processes shipment archive jobs created by Transtrade without exposing SMB to
the hosted application or browser clients.
"""
import argparse
import base64
from contextlib import contextmanager, nullcontext, redirect_stderr, redirect_stdout
import errno
import getpass
from email.message import Message
import hashlib
import http.client
import json
import os
import re
from pathlib import Path
import shutil
import socket
import ssl
import sys
import tempfile
import time
from datetime import datetime, timedelta, timezone
from datetime import datetime as ArchiveDateTime
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


def replace_json_file(temp, path):
    # Windows scanners/readers can briefly deny replacement of an open file.
    # Retry only this local commit; never repeat a verified backup download.
    for attempt in range(5):
        try:
            os.replace(temp, path)
            return
        except OSError as exc:
            if getattr(exc, 'winerror', None) not in (5, 32, 33) or attempt == 4:
                raise
            time.sleep(0.1 * (2 ** attempt))


def write_json(path, value, sensitive=False):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temp = tempfile.mkstemp(prefix='.office-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as out:
            json.dump(value, out, indent=2)
            out.flush(); os.fsync(out.fileno())
        if sensitive: protect(Path(temp))
        replace_json_file(temp, path)
        if sensitive: protect(path)
    finally:
        if os.path.exists(temp): os.unlink(temp)


@contextmanager
def backup_run_lock(config):
    """One backup/retention writer per configuration, including manual runs."""
    config.parent.mkdir(parents=True, exist_ok=True)
    path = config.with_name('office-backup-run.lock')
    if path.is_symlink(): raise OSError('Linked backup run lock refused.')
    with open(path, 'a+b') as handle:
        if os.fstat(handle.fileno()).st_size == 0:
            handle.write(b'0'); handle.flush()
        handle.seek(0)
        try:
            if os.name == 'nt':
                import msvcrt
                msvcrt.locking(handle.fileno(), msvcrt.LK_NBLCK, 1)
            else:
                import fcntl
                fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError as exc:
            if exc.errno not in (errno.EACCES, errno.EAGAIN) and getattr(exc, 'winerror', None) not in (32, 33):
                raise
            yield False
            return
        try:
            yield True
        finally:
            handle.seek(0)
            if os.name == 'nt':
                msvcrt.locking(handle.fileno(), msvcrt.LK_UNLCK, 1)
            else:
                fcntl.flock(handle, fcntl.LOCK_UN)


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
    if os.name == 'nt':
        import subprocess
        print('Copy the Office Agent credential from Super Admin, then press Enter here.')
        input('Credential copied to Windows clipboard? Press Enter: ')
        clip = subprocess.run(['powershell.exe', '-NoProfile', '-Command', 'Get-Clipboard -Raw'], capture_output=True, text=True)
        token = clip.stdout.strip()
        subprocess.run(['powershell.exe', '-NoProfile', '-Command', 'Set-Clipboard -Value $null'], capture_output=True)
    else:
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


def connect_setup(config_file, setup_file):
    """Redeem a one-use owner-authorized setup without showing the PC key."""
    config = json.loads(config_file.read_text(encoding='utf-8'))
    require_endpoint(config.get('backupUrl') or config.get('url'), '/api/office_backup.php')
    setup = json.loads(setup_file.read_text(encoding='utf-8-sig'))
    ticket = setup.get('ticket', '')
    if not isinstance(ticket, str) or not re.fullmatch(r'[a-f0-9]{64}', ticket):
        raise ValueError('Download and extract a fresh PC connection setup.')
    # Confirm that the protected configuration can be committed before consuming
    # the one-use authorization. The existing local writer lock is held here.
    write_json(config_file, config, sensitive=True)
    req = request.Request('https://app.transtradeinternational.com/api/office_backup_connect.php',
        data=json.dumps({'ticket': ticket}).encode(), headers={'Content-Type': 'application/json'}, method='POST')
    try:
        with opener().open(req, timeout=60) as response:
            raw = response.read(4097)
            if len(raw) > 4096: raise ValueError('PC setup returned an invalid response.')
            data = json.loads(raw)
    except error.HTTPError:
        raise ValueError('PC setup expired, was replaced, or was refused. Download a fresh setup and try again.') from None
    token = data.get('token', '')
    if data.get('ok') is not True or not isinstance(token, str) or not re.fullmatch(r'[a-f0-9]{64}', token):
        raise ValueError('PC setup returned an invalid connection authorization.')
    config['token'] = token
    write_json(config_file, config, sensitive=True)
    print('PC connected. Existing folders, password and Windows schedule were preserved.')


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
        return manifest


def scheduled_backup_time(timestamp):
    """Most recent 18:00 Pakistan-time slot, including a missed previous day."""
    now = datetime.fromtimestamp(timestamp, timezone(timedelta(hours=5)))
    due = now.replace(hour=18, minute=0, second=0, microsecond=0)
    return due if now >= due else due - timedelta(days=1)


def backup_job_request(config, action, values=None):
    url = config.get('backupUrl') or config.get('url')
    require_endpoint(url, '/api/office_backup.php')
    payload = {'password': config.get('password', '')}
    if values: payload.update(values)
    req = request.Request(url + '?action=' + action, data=json.dumps(payload).encode('utf-8'), headers={
        'Content-Type': 'application/json', 'Accept-Encoding': 'identity',
        'X-TT-Backup-Token': config['token']}, method='POST')
    return opener().open(req, timeout=60)


def backup_job_json(config, action, values=None):
    with backup_job_request(config, action, values) as response:
        if response.status not in (200, 202) or response.headers.get_content_type() != 'application/json':
            raise ValueError('Unexpected backup preparation response.')
        raw = response.read(65537)
        if len(raw) > 65536: raise ValueError('Backup preparation response is too large.')
        result = json.loads(raw)
        if not result.get('ok'): raise RuntimeError('Hosted backup preparation failed.')
        return result


class PreparedBackupResponse:
    status = 200
    def __init__(self, config, metadata):
        self.config, self.metadata = config, metadata
        self.offset, self.buffer = 0, b''
        self.length = metadata.get('length')
        digest = metadata.get('sha256', '')
        if not isinstance(self.length, int) or not 0 < self.length <= 0xffffffff:
            raise ValueError('Invalid prepared backup size.')
        if len(digest) != 64 or any(c not in '0123456789abcdef' for c in digest):
            raise ValueError('Invalid prepared backup checksum.')
        self.headers = Message()
        self.headers['Content-Type'] = 'application/zip'
        self.headers['Content-Length'] = str(self.length)
        self.headers['Content-Encoding'] = 'identity'
        self.headers['X-Backup-SHA256'] = digest
    def __enter__(self): return self
    def __exit__(self, *_): self.buffer = b''
    def read(self, amount):
        if not self.buffer and self.offset < self.length:
            length = min(4 * 1024 * 1024, self.length - self.offset)
            for attempt in range(3):
                try:
                    with backup_job_request(self.config, 'chunk', {'job': self.metadata['job'], 'offset': self.offset, 'length': length}) as response:
                        if response.status != 200 or response.headers.get_content_type() != 'application/octet-stream':
                            raise ValueError('Unexpected backup chunk response.')
                        if response.headers.get('Content-Encoding', 'identity').lower() not in ('', 'identity'):
                            raise ValueError('Backup chunk was transformed by HTTP compression.')
                        if int(response.headers.get('Content-Length', '0')) != length:
                            raise ValueError('Backup chunk has the wrong length.')
                        data = response.read(length + 1)
                        expected = response.headers.get('X-Backup-Chunk-SHA256', '')
                        if len(data) != length or hashlib.sha256(data).hexdigest() != expected:
                            raise ValueError('Backup chunk length or checksum mismatch.')
                    self.buffer = data; self.offset += length
                    break
                except (error.URLError, http.client.IncompleteRead, TimeoutError, ValueError):
                    if attempt == 2: raise
                    print('Retrying the current backup piece; completed backups are preserved.', flush=True)
                    time.sleep(3)
        chunk, self.buffer = self.buffer[:amount], self.buffer[amount:]
        return chunk
    def acknowledge(self):
        try:
            backup_job_json(self.config, 'complete', {'job': self.metadata['job'], 'sha256': self.metadata['sha256']})
        except Exception:
            print('Local backup verified; server transfer cleanup acknowledgement failed.', flush=True)


def open_backup_response(config):
    result = backup_job_json(config, 'prepare')
    deadline = time.monotonic() + 1200
    while result.get('state') == 'preparing':
        job = result.get('job', '')
        if len(job) != 32 or any(c not in '0123456789abcdef' for c in job):
            raise ValueError('Invalid backup preparation job.')
        if time.monotonic() >= deadline: raise TimeoutError('Hosted backup preparation exceeded 20 minutes.')
        print('Server is ' + ('verifying' if result.get('stage') == 'verifying' else 'preparing') + ' the encrypted backup...', flush=True)
        time.sleep(10)
        result = backup_job_json(config, 'status', {'job': job})
    if result.get('state') != 'ready': raise RuntimeError('Server could not prepare a verified recovery archive.')
    return PreparedBackupResponse(config, result)


def pull(config, destination, scheduled_for=None, refresh_tiers=False):
    url = config.get('backupUrl') or config.get('url')
    require_endpoint(url, '/api/office_backup.php')
    destination.mkdir(parents=True, exist_ok=True)
    temp = None
    try:
        print('Requesting encrypted backup from the hosted server...', flush=True)
        with open_backup_response(config) as response:
            if response.status != 200 or response.headers.get_content_type() != 'application/zip': raise ValueError('Unexpected backup response.')
            encoding = response.headers.get('Content-Encoding', 'identity').strip().lower()
            if encoding not in ('', 'identity'): raise ValueError('Backup response was transformed by HTTP compression; an unchanged ZIP transfer is required.')
            expected = response.headers.get('X-Backup-SHA256', '').lower()
            if len(expected) != 64 or any(c not in '0123456789abcdef' for c in expected): raise ValueError('Missing transfer checksum.')
            length = int(response.headers.get('Content-Length', '0'))
            if length <= 0: raise ValueError('Hosted backup response has no valid file length.')
            if shutil.disk_usage(destination).free < length * 2: raise RuntimeError('Not enough free disk space for verified backup.')
            print(f'Downloading {length:,} bytes; verification is required before saving.', flush=True)
            fd, temp = tempfile.mkstemp(prefix='.downloading-', dir=destination)
            digest = hashlib.sha256(); received = 0; last_progress = time.monotonic()
            with os.fdopen(fd, 'wb') as out:
                while True:
                    try:
                        chunk = response.read(1024 * 1024)
                    except http.client.IncompleteRead as exc:
                        raise ValueError(f'Backup transfer incomplete: received {received + len(exc.partial):,} of {length:,} bytes. Existing verified backups were preserved.') from exc
                    if not chunk: break
                    out.write(chunk); digest.update(chunk); received += len(chunk)
                    if time.monotonic() - last_progress >= 30:
                        print(f'Received {received:,} of {length:,} bytes.', flush=True)
                        last_progress = time.monotonic()
                out.flush(); os.fsync(out.fileno())
            if received != length: raise ValueError(f'Backup transfer incomplete: received {received:,} of {length:,} bytes. Existing verified backups were preserved.')
            if digest.hexdigest() != expected: raise ValueError(f'Backup transfer checksum mismatch after receiving all {received:,} bytes. Existing verified backups were preserved.')
        validate_zip(temp)
        stamp = datetime.now(timezone(timedelta(hours=5))) if scheduled_for is not None else datetime.now(timezone.utc)
        bucket_stamp = scheduled_for if scheduled_for is not None else stamp
        retention = config.get('retention') or {}
        daily_dir, weekly_dir, monthly_dir = destination / 'Daily', destination / 'Weekly', destination / 'Monthly'
        for folder in (daily_dir, weekly_dir, monthly_dir): folder.mkdir(parents=True, exist_ok=True)
        daily = daily_dir / ('TRANSTRADE_FULL_' + stamp.strftime('%Y-%m-%d_%H%M') + '_' + expected[:12] + '.zip')
        os.replace(temp, daily); temp = None
        promotions = []
        if refresh_tiers or not list(weekly_dir.glob('TRANSTRADE_FULL_' + bucket_stamp.strftime('%G-W%V') + '_*.zip')):
            weekly = weekly_dir / ('TRANSTRADE_FULL_' + bucket_stamp.strftime('%G-W%V') + stamp.strftime('_%H%M') + '_' + expected[:12] + '.zip')
            copy_verified(daily, weekly, expected)
            promotions.append(('weekly', weekly, expected))
        # Scheduled monthly points wait for a weekday, with catch-up on the first
        # successful working-day run. No office holiday calendar is available.
        if refresh_tiers or (scheduled_for is None or bucket_stamp.weekday() < 5) and not list(monthly_dir.glob('TRANSTRADE_FULL_' + bucket_stamp.strftime('%Y-%m') + '_*.zip')):
            monthly = monthly_dir / ('TRANSTRADE_FULL_' + bucket_stamp.strftime('%Y-%m') + stamp.strftime('_%H%M') + '_' + expected[:12] + '.zip')
            copy_verified(daily, monthly, expected)
            promotions.append(('monthly', monthly, expected))
        apply_tier_retention(destination, promotions)
        rotate(daily_dir, 'TRANSTRADE_FULL_*.zip', int(retention.get('daily', 7)))
        rotate(weekly_dir, 'TRANSTRADE_FULL_*.zip', int(retention.get('weekly', 4)))
        rotate(monthly_dir, 'TRANSTRADE_FULL_*.zip', int(retention.get('monthly', 12)))
        rotate(destination, 'weekly-*.zip', int(retention.get('weekly', 4)))
        rotate(destination, 'monthly-*.zip', int(retention.get('monthly', 3)))
        if hasattr(response, 'acknowledge'): response.acknowledge()
        return daily, expected
    finally:
        if temp and os.path.exists(temp): os.unlink(temp)


_MANAGED_BACKUP = re.compile(r'^TRANSTRADE_FULL_\d{4}-(?:W\d{2}|\d{2}(?:-\d{2})?)_\d{4}_([a-f0-9]{12})\.zip$')


def backup_identity(path):
    if path.is_symlink() or not path.is_file(): raise ValueError('Backup is not a regular file.')
    stat = path.stat()
    if stat.st_nlink != 1: raise ValueError('Backup has another hard link.')
    return (stat.st_dev, stat.st_ino, stat.st_size, stat.st_mtime_ns, stat.st_ctime_ns)


def retention_receipt(path, expected=None):
    match = _MANAGED_BACKUP.fullmatch(path.name)
    if not match: raise ValueError('Unrecognised backup filename.')
    before = backup_identity(path)
    manifest = validate_zip(path)
    created = ArchiveDateTime.fromisoformat(str(manifest.get('createdAt', '')).replace('Z', '+00:00'))
    if created.tzinfo is None or created.timestamp() > time.time() + 300:
        raise ValueError('Invalid recovery creation date.')
    actual = sha256_file(path)
    if actual[:12] != match.group(1) or (expected is not None and actual != expected):
        raise ValueError('Retained recovery checksum mismatch.')
    # Full server SHA256 receipts prove known survivors unchanged. Unknown old
    # candidates also need every ZIP member CRC to pass before disposal.
    if expected is None:
        with zipfile.ZipFile(path) as archive:
            if archive.testzip() is not None: raise ValueError('Older backup is unreadable.')
    if backup_identity(path) != before: raise ValueError('Backup changed during verification.')
    return {'name': path.name, 'sha256': actual, 'createdAt': created.astimezone(timezone.utc).isoformat(), 'identity': before}


def apply_tier_retention(destination, promotions=(), verified_state=None):
    for folder in ('Daily', 'Weekly', 'Monthly'):
        path = destination / folder
        if path.is_symlink() or (path.exists() and (not path.is_dir() or path.resolve() != destination.resolve() / folder)):
            raise RuntimeError('Linked or unexpected backup tier directory; cleanup refused.')
    catalog_path = destination / 'tier-retention.json'
    catalog = {}
    if catalog_path.is_file() and not catalog_path.is_symlink() and catalog_path.stat().st_size < 8192:
        try: catalog = json.loads(catalog_path.read_text(encoding='utf-8'))
        except (OSError, ValueError): pass
    if not isinstance(catalog, dict): catalog = {}
    catalog = {k:v for k,v in catalog.items() if k in ('weekly', 'monthly') and isinstance(v, dict)}
    additions = list(promotions)
    # Bootstrap an already-created weekly/monthly copy only from the complete
    # SHA256 saved by the last successful download, never from a name alone.
    known = (verified_state or {}).get('sha256', '')
    if isinstance(known, str) and re.fullmatch('[a-f0-9]{64}', known):
        for tier, folder in [('weekly','Weekly'), ('monthly','Monthly')]:
            for path in (destination / folder).glob('TRANSTRADE_FULL_*_' + known[:12] + '.zip'):
                additions.append((tier, path, known))
    for tier, path, expected in additions:
        try:
            receipt = retention_receipt(path, expected)
            previous = catalog.get(tier, {})
            if not previous or receipt['createdAt'] >= previous.get('createdAt', ''):
                catalog[tier] = {k:v for k,v in receipt.items() if k != 'identity'}
        except (OSError, ValueError, zipfile.BadZipFile):
            continue
    removed = 0; freed = 0; held = 0
    for tier, folders in [('monthly', ('Weekly','Daily')), ('weekly', ('Daily',))]:
        entry = catalog.get(tier)
        if not entry or not re.fullmatch('[a-f0-9]{64}', str(entry.get('sha256', ''))): continue
        survivor = destination / ('Monthly' if tier == 'monthly' else 'Weekly') / Path(str(entry.get('name', ''))).name
        try: proof = retention_receipt(survivor, entry['sha256'])
        except (OSError, ValueError, zipfile.BadZipFile): continue
        cutoff = ArchiveDateTime.fromisoformat(proof['createdAt'])
        for folder in folders:
            for old in (destination / folder).glob('*.zip'):
                if old == survivor: continue
                try:
                    candidate = retention_receipt(old)
                    if ArchiveDateTime.fromisoformat(candidate['createdAt']) >= cutoff: continue
                    if backup_identity(survivor) != proof['identity'] or sha256_file(survivor) != proof['sha256']:
                        raise RuntimeError('Replacement backup changed. Retention cleanup stopped.')
                    if backup_identity(old) != candidate['identity']:
                        held += 1; continue
                    size = old.stat().st_size
                    old.unlink(); removed += 1; freed += size
                except (OSError, ValueError, zipfile.BadZipFile): held += 1
    write_json(catalog_path, catalog)
    if removed or held:
        print(f'Backup retention: removed {removed} older daily/weekly copies ({freed:,} bytes); held {held} unrecognised, changed or unreadable copies.', flush=True)
    return {'removed': removed, 'freedBytes': freed, 'held': held}


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
    headers = {'X-TT-Backup-Token': config['token'], 'X-TT-Office-Recovery': base64.b64encode(config['password'].encode('utf-8')).decode('ascii'), 'Accept': 'application/json'}
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


def cleanup_verified_folders(final_dir, folders):
    for folder in folders or []:
        name = safe_component(folder)
        if name not in {'BAGS', 'Custom documents'}: continue
        target = final_dir / name
        if target.is_dir(): shutil.rmtree(target)


def clean_legacy_bank_outputs(final_dir, job, verified):
    """Prune known generated outputs only after the new bank file verifies locally.

    Uploaded originals are not deleted. In old bank-only folders they are moved,
    byte-verified, into BANK DOCUMENTS. Collisions retain the source for review.
    """
    if job.get('layoutVersion') != 2:
        return
    bank_dir = final_dir / 'BANK DOCUMENTS'
    packages = [row for row in verified if row['folder'] == 'BANK DOCUMENTS'
                and row['name'].startswith('Bank Shipment Documents ') and row['name'].endswith('.pdf')]
    if not packages or bank_dir.is_symlink():
        return
    for row in packages:
        target = bank_dir / row['name']
        if not target.is_file() or target.is_symlink() or sha256_file(target) != row['sha256']:
            raise ValueError('New bank package did not verify; legacy outputs retained.')
    generated = ('Bank Shipment Documents ', 'Pakistan → TG — ')
    for target in final_dir.iterdir():
        if target.is_symlink() or not target.is_file():
            continue
        if (target.name.startswith('Bank Shipment Documents ') and target.suffix.lower() == '.pdf') or target.name == 'DOCUMENTS FOR BANK.pdf':
            target.unlink()
    for folder in ('TG docs', 'SEPARATE BANK ATTACHMENTS'):
        source = final_dir / folder
        if source.is_symlink() or not source.is_dir():
            continue
        for target in source.iterdir():
            if target.is_symlink() or not target.is_file():
                continue
            # Generated copies are represented in the new consolidated bank PDF.
            if target.stem in {'Pakistan → TG — Bank Covering Letter', 'Pakistan → TG — Commercial Invoice', 'Pakistan → TG — Packing List', 'Pakistan → TG — Sales Contract - Proforma', 'Pakistan → TG — Sales Contract Proforma', 'Pakistan → TG — Relationship Letter'} and target.suffix.lower() in ('.pdf', '.docx'):
                target.unlink()
                continue
            if target.name == 'READ ME - send phytosanitary separately.pdf':
                target.unlink()
                continue
            dest = bank_dir / target.name
            if dest.exists():
                if dest.is_file() and not dest.is_symlink() and sha256_file(dest) == sha256_file(target):
                    target.unlink()
                continue
            staging = dest.with_name('.' + dest.name + '.migration.tmp')
            shutil.copyfile(target, staging)
            if sha256_file(staging) != sha256_file(target):
                staging.unlink(missing_ok=True)
                raise ValueError('Original bank attachment migration did not verify.')
            os.replace(staging, dest)
            if sha256_file(dest) != sha256_file(target):
                raise ValueError('Migrated bank original did not verify; original retained.')
            target.unlink()
        if not any(source.iterdir()):
            source.rmdir()


def process_shipment_job(config, job):
    root = Path(config['shipmentArchiveDestination']).expanduser().resolve()
    root.mkdir(parents=True, exist_ok=True)
    parts = [safe_component(x) for x in job.get('folderParts') or []]
    if len(parts) != 3: raise ValueError('Shipment archive folder structure is invalid.')
    final_dir = root
    for part in parts: final_dir = final_dir / part
    if any(path.is_symlink() for path in [root, final_dir, *final_dir.parents] if path != path.parent):
        raise ValueError('Linked office destination refused.')
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
            if target_dir.is_symlink(): raise ValueError('Linked archive subfolder refused.')
            target_dir.mkdir(parents=True, exist_ok=True)
            target = target_dir / safe_component(row['name'])
            tmp = target.with_name('.' + target.name + '.tmp')
            shutil.copyfile(source, tmp)
            if sha256_file(tmp) != actual: raise ValueError('Written shipment archive checksum mismatch.')
            os.replace(tmp, target)
            if sha256_file(target) != actual: raise ValueError('Saved shipment archive checksum mismatch.')
            verified.append({'name': row['name'], 'folder': folder, 'sha256': actual, 'size': target.stat().st_size})
        clean_legacy_bank_outputs(final_dir, job, verified)
        cleanup_verified_folders(final_dir, job.get('cleanupFolders') if job.get('finalize') else [])
        office_agent_request(config, 'ack', {'id': job['id'], 'status': 'COMPLETE', 'host': host_name(), 'files': verified, 'layoutVersion': 2})
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
    parser.add_argument('--connect-setup', type=Path, help='Connect with the one-use website setup; no password or key prompt')
    parser.add_argument('--connect', action='store_true', help='Update only the saved PC connection key; preserve passwords, folders and schedules')
    parser.add_argument('--init', action='store_true', help='Save backup-only credentials and destination securely')
    parser.add_argument('--retention-only', action='store_true', help='Apply verified weekly/monthly replacement cleanup without downloading')
    parser.add_argument('--refresh-tiers', action='store_true', help='Make one fresh verified backup and fresh current weekly/monthly copies, such as after a password change')
    parser.add_argument('--force', action='store_true', help='Pull now even if a daily backup succeeded recently')
    parser.add_argument('--shipments-only', action='store_true', help='Only process pending shipment archive jobs')
    parser.add_argument('--backup-only', action='store_true', help='Only pull the encrypted system backup')
    parser.add_argument('--scheduled', action='store_true', help='Once per 18:00 Pakistan-time slot, catching up missed runs')
    parser.add_argument('--log-file', type=Path, help='Write the latest scheduled run output to this file')
    args = parser.parse_args()
    if args.refresh_tiers: args.force = True
    try:
        # Lock before opening the shared log so a skipped duplicate cannot
        # truncate or interleave the active run's evidence. Shipment-only
        # delivery retains its independent task and does not write backup state.
        lock = nullcontext(True) if args.init or args.shipments_only else backup_run_lock(args.config)
        with lock as acquired:
            if not acquired:
                print('Another backup run is active; this duplicate run was skipped.')
                return 1 if args.connect_setup or args.refresh_tiers else 0
            if args.log_file is None:
                return run(args)
            with args.log_file.open('w',encoding='utf-8',buffering=1) as log, redirect_stdout(log), redirect_stderr(log):
                return run(args)
    except OSError as exc:
        print('Office backup local lock or log could not be opened:', str(exc), file=sys.stderr)
        return 1


def run(args):
    try:
        if args.init: initialize(args.config); return 0
        if args.connect_setup: connect_setup(args.config, args.connect_setup); return 0
        config = json.loads(args.config.read_text(encoding='utf-8'))
        if args.connect:
            token = getpass.getpass('Paste the new PC connection key (hidden): ').strip()
            if not re.fullmatch(r'[a-f0-9]{64}', token): raise ValueError('The connection key must contain exactly 64 lowercase hexadecimal characters.')
            config['token'] = token
            write_json(args.config, config, sensitive=True)
            print('PC connection key updated. Existing password, folders and task settings were preserved.')
            return 0

        if args.retention_only:
            destination = Path(config.get('backupDestination') or config.get('destination')).expanduser().resolve()
            state_path = args.config.with_name('office-backup-state.json')
            state = json.loads(state_path.read_text(encoding='utf-8')) if state_path.exists() else {}
            result = apply_tier_retention(destination, verified_state=state)
            print('Retention policy installed. Removed', result['removed'], 'older copies; verified replacements preserved.')
            return 0
        if not args.backup_only:
            process_shipments(config)
            if args.shipments_only: return 0
        destination = Path(config.get('backupDestination') or config.get('destination')).expanduser().resolve()
        state_path = args.config.with_name('office-backup-state.json')
        state = json.loads(state_path.read_text(encoding='utf-8')) if state_path.exists() else {}
        now = time.time()
        last_success = float(state.get('last_success', 0))
        same_day = datetime.fromtimestamp(now, timezone.utc).date() == datetime.fromtimestamp(last_success, timezone.utc).date()
        last_file = destination / 'Daily' / Path(str(state.get('last_file', ''))).name
        scheduled_for = scheduled_backup_time(now) if args.scheduled else None
        if scheduled_for is not None:
            recorded_slot = state.get('scheduled_for')
            current = recorded_slot == scheduled_for.isoformat() if recorded_slot else scheduled_for.timestamp() <= last_success <= now
        else:
            current = last_success <= now and same_day
        if not args.force and current and last_file.is_file():
            print('Daily backup is current.'); return 0
        # Prove this account can atomically update its existing state before
        # requesting a large archive. Preserve the previous successful values.
        try:
            write_json(state_path, state)
        except OSError as exc:
            raise RuntimeError('Cannot update the local backup success record; no new download was requested. Check for a file lock or task-owner access.') from exc
        for attempt in range(3):
            try:
                options = {}
                if scheduled_for is not None: options['scheduled_for'] = scheduled_for
                if args.refresh_tiers: options['refresh_tiers'] = True
                path, digest = pull(config, destination, **options)
                break
            except (error.URLError, OSError, ValueError, RuntimeError, zipfile.BadZipFile) as exc:
                if attempt == 2: raise
                detail = f'HTTP {exc.code}' if isinstance(exc, error.HTTPError) else str(exc)
                print('Backup attempt failed; retrying shortly:', type(exc).__name__, detail, file=sys.stderr, flush=True)
                time.sleep(2 ** attempt * 5)
        new_state = {'last_success': time.time(), 'last_file': path.name, 'sha256': digest}
        if scheduled_for is not None: new_state['scheduled_for'] = scheduled_for.isoformat()
        print('Verified encrypted backup saved:', path.name, flush=True)
        try:
            write_json(state_path, new_state)
        except OSError as exc:
            raise RuntimeError('The verified ZIP was preserved, but its local success record could not be saved. No repeat download was started; check for a Windows file lock or task-owner access.') from exc
        return 0
    except (error.URLError, OSError, ValueError, RuntimeError, KeyError, json.JSONDecodeError, zipfile.BadZipFile) as exc:
        print('Office backup failed:', type(exc).__name__, str(exc) if not isinstance(exc, error.HTTPError) else f'HTTP {exc.code}', file=sys.stderr)
        return 1


if __name__ == '__main__': sys.exit(main())
