# Stop all tunnel processes and remove the tunnel config.
Get-Process ngrok, cloudflared -ErrorAction SilentlyContinue | Stop-Process -Force

$tunnelConfig = Join-Path (Split-Path $PSScriptRoot -Parent) "moodle\tunnel_config.php"
if (Test-Path $tunnelConfig) { Remove-Item $tunnelConfig }

$projectRoot = Split-Path $PSScriptRoot -Parent
@("moodle_tunnel_url.txt", "ai_tunnel_url.txt") | ForEach-Object {
    $f = Join-Path $projectRoot $_
    if (Test-Path $f) { Remove-Item $f }
}

Write-Output "Tunnels stopped. Moodle restored to localhost mode."
