"""Transtrade's single-file Windows Backup & Recovery installer/application."""
import argparse
import base64
from contextlib import redirect_stdout, redirect_stderr
from datetime import datetime, timedelta, timezone
import hashlib
import io
import json
import os
from pathlib import Path
import platform
import queue
import secrets
import shutil
import subprocess
import sys
import threading
import time
from urllib import request, error
import webbrowser
from xml.sax.saxutils import escape
if not getattr(sys,'frozen',False):sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from scripts import office_backup_agent as agent
from scripts.backup_recovery_reader import recover_copy

APP_TITLE='Transtrade Backup & Recovery'
TASK_NAME='Transtrade_Office_Backup'
SHIPMENT_TASK='Transtrade_Office_Shipments'
URL='https://app.transtradeinternational.com/api/office_pc_pair.php'
PK=timezone(timedelta(hours=5))


def app_dir():return Path(os.environ.get('LOCALAPPDATA',Path.home()))/'Transtrade'/'BackupRecovery'
def root_default():
    config=agent.config_path()
    if config.is_file():
        try:old=json.loads(config.read_text(encoding='utf-8'))
        except (OSError,ValueError):old={}
        dest=old.get('backupDestination') or old.get('destination')
        if dest and Path(dest).is_absolute() and Path(Path(dest).anchor).is_dir():return str(Path(dest).parent)
    return r'E:\TranstradeBackup' if Path('E:/').is_dir() else ''


def ps(script,data=None):
    if os.name!='nt':raise RuntimeError('This installer requires Windows 10 or Windows 11 (64-bit).')
    encoded=base64.b64encode(json.dumps(data or {}).encode()).decode()
    source="$ErrorActionPreference='Stop'; $d=ConvertFrom-Json ([Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('"+encoded+"'))); "+script
    command=base64.b64encode(source.encode('utf-16le')).decode()
    result=subprocess.run(['powershell.exe','-NoProfile','-NonInteractive','-EncodedCommand',command],capture_output=True,text=True,creationflags=subprocess.CREATE_NO_WINDOW)
    if result.returncode:raise RuntimeError('Windows could not apply the task or shortcuts. '+result.stderr.strip()[-1800:])
    return json.loads(result.stdout) if result.stdout.strip() else None


def tasks_preflight(names=(TASK_NAME,SHIPMENT_TASK)):
    return ps(r'''$sid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value;$out=@();
foreach($name in $d.names){$t=Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue;if(!$t){continue}
$owner=$t.Principal.UserId;if($owner -notmatch '^S-1-'){if(!$owner.Contains('\') -and !$owner.Contains('@')){$owner=$env:COMPUTERNAME+'\'+$owner};$owner=([Security.Principal.NTAccount]::new($owner)).Translate([Security.Principal.SecurityIdentifier]).Value}
if($owner -ne $sid){throw 'Sign in as the existing backup task owner. Its settings were preserved.'}
if($t.State -eq 'Running'){throw 'A backup is running. Let it finish, then choose Install again.'}
$out+=@{name=$name;xml=(Export-ScheduledTask -TaskName $name)}}
ConvertTo-Json -InputObject @($out) -Depth 8 -Compress''',{'names':list(names)}) or []


