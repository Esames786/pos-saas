@echo off
title POS Print Agent - DEBUG
set NODE=D:\laragon2\bin\nodejs\node-v18\node.exe

echo Freeing port 9100...
for /f "tokens=5" %%a in ('netstat -ano 2^>nul ^| findstr "127.0.0.1:9100"') do (
    echo  - Killing PID %%a
    taskkill /PID %%a /F >nul 2>&1
)

echo.
echo Running agent (Ctrl+C to stop)...
echo.

"%NODE%" --no-warnings "D:\laragon2\www\pos-saas\tools\print-agent\pos-test-agent.js"

echo.
echo *** AGENT EXITED ***
pause
