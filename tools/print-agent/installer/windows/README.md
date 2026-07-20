# Bingoo Print Agent — Windows Installer

## Customer install (one-click — the default download)

**Printing → Print Agents → Create Agent → Download Windows Agent** gives
`BingooPrintAgent-Setup.exe`. On the Counter/Kitchen PC:

1. Double-click **BingooPrintAgent-Setup.exe**. (Node.js is bundled inside — the
   customer installs nothing else.)
2. Wizard → paste the **Server URL** and the **6-digit pairing code** shown in
   Bingoo POS → Next.
3. It installs to `C:\Program Files\BingooPrintAgent`, pairs, and registers an
   auto-start scheduled task (survives reboots). Done.

Unsigned builds trigger Windows SmartScreen ("unrecognized app") — click
*More info → Run anyway*. A code-signing certificate removes this (ops backlog).

## Build the installer (maintainers)

Build machine prerequisites: Node 18+, and Inno Setup 6 (`winget install
JRSoftware.InnoSetup`).

```powershell
cd tools/print-agent
npx pkg print-agent.js --targets node18-win-x64 --output dist/BingooPrintAgent.exe
& "$env:LOCALAPPDATA\Programs\Inno Setup 6\ISCC.exe" installer/windows/BingooPrintAgent.iss
copy installer\windows\output\BingooPrintAgent-Setup.exe dist\BingooPrintAgent-Setup.exe
```

`dist/BingooPrintAgent-Setup.exe` is the committed artifact the download endpoint
serves. The 37 MB `dist/BingooPrintAgent.exe` is a build intermediate (bundled
into Setup.exe) and is git-ignored.

## Script / developer mode (fallback, needs Node.js)

If the wizard can't be used, the download falls back to a script ZIP:
`node print-agent.js setup` then `install-service.ps1`. Existing env-var/token
agents keep working unchanged.

The installed app:
- lives in `C:\Program Files\BingooPrintAgent`
- pairs on first run (`setup`), then auto-starts at boot via the
  `BingooPrintAgent` scheduled task (SYSTEM, restarts on failure)
- config: `C:\ProgramData\BingooPrintAgent\config.json`
- logs:   `C:\ProgramData\BingooPrintAgent\agent.log`

## Uninstall

```powershell
powershell -ExecutionPolicy Bypass -File installer\windows\uninstall-service.ps1        # keep config/logs
powershell -ExecutionPolicy Bypass -File installer\windows\uninstall-service.ps1 -Purge # remove everything
```

(The Inno Setup uninstaller runs this automatically.)
