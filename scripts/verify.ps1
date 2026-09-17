# Verifikasi umum: test PHP (MySQL banjarv2_testing), Pint, PWA test, build Vite,
# lalu migration database lokal banjarv2 hanya jika semua langkah lolos.
$ErrorActionPreference = 'Continue'
Set-Location (Split-Path -Parent $PSScriptRoot)
$php = 'C:\laragon\bin\php\php-8.2.27-Win32-vs16-x64\php.exe'
$log = 'storage\logs\verify.log'
Start-Transcript -Path $log -Force | Out-Null
$failed = $false
function Step($name, [scriptblock]$cmd) {
    Write-Host "`n===== $name ====="
    & $cmd
    if ($LASTEXITCODE -ne 0) { Write-Host "!!!!! GAGAL: $name (exit $LASTEXITCODE)"; $script:failed = $true }
}
Step 'config:clear' { & $php artisan config:clear }
Step 'view:clear' { & $php artisan view:clear }
Step 'phpunit' { & $php vendor/bin/phpunit --log-junit storage/logs/verify-junit.xml }
Step 'pint' { & $php vendor/bin/pint --test app config database routes tests }
Step 'npm test' { npm test }
Step 'npm build' { npm run build }
if (-not $failed) {
    Step 'migrate banjarv2' { & $php artisan migrate --force }
    Step 'migrate:status' { & $php artisan migrate:status }
} else {
    Write-Host "`nMigration database lokal dilewati karena ada langkah yang gagal."
}
Write-Host "`nSELESAI. failed=$failed"
Stop-Transcript | Out-Null
