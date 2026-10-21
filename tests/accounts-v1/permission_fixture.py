"""Load actual production action helpers into deliberately isolated HTTP fixtures."""
from pathlib import Path
import re


def append_permission_helpers(root):
    source = (Path(__file__).resolve().parents[2] / 'auth_store.php').read_text()
    target = Path(root) / 'auth_store.php'
    fixture = target.read_text()
    names = ['tt_business_modules', 'tt_user_can_module_action', 'tt_user_can_access_masters', 'tt_user_can_master',
             'tt_api_write_grants', 'tt_api_write_actions', 'tt_api_icon_write_allowed',
             'tt_api_is_upsert', 'tt_api_upsert_allowed']
    for name in names:
        if re.search(r'function\s+' + name + r'\s*\(', fixture):
            continue
        start = source.index('function ' + name + '(')
        first_end = source.index('\n', start)
        end = first_end if source[start:first_end].rstrip().endswith('}') else source.index('\n}', first_end) + 2
        fixture += '\n' + source[start:end] + '\n'
    target.write_text(fixture)
