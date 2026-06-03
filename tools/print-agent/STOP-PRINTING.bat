@echo off
echo Stopping POS Print Agent processes...
taskkill /FI "WINDOWTITLE eq Fake Printer*" /F >nul 2>&1
taskkill /FI "WINDOWTITLE eq POS Print Agent*" /F >nul 2>&1
taskkill /FI "WINDOWTITLE eq POS Print Agent Launcher*" /F >nul 2>&1
echo Done.
timeout /t 2 /nobreak >nul
