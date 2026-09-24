@echo off
title Claude Code - Remote Control (Banjar Digital V2)
cd /d "C:\laragon\www\banjarv2"
set "CLAUDE=claude"
where claude >nul 2>nul || set "CLAUDE=%USERPROFILE%\.local\bin\claude.exe"
if not exist "%CLAUDE%" if not "%CLAUDE%"=="claude" (
  echo Claude Code belum terinstal. Jalankan install-claude-code.bat dulu.
  pause
  exit /b 1
)
echo Menjalankan Claude Code dengan Remote Control aktif...
echo - Kalau muncul pertanyaan "trust this folder", pilih Yes.
echo - Ketik /rc lalu Enter untuk menampilkan QR code, scan dengan aplikasi Claude di HP.
echo.
"%CLAUDE%" --remote-control "Banjar Digital V2"
pause
