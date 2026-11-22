@echo off
rem CP5 (stream B) - Windows Sandbox logon command: opens a visible PowerShell window that runs the driver.
rem   Start-Cp5.cmd auto | manual | fresh | continue  [scenario ids for -Only joined with +, e.g. g1+g2 (cmd splits at commas)]
rem Everything runs INSIDE the sandbox as its own administrator (WDAGUtilityAccount); the host is never elevated or changed.
set "SCEN=%~1"
if "%SCEN%"=="" set "SCEN=auto"
set "ONLY=%~2"
start "Bingoo Edge CP5 - %SCEN%" powershell.exe -NoProfile -ExecutionPolicy Bypass -NoExit -File "C:\cp5\kit\Invoke-Cp5Sandbox.ps1" -Scenario %SCEN% -Only "%ONLY%"