def task_xml(executable,kind='backup',sid=''):
    # A UTC daily trigger gives 18:00 Pakistan time even if the PC uses another zone.
    now=datetime.now(timezone.utc);start=now.replace(hour=13 if kind=='backup' else 0,minute=0,second=0,microsecond=0)
    boundary=start.strftime('%Y-%m-%dT%H:%M:%SZ')
    options='--backup-only --scheduled' if kind=='backup' else '--shipments-only'
    log=agent.config_path().with_name('office-backup-latest.log' if kind=='backup' else 'shipment-archive-latest.log')
    args='--agent '+options+' --log-file "'+str(log)+'"'
    repeat='<Repetition><Interval>PT30M</Interval><Duration>PT4H</Duration><StopAtDurationEnd>false</StopAtDurationEnd></Repetition>' if kind=='backup' else '<Repetition><Interval>PT5M</Interval><Duration>P1D</Duration><StopAtDurationEnd>false</StopAtDurationEnd></Repetition>'
    return '<?xml version="1.0" encoding="UTF-16"?>'+f'''<Task version="1.4" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">
<RegistrationInfo><Description>Transtrade verified encrypted backups. Keep this Windows user signed in; locking the screen is fine.</Description></RegistrationInfo>
<Triggers><CalendarTrigger>{repeat}<StartBoundary>{boundary}</StartBoundary><Enabled>true</Enabled><ScheduleByDay><DaysInterval>1</DaysInterval></ScheduleByDay></CalendarTrigger><LogonTrigger><Enabled>true</Enabled><UserId>{escape(sid)}</UserId></LogonTrigger></Triggers>
<Principals><Principal id="Author"><UserId>{escape(sid)}</UserId><LogonType>InteractiveToken</LogonType><RunLevel>LeastPrivilege</RunLevel></Principal></Principals>
<Settings><MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy><DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries><StopIfGoingOnBatteries>false</StopIfGoingOnBatteries><StartWhenAvailable>true</StartWhenAvailable><WakeToRun>true</WakeToRun><Enabled>true</Enabled><ExecutionTimeLimit>PT4H</ExecutionTimeLimit><RestartOnFailure><Interval>PT30M</Interval><Count>3</Count></RestartOnFailure></Settings>
<Actions Context="Author"><Exec><Command>{escape(str(executable))}</Command><Arguments>{escape(args)}</Arguments><WorkingDirectory>{escape(str(executable.parent))}</WorkingDirectory></Exec></Actions></Task>'''


def register_tasks(executable):
    sid=ps('[Security.Principal.WindowsIdentity]::GetCurrent().User.Value | ConvertTo-Json -Compress')
    tasks=[{'name':TASK_NAME,'xml':task_xml(executable,'backup',sid)},{'name':SHIPMENT_TASK,'xml':task_xml(executable,'shipments',sid)}]
    ps(r'''foreach($t in $d.tasks){Register-ScheduledTask -TaskName $t.name -Xml $t.xml -Force | Out-Null}
foreach($t in $d.tasks){$saved=Get-ScheduledTask -TaskName $t.name;if($saved.Principal.LogonType -ne 'Interactive'){throw 'Windows task sign-in mode was not verified.'};if($saved.Actions.Execute -ne $d.executable){throw 'Windows task program was not verified.'}}
'{}' ''',{'tasks':tasks,'executable':str(executable)})


def shortcuts(executable,locations=None):
    ps(r'''$shell=New-Object -ComObject WScript.Shell;$desktop=[Environment]::GetFolderPath('Desktop');
$menu=Join-Path ([Environment]::GetFolderPath('Programs')) 'Transtrade Backup';$folders=if($d.locations){@($d.locations)}else{@($desktop,$menu)};
foreach($folder in $folders){New-Item -ItemType Directory -Path $folder -Force | Out-Null};
foreach($folder in $folders){foreach($item in $d.items){$link=$shell.CreateShortcut((Join-Path $folder ($item.name+'.lnk')));$link.TargetPath=$d.executable;$link.Arguments=$item.arguments;$link.WorkingDirectory=Split-Path $d.executable;$link.IconLocation=$d.executable+',0';$link.Description='Transtrade Backup and Recovery';$link.Save()}};'{}' ''',{'executable':str(executable),'locations':[str(p) for p in locations] if locations else [],'items':[{'name':'Transtrade Backup Now','arguments':'--backup-now'},{'name':'Transtrade Recovery','arguments':'--restore'},{'name':'Transtrade Backup Status','arguments':'--status'}]})


