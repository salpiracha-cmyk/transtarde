# Owner-authorized update of the existing office agent and backup schedule.
# Payloads are pinned to the reviewed commit and verified before installation.
$ErrorActionPreference = 'Stop'
$folder = 'D:\transtrade office agent'
$release = '56496c9a14087ba321fd7b1315ed117bb4a31390'
$files = @{
    'office_backup_agent.py' = '8c467d06702e0268b4b2a341f2a847579feb63aecb27e06eb0a9d30c13cf744f'
    'install_office_backup_schedule.ps1' = '894b8404d0e09734c28405cfe4f3aa99e8ac621830dbd0a9c741be56cba3c751'
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
    foreach ($name in $files.Keys) {
        $target = Join-Path $folder $name
        if (Test-Path -LiteralPath $target) { Copy-Item -LiteralPath $target -Destination ($target + '.previous') -Force }
        Copy-Item -LiteralPath (Join-Path $stage $name) -Destination $target -Force
    }
    $installer = [scriptblock]::Create((Get-Content -LiteralPath (Join-Path $folder 'install_office_backup_schedule.ps1') -Raw))
    & $installer -AgentDirectory $folder
    $updated = Get-ScheduledTask -TaskName 'Transtrade_Office_Backup'
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
