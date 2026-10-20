# Run as the existing backup task's Windows user, after updating the agent.
[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
$taskName = 'Transtrade_Office_Backup'
$task = Get-ScheduledTask -TaskName $taskName
if ($task.State -eq 'Running') { throw 'A backup is running. Let it finish before changing its schedule.' }
if ((Get-TimeZone).Id -ne 'Pakistan Standard Time') { throw 'The server clock must use Pakistan Standard Time before installing the 6 pm schedule.' }
$agentPath = Join-Path $PSScriptRoot 'office_backup_agent.py'
if (!(Test-Path -LiteralPath $agentPath -PathType Leaf)) { throw 'The office backup agent is missing from this folder.' }
if (!(Get-Content -LiteralPath $agentPath -Raw).Contains("'--scheduled'")) { throw 'Install the updated backup agent before changing the task.' }
$identity = [System.Security.Principal.WindowsIdentity]::GetCurrent()
$owner = $task.Principal.UserId
try {
    $ownerSid = if ($owner -match '^S-1-') { $owner } else { ([System.Security.Principal.NTAccount]$owner).Translate([System.Security.Principal.SecurityIdentifier]).Value }
} catch { throw 'Could not verify the existing task owner. The task was not changed.' }
if ($ownerSid -ne $identity.User.Value) { throw 'Sign in as the existing backup task owner so its protected configuration remains available.' }
$configPath = Join-Path $env:APPDATA 'Transtrade\office-backup.json'
if (!(Test-Path -LiteralPath $configPath -PathType Leaf)) { throw 'The existing protected backup configuration was not found. The task was not changed.' }
$originalAction = $task.Actions | Select-Object -First 1
$officialPython = Join-Path $env:LOCALAPPDATA 'Programs\Python\Python314\python.exe'
$pythonPath = if (Test-Path -LiteralPath $officialPython -PathType Leaf) { $officialPython } else { $originalAction.Execute }
if (!(Test-Path -LiteralPath $pythonPath -PathType Leaf)) { throw 'The configured Python program was not found.' }
$probe = 'import ssl, json, zipfile; print("PYTHON_BACKUP_LIBRARIES_OK")'
$probe | & $pythonPath -u -
if ($LASTEXITCODE -ne 0) { throw 'Python could not load its backup libraries. The task was not changed.' }
$savedTask = Join-Path $PSScriptRoot 'Transtrade_Office_Backup.before-6pm.xml'
if (!(Test-Path -LiteralPath $savedTask)) { Export-ScheduledTask -TaskName $taskName | Set-Content -LiteralPath $savedTask -Encoding UTF8 }
$runnerPath = Join-Path $PSScriptRoot 'run_office_backup.ps1'
$logPath = Join-Path $PSScriptRoot 'office-backup-latest.log'
$runner = @'
$ErrorActionPreference = 'Continue'
& '__PYTHON__' -u '__AGENT__' --backup-only --scheduled *> '__LOG__'
exit $LASTEXITCODE
'@
$runner = $runner.Replace('__PYTHON__', $pythonPath.Replace("'", "''")).Replace('__AGENT__', $agentPath.Replace("'", "''")).Replace('__LOG__', $logPath.Replace("'", "''"))
$runner | Set-Content -LiteralPath $runnerPath -Encoding UTF8
$powershellPath = Join-Path $env:WINDIR 'System32\WindowsPowerShell\v1.0\powershell.exe'
$action = New-ScheduledTaskAction -Execute $powershellPath -Argument ('-NoProfile -NonInteractive -File "' + $runnerPath + '"') -WorkingDirectory $PSScriptRoot
$daily = New-ScheduledTaskTrigger -Daily -At '18:00'
$catchup = New-ScheduledTaskTrigger -AtLogOn -User $owner
$settings = $task.Settings
$settings.StartWhenAvailable = $true
$settings.MultipleInstances = 2 # IgnoreNew: one backup task at a time.
$settings.RestartCount = 3
$settings.RestartInterval = 'PT30M'
$settings.ExecutionTimeLimit = 'PT4H'
$settings.WakeToRun = $true
$settings.DisallowStartIfOnBatteries = $false
$settings.StopIfGoingOnBatteries = $false
Set-ScheduledTask -TaskName $taskName -Action $action -Trigger @($daily, $catchup) -Settings $settings | Out-Null
Write-Host 'Installed: daily 6 pm Pakistan time; missed-run catch-up; weekly Monday and monthly first weekday use the same verified download.'
Write-Host ('Latest run log: ' + $logPath)
Get-ScheduledTask -TaskName $taskName | Get-ScheduledTaskInfo | Format-List LastRunTime, LastTaskResult, NextRunTime