def pair_call(action,device):
    req=request.Request(URL,data=json.dumps({'action':action,**device}).encode(),headers={'Content-Type':'application/json'},method='POST')
    try:
        with agent.opener().open(req,timeout=30) as response:
            raw=response.read(8193)
            if len(raw)>8192:raise RuntimeError('Unexpected website response.')
            value=json.loads(raw)
    except error.HTTPError as exc:
        # Fixed server-owned messages only; never print credentials or response bodies.
        raise RuntimeError('The website could not authorize this PC (HTTP '+str(exc.code)+'). Start Install again, or check the saved website backup password.') from None
    if not isinstance(value,dict) or value.get('ok') is not True:raise RuntimeError('Website authorization did not complete.')
    return value


def validate_root(root):
    root=Path(root)
    if not root.is_absolute() or root.is_symlink():raise ValueError('Choose a regular full backup folder path.')
    for name in ['SystemBackups/Daily','SystemBackups/Weekly','SystemBackups/Monthly','ShipmentArchives','RecoveryTests']:
        folder=root/name
        if folder.is_symlink():raise ValueError('Linked backup folders are not supported.')
        folder.mkdir(parents=True,exist_ok=True)
    import tempfile
    with tempfile.TemporaryFile(dir=root):pass
    return root


def configure(root,token,previous=None):
    root=validate_root(root)
    config=dict(previous or {})
    config.update({'backupUrl':'https://app.transtradeinternational.com/api/office_backup.php','officeAgentUrl':'https://app.transtradeinternational.com/api/office_agent.php',
        'backupDestination':str(root/'SystemBackups'),'shipmentArchiveDestination':str(root/'ShipmentArchives'),'token':token,'retention':{'daily':7,'weekly':4,'monthly':12}})
    config.setdefault('password','') # New PCs use the owner's server-managed password.
    agent.write_json(agent.config_path(),config,sensitive=True)
    return config


def call_agent(arguments,writer):
    previous=sys.argv;sys.argv=['Transtrade Backup']+arguments
    try:
        with redirect_stdout(writer),redirect_stderr(writer):return agent.main()
    finally:sys.argv=previous


class QueueWriter:
    def __init__(self,events):self.events=events
    def write(self,text):
        if text.strip():self.events.put(('log',text.rstrip()))
        return len(text)
    def flush(self):pass


def status_text():
    path=agent.config_path()
    if not path.exists():return 'Not installed on this Windows user. Choose Install / Connect.'
    config=json.loads(path.read_text(encoding='utf-8'));dest=Path(config.get('backupDestination') or config.get('destination'))
    lines=['Backup folder: '+str(dest),'Shipment folder: '+str(config.get('shipmentArchiveDestination','')),'Schedule: 6 pm Pakistan time, with missed-run catch-up.','Windows user must stay signed in. Locking the screen is fine.','']
    state=path.with_name('office-backup-state.json')
    if state.exists():
        s=json.loads(state.read_text());stamp=s.get('last_success')
        lines+=['Last successful download: '+(datetime.fromtimestamp(stamp,PK).strftime('%d %b %Y, %I:%M %p Pakistan time') if stamp else 'None'),'File: '+str(s.get('last_file','None'))]
    else:lines+=['No successful backup has been recorded on this Windows user.']
    catalog=dest/'tier-retention.json';receipts=json.loads(catalog.read_text()) if catalog.exists() else {}
    for tier in ['Daily','Weekly','Monthly']:
        files=sorted((dest/tier).glob('TRANSTRADE_FULL_*.zip'),key=lambda p:p.stat().st_mtime,reverse=True)
        lines+=['',tier+': '+(files[0].name if files else 'No file yet')]
        if files:
            expected=None
            if tier=='Daily' and state.exists():expected=s.get('sha256') if files[0].name==s.get('last_file') else None
            elif tier.lower() in receipts:expected=receipts[tier.lower()].get('sha256') if files[0].name==receipts[tier.lower()].get('name') else None
            lines+=['Checksum: '+('VERIFIED now' if expected and agent.sha256_file(files[0])==expected else 'No matching successful-download receipt; needs checking')]
    return '\n'.join(lines)


