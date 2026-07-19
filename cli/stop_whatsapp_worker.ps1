# Stop the WhatsApp queue worker (Windows PowerShell)
# Run from project root: .\cli\stop_whatsapp_worker.ps1

$processes = Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue | 
    Where-Object { $_.CommandLine -like "*whatsapp_worker*" }

if ($null -eq $processes -or $processes.Count -eq 0) {
    Write-Host "WhatsApp worker is not running."
    exit 0
}

Write-Host "Stopping WhatsApp worker ($($processes.Count) process(es))..."
$processes | ForEach-Object {
    Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
    Write-Host "  Stopped PID $($_.ProcessId)"
}
Write-Host "WhatsApp worker stopped."
