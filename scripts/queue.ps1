$ErrorActionPreference = 'Stop'
$projectPath = Split-Path $PSScriptRoot -Parent
$runtimePath = 'C:\laragon\bin\php\php-8.2.27-Win32-vs16-x64\php.exe'
if (-not (Test-Path -LiteralPath $runtimePath)) { $runtimePath = (Get-Command php).Source }
Push-Location $projectPath
try {
    & $runtimePath artisan queue:work database --sleep=2 --tries=3 --timeout=60 --max-time=3600
} finally { Pop-Location }
