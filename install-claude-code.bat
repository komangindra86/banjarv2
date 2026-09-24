@echo off
title Install Claude Code
echo Menginstal Claude Code (installer resmi Anthropic)...
curl -fsSL https://claude.ai/install.cmd -o "%TEMP%\install-claude.cmd" && call "%TEMP%\install-claude.cmd" && del "%TEMP%\install-claude.cmd"
echo.
"%USERPROFILE%\.local\bin\claude.exe" --version
echo.
echo Selesai. Sekarang jalankan start-remote-control.bat
pause