def prune_installed_apps(folder,current):
    import re
    known=[]
    for path in Path(folder).glob('TranstradeBackup-*.exe'):
        match=re.fullmatch(r'TranstradeBackup-([a-f0-9]{12})\.exe',path.name)
        if match and path.is_file() and not path.is_symlink() and agent.sha256_file(path).startswith(match[1]):known.append(path)
    previous=sorted((p for p in known if p!=current),key=lambda p:p.stat().st_mtime,reverse=True)
    for old in previous[1:]:
        try:old.unlink()
        except OSError:pass # Windows may still have an older GUI open; leave it held.


def install(root,events):
    if not getattr(sys,'frozen',False):raise RuntimeError('Install using the downloaded Windows EXE.')
    events.put(('log','Checking existing Windows tasks and preserving existing backups…'))
    validate_root(root);snapshots=tasks_preflight();folder=app_dir();folder.mkdir(parents=True,exist_ok=True)
    config_path=agent.config_path();old=json.loads(config_path.read_text(encoding='utf-8')) if config_path.exists() else None
    if old is not None:
        saved=config_path.with_name('office-backup.before-universal.json')
        if not saved.exists():agent.write_json(saved,old,sensitive=True)
    with agent.backup_run_lock(config_path) as acquired:
        if not acquired:raise RuntimeError('A backup is already running. Let it finish and choose Install again.')
        # Test protected local writes before asking the owner to authorize rotation.
        probe=config_path.with_name('.install-write-test.json');agent.write_json(probe,{},sensitive=True);probe.unlink()
        device={'id':secrets.token_hex(16),'secret':secrets.token_hex(32),'computer':platform.node()[:80]}
        started=pair_call('start',device);code=started.get('code','');url=started.get('approvalUrl','')
        expected='https://app.transtradeinternational.com/connect-backup-pc.php?request='+device['id']
        if url!=expected or not isinstance(code,str) or len(code)!=6 or not code.isdigit():raise RuntimeError('Unexpected website authorization response.')
        events.put(('authorize',(code,url)));deadline=time.monotonic()+900;webbrowser.open(url)
        while True:
            time.sleep(3)
            try:reply=pair_call('poll',{'id':device['id'],'secret':device['secret']})
            except (error.URLError,TimeoutError):
                if time.monotonic()>deadline:raise RuntimeError('Internet connection was lost. Choose Install again when it returns.') from None
                events.put(('log','Waiting for the website connection to return…'));time.sleep(5);continue
            if reply.get('state')=='approved':break
            if reply.get('state')!='pending' or time.monotonic()>deadline:raise RuntimeError('PC approval expired. Choose Install again.')
        token=reply.get('token','')
        if not isinstance(token,str) or len(token)!=64 or any(c not in '0123456789abcdef' for c in token):raise RuntimeError('Invalid PC authorization response.')
        events.put(('log','PC approved. Installing folders, tasks and shortcuts…'))
        configure(root,token,old)
        digest=agent.sha256_file(Path(sys.executable));executable=folder/('TranstradeBackup-'+digest[:12]+'.exe')
        if executable.resolve()!=Path(sys.executable).resolve():
            staged=executable.with_suffix('.installing');shutil.copyfile(sys.executable,staged)
            if agent.sha256_file(staged)!=digest:raise RuntimeError('Installer copy verification failed.')
            staged.replace(executable)
        for item in snapshots:
            saved=folder/('task-before-universal-'+hashlib.sha256(item['name'].encode()).hexdigest()[:12]+'.xml')
            if not saved.exists():saved.write_text(item['xml'],encoding='utf-16');agent.protect(saved)
        register_tasks(executable);shortcuts(executable)
        agent.write_json(folder/'installed.json',{'executable':str(executable),'backupRoot':str(root),'installedAt':datetime.now(PK).isoformat()},sensitive=True)
    events.put(('log','Installation and connection complete. Starting the first verified daily, weekly and monthly backups…'))
    result=call_agent(['--backup-only','--refresh-tiers'],QueueWriter(events))
    if result:raise RuntimeError('Installed and connected, but the first backup needs attention. The details remain in this window. Use Backup Now to retry.')
    prune_installed_apps(folder,executable)
    events.put(('done','SUCCESS: Installed, connected, scheduled and first daily / weekly / monthly backups completed.'))


