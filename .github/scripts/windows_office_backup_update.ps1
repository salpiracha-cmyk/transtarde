# Owner-authorized update of the existing office agent and backup schedule.
# Payloads are pinned to the reviewed commit and verified before installation.
$ErrorActionPreference = 'Stop'
$folder = 'D:\transtrade office agent'
$release = 'c9daaff6ff99b4515c0125a22f86c99e703eb863'
$files = @{
    'office_backup_agent.py' = '756a5f16a73f361e67ea30c5e94712a5ffe49db48fc05525bb0e22a3191612c0'
    'install_office_backup_schedule.ps1' = 'c82d099f80e251807ec7a5bb133770f6b534ca8ba83c2ba74fe08973762d8c0c'
}
$task = Get-ScheduledTask -TaskName 'Transtrade_Office_Backup'
if (!(Test-Path -LiteralPath (Join-Path $folder 'office_backup_agent.py') -PathType Leaf)) { throw 'The existing office agent was not found. Nothing was changed.' }
$stage = Join-Path $env:TEMP ('tti-office-update-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $stage | Out-Null
try {
    foreach ($name in $files.Keys) {
        $url = 'https://raw.githubusercontent.com/salpiracha-cmyk/transtarde/' + $release + '/scripts/' + $name
        $path = Join-Path $stage $name
        Invoke-WebRequest -UseBasicParsing -Uri $url -OutFile $path
        if ((Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() -ne $files[$name]) { throw ('Downloaded source failed verification: ' + $name) }
    }
    if ($task.State -eq 'Running') {
        Write-Host 'Stopping the current scheduled attempt before updating the agent. Completed backups are preserved.'
        Stop-ScheduledTask -TaskName $task.TaskName
        Start-Sleep -Seconds 3
        if ((Get-ScheduledTask -TaskName $task.TaskName).State -eq 'Running') { throw 'The old task has not stopped. Its installed files were left unchanged.' }
    }
    $activeAgents = @(Get-CimInstance -ClassName Win32_Process -Filter "Name='python.exe' OR Name='pythonw.exe'" -ErrorAction Stop | Where-Object { $_.CommandLine -match 'office_backup_agent\.py' })
    if ($activeAgents.Count) { throw 'Another office backup attempt is still running. Press Ctrl+C in its PowerShell window before running this updater again.' }
    foreach ($name in $files.Keys) {
        $target = Join-Path $folder $name
        if (Test-Path -LiteralPath $target) { Copy-Item -LiteralPath $target -Destination ($target + '.previous') -Force }
        Copy-Item -LiteralPath (Join-Path $stage $name) -Destination $target -Force
    }
    $taskCredential = $null
    if ($task.Principal.LogonType -eq 'Password') {
        $account = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
        $taskCredential = Get-Credential -UserName $account -Message 'Enter this Windows account password (not its PIN). It is used locally to update the existing backup task.'
        if (!$taskCredential) { throw 'No Windows credential supplied. The schedule was not changed.' }
    }
    $installer = [scriptblock]::Create((Get-Content -LiteralPath (Join-Path $folder 'install_office_backup_schedule.ps1') -Raw))
    & $installer -AgentDirectory $folder -TaskCredential $taskCredential
    if (!$?) { throw 'The schedule installer failed. No success is being reported.' }
    $updated = Get-ScheduledTask -TaskName 'Transtrade_Office_Backup'
    $verifiedArguments = ($updated.Actions | Select-Object -First 1).Arguments
    if (!$verifiedArguments.Contains('--scheduled') -or !$verifiedArguments.Contains('--log-file')) { throw 'The expected task action was not installed. No backup test was started.' }
    $python = ($updated.Actions | Select-Object -First 1).Execute
    Write-Host 'Running one fresh verified backup now. The 6 pm task remains enabled.'
    $ErrorActionPreference = 'Continue'
    & $python -u (Join-Path $folder 'office_backup_agent.py') --backup-only --scheduled --force
    $result = $LASTEXITCODE
    $ErrorActionPreference = 'Stop'
    if ($result -ne 0) { throw 'The schedule was installed, but the test transfer failed. Send the error shown above.' }
    Write-Host 'OFFICE BACKUP VERIFIED; DAILY 6 PM SCHEDULE INSTALLED.'
} finally {
    if (Test-Path -LiteralPath $stage) { Remove-Item -LiteralPath $stage -Recurse -Force }
}
