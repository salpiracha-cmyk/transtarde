"""Present fully verified recovery bytes in four business folders and one hosting set."""
import csv
import hashlib
import json
from pathlib import Path
import re
import shutil
import unicodedata

MODULES=('Accounts','Mill','Exports','Directors')
WEBSITE='Complete Website Restore'

def file_hash(path):
    digest=hashlib.sha256()
    with path.open('rb') as source:
        for block in iter(lambda:source.read(1024*1024),b''):digest.update(block)
    return digest.digest()
LABELS={
 'tt30slips':'Arrivals','tt30prod':'Production and Recoveries','tt30bags':'Bags Orders',
 'tt30ship':'Shipment Loadings','tt32stockadj':'Stock Adjustments','tt33bagreceipts':'Bags Receipts',
 'tt36bagissues':'Bags Issues','tt35localsales':'Local Sales','tt35exportersale':'Fellow Exporter Dispatches',
 'tt34ghati':'Stock Shortages and Gains','tt39physicalconfirmations':'Physical Stock Confirmations',
 'tt30queue':'Arrival Queue','tt30prodaudit':'Production Audit History','tt30prodinst':'Production Instructions',
 'tt37arrivalaudit':'Arrival Audit History','tt37usedbags':'Used Bags','tt38labourbills':'Labour Bills',
 'tt38kebills':'Electricity Bills','tt38costmaster':'Mill Cost Settings','tt38labourrates':'Labour Rates',
 'tt38reprocessbills':'Reprocessing Bills','tt39rentpayments':'Rent Payments','tt39salaryadvances':'Salary Advances',
 'tt39salarymaster':'Mill Salary Master','tt39rentmaster':'Rent Master','tt37processingexpenses':'Processing Expenses',
 'tt30petty':'Mill Petty Cash','tt33pettyexp':'Mill Petty Expenses','tt35exmill':'Ex Mill Purchases',
 'transtrade_export_v3_operational':'Exports Saved Records', 'Accounts':'Accounts Saved Records',
}

def readable(value):
    value=re.sub(r'-[a-f0-9]{8,10}(?=-all-fields\.csv$|$|[/.])','',value)
    value=LABELS.get(value,value)
    for key,label in LABELS.items():
        if value.startswith(key+'-') or value.startswith(key+'/'):value=label+value[len(key):];break
    value=re.sub(r'[_]+',' ',value);value=re.sub(r'(?<=[a-z])(?=[A-Z])',' ',value)
    return value.strip() or 'Saved Records'


def component(value):
    value=unicodedata.normalize('NFC',str(value));value=re.sub(r'[<>:"/\\|?*\x00-\x1f]','-',value).strip().rstrip('. ')
    if re.match(r'^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)',value,re.I):value='Record - '+value
    return value[:140].rstrip('. ') or 'Unnamed Record'


