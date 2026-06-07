# Run this script ONCE as Administrator to register the Hypermed queue worker.
# Right-click → "Run with PowerShell" → confirm the UAC prompt.

$action = New-ScheduledTaskAction `
    -Execute "C:\xampp82\php\php.exe" `
    -Argument "C:\xampp82\htdocs\hypermed-api\artisan queue:work --tries=2 --sleep=3 --timeout=120" `
    -WorkingDirectory "C:\xampp82\htdocs\hypermed-api"

$trigger = New-ScheduledTaskTrigger -AtLogOn

$settings = New-ScheduledTaskSettingsSet `
    -ExecutionTimeLimit (New-TimeSpan -Hours 0) `
    -RestartCount 5 `
    -RestartInterval (New-TimeSpan -Minutes 1) `
    -MultipleInstances IgnoreNew

Register-ScheduledTask `
    -TaskName "Hypermed Queue Worker" `
    -TaskPath "\Hypermed" `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -Description "Laravel queue worker for Hypermed API (processes SyncEmailsJob and future jobs)" `
    -RunLevel Highest `
    -Force

Write-Host "Done. The queue worker will now start automatically at every login." -ForegroundColor Green
Write-Host "You can also start/stop it manually from Task Scheduler > Hypermed > Hypermed Queue Worker."
