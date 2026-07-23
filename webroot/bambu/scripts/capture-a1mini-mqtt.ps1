# Capture Bambu A1 Mini MQTT request and report traffic
# Compatible with Windows PowerShell 5.1 and PowerShell 7+

$ErrorActionPreference = "Stop"

$Mosquitto = "C:\Program Files\mosquitto\mosquitto_sub.exe"
$CaFile = "C:\tmp\bambu-ca.pem"
$LogFile = "C:\tmp\a1mini-mqtt-request-report.txt"
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

$requestTopic = "device/$serial/request"
$reportTopic = "device/$serial/report"

Write-Host ""
Write-Host "Subscribing to:"
Write-Host "  $requestTopic"
Write-Host "  $reportTopic"
Write-Host ""
Write-Host "Writing capture to:"
Write-Host "  $LogFile"
Write-Host ""
Write-Host "Press Ctrl+C when the test is complete."
Write-Host ""

& $Mosquitto `
    -h $PrinterIp `
    -p 8883 `
    -u "bblp" `
    -P $code `
    -i "hotfetched-observer-04" `
    -t $requestTopic `
    -t $reportTopic `
    -V "mqttv311" `
    --tls-version "tlsv1.2" `
    --cafile $CaFile `
    --insecure `
    -F "%I`t%t`t%p" `
    -d 2>&1 |
    Tee-Object -FilePath $LogFile -Append
