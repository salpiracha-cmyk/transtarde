$ErrorActionPreference = 'Stop'
try {
    $task = Get-ScheduledTask -TaskName 'Transtrade_Office_Backup'
    if ($task.State -eq 'Running') { throw 'A backup is running. Let it finish, then open this setup again.' }
    $python = ($task.Actions | Select-Object -First 1).Execute.Trim('"')
    if (!(Test-Path -LiteralPath $python -PathType Leaf)) { throw 'The existing backup Python program was not found.' }
    $action = $task.Actions | Select-Object -First 1
    # Use the existing task location; do not change its user, triggers or arguments.
    $directory = [Environment]::ExpandEnvironmentVariables($action.WorkingDirectory)
    if (!$directory) { $directory = 'D:\transtrade office agent' }
    $installed = Join-Path $directory 'office_backup_agent.py'
    $config = Join-Path $env:APPDATA 'Transtrade\office-backup.json'
    if (!(Test-Path -LiteralPath $installed -PathType Leaf) -or !(Test-Path -LiteralPath $config -PathType Leaf)) {
        throw 'Existing office backup settings were not found. Run this on TTI-SERVER under its usual backup user.'
    }
    if ($action.Arguments -notlike '*office_backup_agent.py*') { throw 'The existing backup task needs review; its settings were preserved.' }
    $setup = Join-Path $PSScriptRoot 'connection.json'
    $source = Join-Path $PSScriptRoot 'office_backup_agent.py'
    $details = Get-Content -LiteralPath $setup -Raw | ConvertFrom-Json
    if ($details.agentSha256 -notmatch '^[a-f0-9]{64}$' -or
        (Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash.ToLowerInvariant() -ne $details.agentSha256) {
        throw 'The setup files do not match. Download and extract a fresh setup.'
    }
    $previous = "$installed.before-easy-connect"
    if (!(Test-Path -LiteralPath $previous)) { Copy-Item -LiteralPath $installed -Destination $previous }
    $staged = "$installed.connect-new"
    Copy-Item -LiteralPath $source -Destination $staged -Force
    Move-Item -LiteralPath $staged -Destination $installed -Force
    & $python $installed --config $config --connect-setup $setup
    if ($LASTEXITCODE -ne 0) { throw 'PC connection was not completed. See the message above.' }
    Remove-Item -LiteralPath $setup -Force
    Write-Host 'PC connected. Preparing fresh encrypted daily, weekly and monthly backups. Leave this window open.'
    & $python $installed --config $config --backup-only --refresh-tiers
    if ($LASTEXITCODE -ne 0) { throw 'PC connected, but the fresh backups need checking. Keep the error message.' }
    Write-Host 'SUCCESS: PC connected and the fresh backup run completed. Existing folders and schedule were preserved.'
    Write-Host 'You can now delete this extracted setup folder and its downloaded ZIP.'
} catch {
    Write-Host ('SETUP NEEDS ATTENTION: ' + $_.Exception.Message) -ForegroundColor Red
    exit 1
}