def organize(stage,manifest,progress=lambda text:None):
    stage=Path(stage);hosting=stage/WEBSITE;hosting.mkdir()
    for module in MODULES:(stage/module).mkdir(exist_ok=True)
    mapping=[]
    def move(source,relative):
        source=Path(source);target=stage.joinpath(*relative.parts);target.parent.mkdir(parents=True,exist_ok=True)
        if target.exists():
            if source.is_file() and target.is_file() and file_hash(source)==file_hash(target):
                mapping.append({'Original archive path':str(source.relative_to(stage)),'Recovered path':str(target.relative_to(stage))});source.unlink();return
            stem,suffix=target.stem,target.suffix;index=2
            while target.exists():target=target.with_name(stem+' - Record '+str(index)+suffix);index+=1
        original=str(source.relative_to(stage));source.rename(target);mapping.append({'Original archive path':original,'Recovered path':str(target.relative_to(stage))})
    # Keep the deployable tree and private canonical stores together, outside the readable exports.
    for original,destination in [('Application_Code','public_html'),('System_Recovery/private','transtrade_private')]:
        source=stage/original
        if source.exists():move(source,Path(WEBSITE)/destination)
    for original in ['System_Recovery','Business_Data','Shared']:
        source=stage/original
        if source.exists():move(source,Path(WEBSITE)/'Reference Exports'/original)
    # Use explicit document references from canonical records; never guess customer identity.
    root={};private=hosting/'transtrade_private';ops=private/'operations.json'
    if ops.exists():
        values=json.loads(ops.read_text(encoding='utf-8')).get('values',{});raw=values.get('transtrade_export_v3_operational','{}');root=json.loads(raw) if isinstance(raw,str) else raw
    customers={str(r.get('id','')):r for r in root.get('customers',[]) if isinstance(r,dict)}
    contracts={str(r.get('ref','')):r for r in root.get('contracts',[]) if isinstance(r,dict)}
    docs=private/'export_documents/index.json';index=json.loads(docs.read_text(encoding='utf-8')) if docs.exists() else {}
    if isinstance(index,list):index={str(i):r for i,r in enumerate(index)}
    for row in index.values():
        if not isinstance(row,dict):continue
        stored=row.get('stored_name','')
        if not stored or Path(stored).name!=stored:continue
        source=private/'export_documents'/stored
        if not source.is_file():continue
        ref=str(row.get('contract_ref') or 'Unassigned Shipment');lot=str(row.get('lot_ref') or 'No Lot');contract=contracts.get(ref,{})
        shipments=[r for r in root.get('shipments',[]) if isinstance(r,dict) and str(r.get('contractRef',''))==ref and lot in (str(r.get('id','')),str(r.get('lotId','')))]
        shipment=shipments[0] if len(shipments)==1 else {};buyer=shipment.get('documentParties',{}).get('buyer',{}).get('name') or shipment.get('buyerSnapshot',{}).get('name') or shipment.get('buyer') or customers.get(str(contract.get('customerId','')),{}).get('name') or 'Customer Not Assigned'
        relative=Path('Exports')/'Customer Shipments'/component(buyer)/component(ref)/component(lot)/component(row.get('category') or 'Documents')/component(row.get('original_name') or stored)
        target=stage/relative;target.parent.mkdir(parents=True,exist_ok=True)
        if target.exists():target=target.with_name(target.stem+' - '+component(str(row.get('id') or stored))[:40]+target.suffix)
        original=target;number=2
        while target.exists():target=original.with_name(original.stem+' - Record '+str(number)+original.suffix);number+=1
        shutil.copyfile(source,target)
    # Friendly names for preserved input tables. Derived report names remain explicit.
    for module in MODULES:
        source=stage/module/'Data'
        if source.exists():
            for p in sorted(source.rglob('*')):
                if not p.is_file():continue
                parts=list(p.relative_to(source).parts);parts=[component(readable(x)) for x in parts]
                parts[-1]=parts[-1].replace('-all-fields.csv',' - All Fields.csv')
                move(p,Path(module)/'Saved Input Records'/Path(*parts))
            shutil.rmtree(source)
        # Old technical ledger filenames remain accessible, but do not crowd the main ledgers.
        ledgers=stage/module/'Ledgers'
        if ledgers.exists():
            for p in list(ledgers.glob('*')):
                if p.is_file():move(p,Path(module)/'Saved Input Records'/'Original Ledger Exports'/component(readable(p.name)))
        for old in ['Shipment_Folders','Shipment_Documents']:
            source=stage/module/old
            if source.exists():move(source,Path(WEBSITE)/'Reference Exports'/module/old)
        if (stage/module/'START HERE.txt').exists():move(stage/module/'START HERE.txt',Path(WEBSITE)/'Reference Exports'/module/'Original START HERE.txt')
        if not (stage/module/'Report Notes.csv').exists():
            (stage/module/'Report Notes.csv').write_text('Module,Issue\n'+module+',Older backup: newer calculated reports are unavailable. Original saved input records are preserved.\n',encoding='utf-8-sig')
        (stage/module/'START HERE.txt').write_text(module+'\n\nReports and Registers contain human-readable snapshot reports when supplied by this backup.\nSaved Input Records preserves every original exported field, including history, drafts and reversals.\nSee Report Notes.csv before interpreting calculated balances. Older backups may not contain the newer calculated reports; their original records are preserved.\nThe matching Complete Website Restore folder contains canonical data and application code.\n',encoding='utf-8')
    # Only the four requested business folders and the hosting set remain at top level.
    for p in list(stage.iterdir()):
        if p.is_dir() and p.name not in (*MODULES,WEBSITE):move(p,Path(WEBSITE)/'Reference Exports'/p.name)
        elif p.is_file():move(p,Path(WEBSITE)/'Reference Exports'/p.name)
    with (hosting/'Recovery File Index.csv').open('w',encoding='utf-8-sig',newline='') as f:
        writer=csv.DictWriter(f,fieldnames=['Original archive path','Recovered path']);writer.writeheader();writer.writerows(mapping)
    (hosting/'HOSTING RESTORE - READ FIRST.txt').write_text('COMPLETE WEBSITE RESTORE\n\npublic_html contains the software code and design.\ntranstrade_private contains original business data, settings, users and documents.\nThey must remain siblings on hosting. Never upload transtrade_private inside public_html.\nUpload only to a new/private test installation first. Preserve current hosting before any live replacement.\nThe recovery program has NOT restored or started a website. Existing backup-PC, emergency recovery and backup-password vault credentials are deliberately not reactivated. Provision fresh security on replacement hosting.\nOptional database snapshots require a technician to validate the target database/file-storage path.\nReference Exports retains shared/unassigned records and older export formats. No unknown data has been assigned to a guessed module.\nKeep this decrypted folder private. The encrypted original ZIP remains the disaster-recovery source.\n',encoding='utf-8')
    (stage/'START HERE.txt').write_text('TRANSTRADE RECOVERY\n\nAccounts, Mill, Exports and Directors contain readable records, reports and documents.\nComplete Website Restore contains code/design and canonical private data for a technician.\nThis is a verified offline copy, not a live website restore. Original ZIP preserved.\nReport availability depends on the backup version. Always review each module Report Notes.csv.\nAll dates and amounts are from the chosen snapshot.\n',encoding='utf-8')
    if manifest.get('scope')=='data':
        (hosting/'SOFTWARE COPY REQUIRED.txt').write_text('This is a complete data snapshot. Add the matching separate Software and Setup ZIP through Recovery to populate public_html, or have a technician supply compatible software. The private data folder alone is not a running website.\n',encoding='utf-8')
    if manifest.get('scope')=='software':
        for module in MODULES:move(stage/module,Path(WEBSITE)/'Reference Exports'/module)
        (stage/'START HERE.txt').write_text('SOFTWARE AND SETUP\n\nComplete Website Restore/public_html contains application code and design. Its transtrade_private contains the weekly users, masters and settings snapshot. This archive does not contain all business entries or uploaded shipment documents. Combine it with a complete Data backup using Recovery to prepare hosting.\n',encoding='utf-8')
    progress('Organised Accounts, Mill, Exports, Directors and Complete Website Restore.')
