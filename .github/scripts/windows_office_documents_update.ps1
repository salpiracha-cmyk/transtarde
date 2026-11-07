param(
    [Parameter(Mandatory=$true)][ValidatePattern('^[a-f0-9]{40}$')][string]$Release,
    [Parameter(Mandatory=$true)][ValidatePattern('^[a-f0-9]{64}$')][string]$AgentSha256
)
# Update document delivery only. Retain the saved credentials, backup settings and task schedule.
$ErrorActionPreference = 'Stop'
$agentFolder = 'D:\transtrade office agent'
$agentPath = Join-Path $agentFolder 'office_backup_agent.py'
$stagePath = Join-Path $env:TEMP ('tti-documents-agent-' + [guid]::NewGuid().ToString('N') + '.py')
if (!(Test-Path -LiteralPath $agentPath -PathType Leaf)) { throw 'The installed Office Agent was not found.' }
$task = Get-ScheduledTask -TaskName 'Transtrade_Office_Backup' -ErrorAction Stop
$python = ($task.Actions | Select-Object -First 1).Execute
if (!(Test-Path -LiteralPath $python -PathType Leaf)) { throw 'The existing task Python executable was not found.' }
try {
    Invoke-WebRequest -UseBasicParsing -Uri ('https://raw.githubusercontent.com/salpiracha-cmyk/transtarde/' + $Release + '/scripts/office_backup_agent.py') -OutFile $stagePath
    if ((Get-FileHash -LiteralPath $stagePath -Algorithm SHA256).Hash.ToLowerInvariant() -ne $AgentSha256) { throw 'Downloaded agent failed verification. Installed files were not changed.' }
    if ($task.State -eq 'Running') { throw 'The existing scheduled attempt is running. Wait until it finishes, then run this update again.' }
    $other = @(Get-CimInstance Win32_Process -Filter "Name='python.exe' OR Name='pythonw.exe'" | Where-Object { $_.CommandLine -match 'office_backup_agent\.py' })
    if ($other.Count) { throw 'An Office Agent is still running. Wait until it finishes before updating.' }
    Copy-Item -LiteralPath $agentPath -Destination ($agentPath + '.previous') -Force
    Copy-Item -LiteralPath $stagePath -Destination $agentPath -Force
    Set-Location -LiteralPath $agentFolder
    & $python -u $agentPath --shipments-only
    if ($LASTEXITCODE -ne 0) { throw 'Agent installed. Document delivery needs attention; see the error above.' }
    Write-Host 'Document agent updated. Verified bank folders have been refreshed. Backup schedule and credentials retained.'
} finally { if (Test-Path -LiteralPath $stagePath) { Remove-Item -LiteralPath $stagePath -Force } }
