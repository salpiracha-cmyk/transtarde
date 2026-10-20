"""Guard the high-impact rehearsal boundaries without reading real records."""
from pathlib import Path
root=Path(__file__).resolve().parents[2]
host=(root/'.github/scripts/migration_rehearsal_host.sh').read_text()
browser=(root/'.github/scripts/migration_rehearsal_browser.cjs').read_text()
workflow=(root/'.github/workflows/migration-rehearsal.yml').read_text()
assert '127.0.0.1:$port' in host and '0.0.0.0' not in host
assert 'HTTP_X_TT_REHEARSAL' in host and 'hash_equals' in host
assert 'env -u TT_DB_HOST -u TT_DB_NAME -u TT_DB_USER -u TT_DB_PASS' in host
assert 'trap cleanup EXIT' in host and '+ 1200' in host
assert 'tt_backup_commit_staged($staged)' in host
assert "TT_DATA_DIR.'/'.$name" in host and "require $work.'/app/auth_store.php'" in host
assert 'sourceTargetControlsAgree:true' in browser and 'canonicalStoresRestored:3' in browser
assert "url.origin!==base" in browser and "return route.abort()" in browser
assert 'environment: live-qa' in workflow
assert "github.event_name == 'workflow_run'" in workflow
assert 'upload-artifact' not in workflow and 'StrictHostKeyChecking=yes' in workflow
assert 'office_backup' not in host+browser+workflow
print('PASS private recovery boundaries: main-only credentials, gated loopback, target-only rollback, TTL cleanup, exact controls and no raw-data artifact')
