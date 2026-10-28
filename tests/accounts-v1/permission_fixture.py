"""Load actual production action helpers into deliberately isolated HTTP fixtures."""
from pathlib import Path
import re, shutil


def append_permission_helpers(root):
    source = (Path(__file__).resolve().parents[2] / 'auth_store.php').read_text()
    target = Path(root) / 'auth_store.php'
    fixture = target.read_text()
    names = ['tt_business_modules', 'tt_user_can_module_action', 'tt_user_can_access_masters', 'tt_user_can_master',
             'tt_api_write_grants', 'tt_api_write_actions', 'tt_api_icon_write_allowed',
             'tt_api_is_upsert', 'tt_api_upsert_allowed', 'tt_user_business_entity', 'tt_user_any_icon', 'tt_mask_bank_identifier']
    for name in names:
        if re.search(r'function\s+' + name + r'\s*\(', fixture):
            continue
        start = source.index('function ' + name + '(')
        first_end = source.index('\n', start)
        end = first_end if source[start:first_end].rstrip().endswith('}') else source.index('\n}', first_end) + 2
        fixture += '\n' + source[start:end] + '\n'
    target.write_text(fixture)

    # Mirror shared dependencies used by the copied production endpoints.
    repo = Path(__file__).resolve().parents[2]
    if any((Path(root)/'api'/name).exists() for name in ['export_receipts.php','bag_purchases.php','accounts_receipt_file.php','bag_bill_file.php']):
        shutil.copy(repo/'pending_uploads.php', Path(root)/'pending_uploads.php')
        if (Path(root)/'api'/'export_receipts.php').exists():
            for name in ['accounts_linked_edit_core.php','intercompany_advance_core.php','accounts_posting_preview.php']:
                shutil.copy(repo/'api'/name,Path(root)/'api'/name)
    if any((Path(root)/'api'/name).exists() for name in ['local_sales_control.php','local_sales_payments.php']):
        for name in ['inventory_reconciliation.php','product_stage.php']:
            shutil.copy(repo/name,Path(root)/name)
