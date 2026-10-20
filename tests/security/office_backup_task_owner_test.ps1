$ErrorActionPreference = 'Stop'
$path = Join-Path $PSScriptRoot '../../scripts/install_office_backup_schedule.ps1'
$tokens = $null; $errors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile($path, [ref]$tokens, [ref]$errors)
if ($errors.Count) { throw 'Installer parsing failed.' }
$function = $ast.Find({ param($node) $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq 'Resolve-TtiBackupTaskOwnerSid' }, $true)
. ([scriptblock]::Create($function.Extent.Text))
$identity = [System.Security.Principal.WindowsIdentity]::GetCurrent()
$localName = ($identity.Name -split '\\')[-1]
foreach ($name in @($identity.Name, $localName, $identity.User.Value)) {
    if ((Resolve-TtiBackupTaskOwnerSid $name) -ne $identity.User.Value) { throw 'Task owner SID mismatch.' }
}
$failed = $false
try { Resolve-TtiBackupTaskOwnerSid ('missing-tti-' + [guid]::NewGuid().ToString('N')) | Out-Null } catch { $failed = $true }
if (!$failed) { throw 'Unknown accounts must be refused.' }
Write-Host 'TASK_OWNER_RESOLUTION_OK; installer was not executed; task principal unchanged.'
# Execute the actual installer update boundary with a failing local mock.
$updateBlock = $ast.Find({ param($node) $node -is [System.Management.Automation.Language.TryStatementAst] -and $node.Body.Extent.Text.Contains('Set-ScheduledTask @updateParameters') }, $true)
if (!$updateBlock) { throw 'Task update must have a guarded failure boundary.' }
function Set-ScheduledTask {
    [CmdletBinding()]param([string]$TaskName)
    Write-Error 'Fixture: Windows rejected stored credentials.'
}
$updateParameters = @{TaskName='fixture-only';ErrorAction='Stop'}
$failed = $false
try { . ([scriptblock]::Create($updateBlock.Extent.Text)) } catch { $failed = $_.Exception.Message.Contains('schedule was not installed') }
if (!$failed) { throw 'A rejected task update must stop installation.' }
Write-Host 'TASK_AUTHENTICATION_FAILURE_STOPS_INSTALL_OK'
