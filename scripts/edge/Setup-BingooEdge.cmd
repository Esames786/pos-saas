@echo off
rem Bingoo Edge - Branch Server SETUP (install or update). Double-click this file.
rem It asks Windows for administrator rights (click Yes), then opens the setup wizard window.
rem Package layout (stream E builder): <package>\Setup-BingooEdge.cmd with the wizard in <package>\app\scripts\edge\wizard\
rem (inside the signed artifact); the original copy under <package>\scripts\ finds it in scripts\wizard\.
rem (source tree: scripts\edge\Setup-BingooEdge.cmd next to scripts\edge\wizard\).
setlocal
set "HERE=%~dp0"
set "WIZ=%HERE%app\scripts\edge\wizard\Start-BingooEdgeWizard.ps1"
if not exist "%WIZ%" set "WIZ=%HERE%wizard\Start-BingooEdgeWizard.ps1"
if not exist "%WIZ%" set "WIZ=%HERE%scripts\wizard\Start-BingooEdgeWizard.ps1"
if not exist "%WIZ%" (
  echo The setup wizard was not found next to this file. Copy the whole Bingoo Edge folder and try again.
  pause
  exit /b 1
)
net session >nul 2>&1
if %errorlevel% neq 0 (
  powershell.exe -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b 0
)
powershell.exe -NoProfile -ExecutionPolicy Bypass -STA -WindowStyle Minimized -File "%WIZ%" %*
exit /b %errorlevel%
