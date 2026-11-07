# Installs owner-approved tier replacement without changing the scheduled task.
$ErrorActionPreference = 'Stop'
$folder = 'D:\transtrade office agent'
$task = Get-ScheduledTask -TaskName 'Transtrade_Office_Backup' -ErrorAction Stop
if ($task.State -eq 'Running') { throw 'Let the running scheduled backup finish before applying retention.' }
$owner = [string]$task.Principal.UserId
if ($owner -match '^S-1-') { $ownerSid = $owner } else {
    if (!$owner.Contains('\') -and !$owner.Contains('@')) { $owner = $env:COMPUTERNAME + '\' + $owner }
    $ownerSid = ([System.Security.Principal.NTAccount]::new($owner)).Translate([System.Security.Principal.SecurityIdentifier]).Value
}
if ($ownerSid -ne [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value) { throw 'Run this update as the existing backup task owner.' }
$active = @(Get-CimInstance -ClassName Win32_Process -Filter "Name='python.exe' OR Name='pythonw.exe'" -ErrorAction Stop | Where-Object { $_.CommandLine -match 'office_backup_agent\.py' })
if ($active.Count) { throw 'Let the current manual backup finish before applying retention.' }
$target = Join-Path $folder 'office_backup_agent.py'
if (!(Test-Path -LiteralPath $target -PathType Leaf)) { throw 'The installed office backup agent was not found.' }
$expected = '1dbae1aafc8a2f1dfee9c7053bbd04bb144ab324e442af38a972d3e33d388018'
$stage = Join-Path $env:TEMP ('tti-retention-' + [guid]::NewGuid().ToString('N') + '.py')
try {
    Invoke-WebRequest -UseBasicParsing -Uri 'https://raw.githubusercontent.com/salpiracha-cmyk/transtarde/4d3afa0b524312a54acb39fab9c00bc1b940140a/scripts/office_backup_agent.py' -OutFile $stage
    if ((Get-FileHash -LiteralPath $stage -Algorithm SHA256).Hash.ToLowerInvariant() -ne $expected) { throw 'Downloaded retention agent failed verification.' }
    if ((Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash.ToLowerInvariant() -ne $expected) {
        Copy-Item -LiteralPath $target -Destination ($target + '.previous') -Force
        Copy-Item -LiteralPath $stage -Destination $target -Force
    }
    $python = ($task.Actions | Select-Object -First 1).Execute
    Write-Host 'Checking verified replacement backups before deleting any older daily/weekly copies...'
    & $python -u $target --backup-only --retention-only
    if ($LASTEXITCODE -ne 0) { throw 'Retention cleanup did not complete. Send the error above.' }
    Write-Host 'VERIFIED WEEKLY/MONTHLY REPLACEMENT CLEANUP ENABLED.'
} finally {
    if (Test-Path -LiteralPath $stage) { Remove-Item -LiteralPath $stage -Force }
}