def icon_file(path):
    # Original small vector-style TT mark, encoded directly as a Windows icon.
    import struct
    n=32;pixels=bytearray()
    for y in reversed(range(n)):
        for x in range(n):
            white=(5<=x<=14 and 8<=y<=11) or (9<=x<=11 and 8<=y<=25) or (17<=x<=27 and 8<=y<=11) or (21<=x<=23 and 8<=y<=25)
            pixels+=bytes((238,246,238,255) if white else (58,91,49,255))
    dib=struct.pack('<IIIHHIIIIII',40,n,n*2,1,32,0,n*n*4,0,0,0,0)+pixels+bytes(n*n//8)
    Path(path).write_bytes(struct.pack('<HHH',0,1,1)+struct.pack('<BBBBHHII',n,n,0,0,1,32,len(dib),22)+dib)


def gui(mode='setup'):
    import tkinter as tk
    from tkinter import ttk,filedialog,messagebox
    root=tk.Tk();root.title(APP_TITLE);root.geometry('840x700');root.minsize(720,640)
    style=ttk.Style();style.theme_use('clam');style.configure('TButton',padding=10);style.configure('Title.TLabel',font=('Segoe UI',20,'bold'))
    top=ttk.Frame(root,padding=18);top.pack(fill='both',expand=True);ttk.Label(top,text=APP_TITLE,style='Title.TLabel').pack(anchor='w')
    ttk.Label(top,text='One app for installation, automatic backups and safe recovery.').pack(anchor='w',pady=(4,15))
    tabs=ttk.Notebook(top);tabs.pack(fill='x');pages={}
    for title in ['Install / Connect','Backup Now','Recovery','Status']:
        page=ttk.Frame(tabs,padding=14);tabs.add(page,text=title);pages[title]=page
    events=queue.Queue();busy=False;buttons=[];approval_url='';output_path=None
    folder=tk.StringVar(value=root_default());backup=tk.StringVar();password=tk.StringVar();code=tk.StringVar(value='')
    setup=pages['Install / Connect'];ttk.Label(setup,text='Backup location (use an external or separate drive when available):').pack(anchor='w')
    line=ttk.Frame(setup);line.pack(fill='x',pady=6);ttk.Entry(line,textvariable=folder).pack(side='left',fill='x',expand=True)
    ttk.Button(line,text='Choose folder',command=lambda:folder.set(filedialog.askdirectory() or folder.get())).pack(side='left')
    paths=ttk.Label(setup,text='');paths.pack(anchor='w',pady=5)
    def folder_label(*_):paths.config(text=str(Path(folder.get())/'SystemBackups')+' → Daily / Weekly / Monthly\n'+str(Path(folder.get())/'ShipmentArchives')+'\n'+str(Path(folder.get())/'RecoveryTests') if folder.get() else 'Choose a backup folder before installing.')
    folder.trace_add('write',folder_label);folder_label()
    ttk.Label(setup,text='6 pm Pakistan time, with catch-up. Keep this Windows user signed in; locking is fine.\nApprove this PC on the website once. Existing backup files stay in place.').pack(anchor='w',pady=8)
    code_label=ttk.Label(setup,textvariable=code,font=('Segoe UI',15,'bold'))
    open_site=ttk.Button(setup,text='Open website approval',command=lambda:webbrowser.open(approval_url) if approval_url else None);open_site.state(['disabled'])
    message=tk.StringVar(value='Ready. Choose Install / Connect for a new or replacement PC.')
    ttk.Label(top,textvariable=message,wraplength=740,font=('Segoe UI',10,'bold')).pack(anchor='w',pady=12)
    log=tk.Text(top,height=8,wrap='word',font=('Consolas',10));log.pack(fill='both',expand=True)
    def write(text):log.insert('end',text+'\n');log.see('end')
    def start(fn):
        nonlocal busy
        if busy:return
        busy=True
        for button in buttons:button.state(['disabled'])
        def worker():
            try:fn()
            except Exception as exc:events.put(('error',str(exc)))
            finally:events.put(('idle',None))
        threading.Thread(target=worker,daemon=True).start()
    def install_click():
        if not folder.get():folder.set(filedialog.askdirectory(title='Choose your Transtrade backup folder') or '')
        if folder.get():start(lambda:install(Path(folder.get()),events))
    b=ttk.Button(setup,text='Install / Reconnect this PC',command=install_click);b.pack(anchor='w',pady=8);buttons.append(b)
    def backup_now():
        result=call_agent(['--backup-only','--force'],QueueWriter(events))
        if result:raise RuntimeError('Backup did not complete. Check the message above. Previous backups were preserved.')
        events.put(('done','SUCCESS: The requested backup run completed.'))
    ttk.Label(pages['Backup Now'],text='Create a fresh encrypted backup using the password saved on your website.\nNo connection key or ZIP password needs typing here.').pack(anchor='w')
    b=ttk.Button(pages['Backup Now'],text='Back up now',command=lambda:start(backup_now));b.pack(anchor='w',pady=12);buttons.append(b)
    recovery=pages['Recovery'];ttk.Label(recovery,text='Verify and recover a Complete Backup into a new private folder.\nYour original ZIP is preserved. This creates a recovery copy; it does not replace the live website.').pack(anchor='w')
    row=ttk.Frame(recovery);row.pack(fill='x',pady=8);ttk.Entry(row,textvariable=backup).pack(side='left',fill='x',expand=True)
    ttk.Button(row,text='Choose backup ZIP',command=lambda:backup.set(filedialog.askopenfilename(filetypes=[('Complete backup ZIP','*.zip')]) or '')).pack(side='left')
    recovery_folder=tk.StringVar(value=str(Path(folder.get())/'RecoveryTests') if folder.get() else '')
    ttk.Label(recovery,text='Create the new recovery folder inside:').pack(anchor='w')
    destination_row=ttk.Frame(recovery);destination_row.pack(fill='x',pady=5);ttk.Entry(destination_row,textvariable=recovery_folder).pack(side='left',fill='x',expand=True)
    ttk.Button(destination_row,text='Choose folder',command=lambda:recovery_folder.set(filedialog.askdirectory() or recovery_folder.get())).pack(side='left')
    ttk.Label(recovery,text='Password used when this backup was made:').pack(anchor='w');ttk.Entry(recovery,textvariable=password,show='•').pack(fill='x',pady=5)
    def restore_click():
        if not backup.get():messagebox.showinfo(APP_TITLE,'Choose a Complete Backup ZIP first.');return
        parent=Path(recovery_folder.get()) if recovery_folder.get() else None
        if parent is None:
            selected=filedialog.askdirectory(title='Choose where to create a NEW recovery folder')
            if not selected:return
            parent=Path(selected)
        secret=password.get();selected_backup=Path(backup.get());password.set('')
        def recover():
            result=recover_copy(selected_backup,secret,parent,lambda text:events.put(('log',text)))
            events.put(('recovered',str(result)))
        start(recover)
    b=ttk.Button(recovery,text='Verify & recover to a new folder',command=restore_click);b.pack(anchor='w',pady=6);buttons.append(b)
    ttk.Label(pages['Status'],text='Shows the last successful download and checks the latest retained files against their saved checksums.').pack(anchor='w')
    b=ttk.Button(pages['Status'],text='Check backup status',command=lambda:start(lambda:events.put(('done',status_text()))));b.pack(anchor='w',pady=12);buttons.append(b)
    footer=ttk.Frame(top);footer.pack(before=log,side='bottom',fill='x',pady=(8,0))
    ttk.Button(footer,text='Copy log',command=lambda:(root.clipboard_clear(),root.clipboard_append(log.get('1.0','end')))).pack(side='left')
    ttk.Button(footer,text='Open backup folder',command=lambda:os.startfile(str(Path(folder.get())/'SystemBackups')) if folder.get() and (Path(folder.get())/'SystemBackups').is_dir() else None).pack(side='left',padx=8)
    def drain():
        nonlocal busy,approval_url
        try:
            while True:
                kind,value=events.get_nowait()
                if kind=='authorize':
                    number,approval_url=value;code.set('Match this number on the website: '+number);code_label.pack(anchor='w',pady=5);open_site.pack(anchor='w');open_site.state(['!disabled']);message.set('Approve this PC on your website. No password or key to copy.')
                elif kind=='idle':
                    busy=False
                    for button in buttons:button.state(['!disabled'])
                elif kind=='error':message.set('Needs attention — see the details below.');write(value)
                elif kind=='recovered':message.set('SUCCESS: Recovery copy verified. Original ZIP preserved.');write('Recovered files: '+value);os.startfile(value)
                else:
                    if value.startswith('PC approved.') or kind=='done':code_label.pack_forget();open_site.pack_forget()
                    write(value);message.set(value.split('\n')[0][:180])
        except queue.Empty:pass
        root.after(150,drain)
    def close():
        if busy and not messagebox.askyesno(APP_TITLE,'A backup or installation is still running. Closing now may interrupt it. Close anyway?'):return
        root.destroy()
    root.protocol('WM_DELETE_WINDOW',close);root.after(150,drain)
    if mode=='restore':tabs.select(pages['Recovery'])
    elif mode=='status':tabs.select(pages['Status']);root.after(200,lambda:start(lambda:events.put(('done',status_text()))))
    elif mode=='backup-now':
        if agent.config_path().exists():tabs.select(pages['Backup Now']);root.after(200,lambda:start(backup_now))
    root.mainloop()


def main():
    if '--agent' in sys.argv:
        sys.argv.remove('--agent')
        if sys.stdout is None:sys.stdout=io.StringIO()
        if sys.stderr is None:sys.stderr=io.StringIO()
        return agent.main()
    parser=argparse.ArgumentParser();parser.add_argument('--backup-now',action='store_true');parser.add_argument('--restore',action='store_true');parser.add_argument('--status',action='store_true');parser.add_argument('--build-icon');parser.add_argument('--self-test-report')
    args=parser.parse_args()
    if args.build_icon:icon_file(args.build_icon);return 0
    if args.self_test_report:
        import tkinter as tk
        r=tk.Tk();r.withdraw();r.update();r.destroy()
        from cryptography.hazmat.primitives.ciphers.aead import AESGCM
        key=AESGCM.generate_key(128);nonce=bytes(12);assert AESGCM(key).decrypt(nonce,AESGCM(key).encrypt(nonce,b'fixture',None),None)==b'fixture'
        original_loop=tk.Tk.mainloop
        def layout_check(window,*unused):
            window.update()
            def descendants(widget):
                for child in widget.winfo_children():yield child;yield from descendants(child)
            widgets=list(descendants(window));logs=[w for w in widgets if isinstance(w,tk.Text)]
            assert logs and logs[0].winfo_height()>=40, 'Progress log must remain visible'
            footers=[w for w in widgets if w.winfo_class()=='TButton' and w.cget('text') in ('Copy log','Open backup folder')]
            assert len(footers)==2 and all(w.winfo_ismapped() for w in footers), 'Footer actions must remain visible'
            window.destroy()
        tk.Tk.mainloop=layout_check
        try:gui()
        finally:tk.Tk.mainloop=original_loop
        Path(args.self_test_report).write_text(json.dumps({'frozen':bool(getattr(sys,'frozen',False)),'tkinter':True,'encryption':True,'guiLayout':True,'platform':platform.system()}));return 0
    gui('restore' if args.restore else 'status' if args.status else 'backup-now' if args.backup_now else 'setup');return 0

if __name__=='__main__':
    try:sys.exit(main())
    except Exception as exc:
        import tkinter as tk
        from tkinter import messagebox
        error_root=tk.Tk();error_root.withdraw();messagebox.showerror(APP_TITLE,'The app could not start safely. Existing backups were preserved.\n\n'+str(exc));error_root.destroy();sys.exit(1)
