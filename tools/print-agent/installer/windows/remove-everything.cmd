@echo off
rem Bingoo Print Agent - HAR version hatao: 2.7.0+ (per-user) aur 2.6.x (machine-wide, ek dafa admin).
rem
rem AGENT-UPGRADE-1 (11 Nov) - owner: "old agent remove kar ke new dalna ho to wo bhi easy ho ... koi script de do".
rem Aam taur par iski zaroorat NAHI: naya setup purana khud hata deta hai. Ye tab ke liye hai jab agent bilkul
rem hatana ho, ya kuch adhoora reh gaya ho. Pairing bhi jati hai - dobara lagane par naya code chahiye.
rem
rem Ye file install folder ke andar hoti hai, aur wohi folder mitti hai - is liye pehle khud ko TEMP me copy kar ke
rem wahan se chalti hai (warna chalti hui batch file beech me toot jati hai).

if /I not "%~dp0"=="%TEMP%\" (
    copy /Y "%~f0" "%TEMP%\bingoo-remove-everything.cmd" >nul
    "%TEMP%\bingoo-remove-everything.cmd"
    exit /b
)

echo.
echo Bingoo Print Agent hataya ja raha hai...
echo.

rem 1) Chowkidar ko roko, phir agent band.
if not exist "%LOCALAPPDATA%\BingooPrintAgent" mkdir "%LOCALAPPDATA%\BingooPrintAgent" >nul 2>&1
echo stop>"%LOCALAPPDATA%\BingooPrintAgent\stop.flag"
powershell -NoProfile -ExecutionPolicy Bypass -Command "Get-CimInstance Win32_Process -Filter \"Name='wscript.exe'\" | Where-Object { $_.CommandLine -like '*BingooPrintAgent*run-hidden.vbs*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }"
taskkill /F /IM BingooPrintAgent.exe >nul 2>&1

rem 2) Naya (per-user) - admin nahi.
if exist "%LOCALAPPDATA%\Programs\BingooPrintAgent\unins000.exe" (
    "%LOCALAPPDATA%\Programs\BingooPrintAgent\unins000.exe" /VERYSILENT /SUPPRESSMSGBOXES /NORESTART
)
reg delete "HKCU\Software\Microsoft\Windows\CurrentVersion\Run" /v BingooPrintAgent /f >nul 2>&1
rmdir /s /q "%LOCALAPPDATA%\Programs\BingooPrintAgent" >nul 2>&1
rmdir /s /q "%LOCALAPPDATA%\BingooPrintAgent" >nul 2>&1

rem 3) Purana (machine-wide, 2.6.x) - sirf mile to, aur tab Windows EK dafa admin maangega.
set OLD=0
if exist "%ProgramFiles%\BingooPrintAgent" set OLD=1
if exist "%ProgramData%\BingooPrintAgent" set OLD=1
schtasks /Query /TN BingooPrintAgent >nul 2>&1 && set OLD=1
if "%OLD%"=="1" (
    echo Purana agent bhi mila - Windows ek dafa admin ki ijazat maangega...
    powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process powershell -Verb RunAs -Wait -WindowStyle Hidden -ArgumentList '-NoProfile','-ExecutionPolicy','Bypass','-Command','$u = Join-Path $env:ProgramFiles ''BingooPrintAgent\unins000.exe''; if (Test-Path $u) { Start-Process $u -ArgumentList ''/VERYSILENT'',''/SUPPRESSMSGBOXES'',''/NORESTART'' -Wait }; Stop-ScheduledTask -TaskName BingooPrintAgent -ErrorAction SilentlyContinue; Unregister-ScheduledTask -TaskName BingooPrintAgent -Confirm:$false -ErrorAction SilentlyContinue; Remove-Item -Recurse -Force (Join-Path $env:ProgramFiles ''BingooPrintAgent''), (Join-Path $env:ProgramData ''BingooPrintAgent'') -ErrorAction SilentlyContinue'"
)

echo.
echo Ho gaya. Bingoo Print Agent is PC se hat gaya.
echo Dobara lagana ho to: Bingoo ^> Printing ^> Print Agents ^> Create Agent ^> Download.
echo.
pause
