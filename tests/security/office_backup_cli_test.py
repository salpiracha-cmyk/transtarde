"""Run the actual CLI entry point against an isolated private directory."""
import json
import shutil
import subprocess
import tempfile
from pathlib import Path

SOURCE = Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix="tt-backup-cli-") as temp:
    app = Path(temp) / "public_html"
    (app / "scripts").mkdir(parents=True)
    for source in SOURCE.glob("*.php"):
        shutil.copy2(source, app / source.name)
    shutil.copy2(SOURCE / "scripts/office_backup_worker.php", app / "scripts/office_backup_worker.php")
    command = ["php", str(app / "scripts/office_backup_worker.php")]
    missing = subprocess.run(command + ["--run"], capture_output=True, text=True)
    assert missing.returncode == 1 and "Set the managed recovery password" in missing.stderr, missing.stderr
    setup = "require 'auth_store.php';require 'office_backup_auth.php';tt_backup_password_set('isolated-worker-test-password');"
    subprocess.run(["php", "-r", setup], cwd=app, check=True, capture_output=True)
    for flag, expected in [("--run", "OFFICE_WORKER_CHECK_COMPLETE"), ("--enable", "OFFICE_WORKER_ENABLED"), ("--run", "OFFICE_WORKER_CHECK_COMPLETE")]:
        result = subprocess.run(command + [flag], capture_output=True, text=True)
        assert result.returncode == 0 and expected in result.stdout, result.stderr
    control = json.loads((Path(temp) / "transtrade_private/backups/.office-worker.json").read_text())
    assert control["enabled"] is True and control["heartbeatAt"] > 0
print("OFFICE_BACKUP_CLI_BOOTSTRAP_PASSED")
