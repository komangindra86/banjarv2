param([int]$Port = 18915)
$ErrorActionPreference = 'Stop'
$projectPath = Split-Path $PSScriptRoot -Parent
$runtimePath = 'C:\laragon\bin\php\php-8.2.27-Win32-vs16-x64\php.exe'
if (-not (Test-Path -LiteralPath $runtimePath)) { $runtimePath = (Get-Command php).Source }
Push-Location (Join-Path $projectPath 'public')
try {
    & $runtimePath -S "127.0.0.1:$Port" -t (Join-Path $projectPath 'public') (Join-Path $projectPath 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')
} finally { Pop-Location }
