# Bingoo Print Agent — Windows Installer

Two install paths. Path A works today with no external tooling; Path B is the
one-click `.exe` once the build/signing pipeline runs.

## Path A — Script install (works today)

Prerequisite on the shop PC: [Node.js 18+](https://nodejs.org) (LTS installer, next-next-finish).

1. In Bingoo POS: **Printing → Print Agents → Download Windows Agent** → unzip
   anywhere (e.g. `C:\BingooPrintAgent`).
2. Open the folder, hold Shift + right-click → "Open PowerShell window here":
   ```powershell
   node print-agent.js setup
   ```
3. Enter the **Server URL** (pre-filled from SERVER.txt in the zip) and the
   **6-digit pairing code** from the Print Agents screen. The agent pairs and
   starts printing immediately.
4. Make it survive reboots (run PowerShell **as Administrator**):
   ```powershell
   powershell -ExecutionPolicy Bypass -File installer\windows\install-service.ps1
   ```

## Path B — One-click .exe (build pipeline)

Build machine prerequisites: Node 18+, `npm i -g pkg`, Inno Setup 6.

```powershell
cd tools/print-agent
npm run build:win          # pkg → dist/BingooPrintAgent.exe
cd installer/windows
iscc BingooPrintAgent.iss  # → output/BingooPrintAgent-Setup.exe
```

Then sign `BingooPrintAgent-Setup.exe` with the code-signing certificate
(unsigned builds trigger SmartScreen "unrecognized app" warnings — clients can
still proceed via *More info → Run anyway*, but signing is required for a clean
experience).

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
