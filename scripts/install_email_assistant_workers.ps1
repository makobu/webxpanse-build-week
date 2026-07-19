param(
    [ValidateSet('Install', 'Status', 'Enable', 'Disable', 'Remove')]
    [string]$Action = 'Install'
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$php = 'C:\xampp\php\php.exe'
if (-not (Test-Path -LiteralPath $php)) {
    throw "PHP executable not found at $php"
}

$definitions = @(
    @{ Name = 'CRM Email Assistant Digest'; Script = 'cli\daily_digest_worker.php' },
    @{ Name = 'CRM Email Assistant Inbound'; Script = 'cli\fetch_incoming_emails.php' }
)

if ($Action -eq 'Status') {
    foreach ($definition in $definitions) {
        $task = Get-ScheduledTask -TaskName $definition.Name -ErrorAction SilentlyContinue
        if ($null -eq $task) {
            [pscustomobject]@{ TaskName = $definition.Name; State = 'Missing'; Arguments = '' }
        } else {
            [pscustomobject]@{ TaskName = $task.TaskName; State = $task.State; Arguments = ($task.Actions.Arguments -join '; ') }
        }
    }
    exit 0
}

if ($Action -eq 'Remove') {
    foreach ($definition in $definitions) {
        if (Get-ScheduledTask -TaskName $definition.Name -ErrorAction SilentlyContinue) {
            Unregister-ScheduledTask -TaskName $definition.Name -Confirm:$false
        }
    }
    exit 0
}

if ($Action -in @('Enable', 'Disable')) {
    foreach ($definition in $definitions) {
        if (-not (Get-ScheduledTask -TaskName $definition.Name -ErrorAction SilentlyContinue)) {
            throw "Scheduled task is not installed: $($definition.Name)"
        }
        if ($Action -eq 'Enable') {
            Enable-ScheduledTask -TaskName $definition.Name | Out-Null
        } else {
            Disable-ScheduledTask -TaskName $definition.Name | Out-Null
        }
    }
    & $PSCommandPath -Action Status
    exit 0
}

foreach ($definition in $definitions) {
    $scriptPath = Join-Path $root $definition.Script
    if (-not (Test-Path -LiteralPath $scriptPath)) {
        throw "Worker script not found: $scriptPath"
    }

    $taskAction = New-ScheduledTaskAction -Execute $php -Argument ('"' + $scriptPath + '"') -WorkingDirectory $root
    $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 5)
    $settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 4)
    Register-ScheduledTask -TaskName $definition.Name -Action $taskAction -Trigger $trigger -Settings $settings -Description 'webXpanse CRM Email Assistant worker; managed by scripts/install_email_assistant_workers.ps1' -Force | Out-Null
}

& $PSCommandPath -Action Status
