; Bingoo Print Agent — Inno Setup script (one-click Windows installer)
; Build prerequisites (NOT bundled in this repo):
;   1. pkg-built binary:  npm run build:win  → dist/BingooPrintAgent.exe
;   2. Inno Setup 6 (iscc.exe on PATH)
; Build: iscc BingooPrintAgent.iss
; NOTE: unsigned builds trigger Windows SmartScreen — code-signing certificate
; required for a clean client experience (see installer/windows/README.md).

[Setup]
AppName=Bingoo Print Agent
AppVersion=2.0.0
AppPublisher=Bingoo POS
DefaultDirName={commonpf}\BingooPrintAgent
DefaultGroupName=Bingoo Print Agent
OutputBaseFilename=BingooPrintAgent-Setup
OutputDir=output
Compression=lzma2
SolidCompression=yes
PrivilegesRequired=admin
ArchitecturesInstallIn64BitMode=x64compatible

[Files]
Source: "..\..\dist\BingooPrintAgent.exe"; DestDir: "{app}"; Flags: ignoreversion
Source: "install-service.ps1";  DestDir: "{app}"; Flags: ignoreversion
Source: "uninstall-service.ps1"; DestDir: "{app}"; Flags: ignoreversion

[Dirs]
Name: "{commonappdata}\BingooPrintAgent"

[Icons]
Name: "{group}\Bingoo Print Agent Setup (Pair)"; Filename: "{app}\BingooPrintAgent.exe"; Parameters: "setup"
Name: "{group}\Agent Status"; Filename: "{app}\BingooPrintAgent.exe"; Parameters: "status"

[Run]
; First run = interactive pairing in a console window, then service install.
Filename: "{app}\BingooPrintAgent.exe"; Parameters: "setup"; Description: "Pair with Bingoo POS now"; Flags: postinstall nowait skipifsilent
Filename: "powershell.exe"; Parameters: "-ExecutionPolicy Bypass -File ""{app}\install-service.ps1"""; Description: "Install auto-start service"; Flags: postinstall runhidden

[UninstallRun]
Filename: "powershell.exe"; Parameters: "-ExecutionPolicy Bypass -File ""{app}\uninstall-service.ps1"""; RunOnceId: "RemoveBpaTask"; Flags: runhidden
