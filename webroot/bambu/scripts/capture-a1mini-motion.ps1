# Capture Bambu A1 Mini motion-control responses from the report topic.
# Windows PowerShell 5.1 / PowerShell 7+

$ErrorActionPreference = "Stop"

$Mosquitto = "C:\Program Files\mosquitto\mosquitto_sub.exe"
$CaFile = "C:\tmp\bambu-ca.pem"
$LogFile = "C:\tmp\a1mini-motion-capture.txt"
$PrinterIp = "192.168.1.147"

if (-not (Test-Path -LiteralPath $Mosquitto)) {
    throw "mosquitto_sub.exe was not found at: $Mosquitto"
}

if (-not (Test-Path -LiteralPath $CaFile)) {
    throw "Bambu CA certificate was not found at: $CaFile"
}

$serial = (Read-Host "Enter the A1 Mini serial number").Trim()
if ([string]::IsNullOrWhiteSpace($serial)) {
    throw "The printer serial number cannot be blank."
}

$secureCode = Read-Host "Enter the LAN access code" -AsSecureString
$code = [System.Net.NetworkCredential]::new("", $secureCode).Password
if ([string]::IsNullOrWhiteSpace($code)) {
    throw "The LAN access code cannot be blank."
}

New-Item -ItemType Directory -Path (Split-Path -Parent $LogFile) -Force | Out-Null
New-Item -ItemType File -Path $LogFile -Force | Out-Null

$reportTopic = "device/$serial/report"

Write-Host ""
Write-Host "Subscribing to: $reportTopic"
Write-Host "Writing capture to: $LogFile"
Write-Host ""
Write-Host "Press Ctrl+C when the motion test is complete."
Write-Host ""

& $Mosquitto `
    -h $PrinterIp `
    -p 8883 `
    -u "bblp" `
    -P $code `
    -i "hotfetched-motion-observer" `
    -t $reportTopic `
    -V "mqttv311" `
    --tls-version "tlsv1.2" `
    --cafile $CaFile `
    --insecure `
    -F "%I`t%t`t%p" `
    -d 2>&1 |
    Tee-Object -FilePath $LogFile -Append
