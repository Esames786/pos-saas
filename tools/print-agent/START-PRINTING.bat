@echo off
title POS Print Agent Launcher

echo Stopping any existing agent...
taskkill /FI "WINDOWTITLE eq POS Print Agent*" /F >nul 2>&1
for /f "tokens=5" %%a in ('netstat -ano 2^>nul ^| findstr "127.0.0.1:9100"') do taskkill /PID %%a /F >nul 2>&1
timeout /t 1 /nobreak >nul

echo Starting POS Print Agent...
start "POS Print Agent" "D:\laragon2\www\pos-saas\tools\print-agent\dist\pos-test-agent.exe"

echo.
echo Done! Look for the "POS Print Agent" window in your taskbar.
timeout /t 3 /nobreak >nul
