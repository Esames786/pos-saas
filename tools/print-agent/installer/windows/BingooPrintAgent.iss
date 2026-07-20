; Bingoo Print Agent — one-click Windows installer wizard.
; Node.js is bundled inside BingooPrintAgent.exe (pkg) — the customer installs
; NOTHING else. The wizard: installs to Program Files, asks Server URL + pairing
; code on a custom page, pairs, and registers an auto-start scheduled task.
;
; Build:  "%LOCALAPPDATA%\Programs\Inno Setup 6\ISCC.exe" BingooPrintAgent.iss
; Prereq: dist\BingooPrintAgent.exe already built (npm run build:win / pkg).
; Unsigned builds trigger SmartScreen — sign OutputDir\BingooPrintAgent-Setup.exe
; with the code-signing certificate for a clean client experience.

[Setup]
AppName=Bingoo Print Agent
AppVersion=2.0.1
AppPublisher=Bingoo POS
DefaultDirName={commonpf}\BingooPrintAgent
DefaultGroupName=Bingoo Print Agent
DisableProgramGroupPage=yes
OutputBaseFilename=BingooPrintAgent-Setup
OutputDir=output
Compression=lzma2
SolidCompression=yes
PrivilegesRequired=admin
ArchitecturesInstallIn64BitMode=x64compatible
WizardStyle=modern

[Files]
Source: "..\..\dist\BingooPrintAgent.exe"; DestDir: "{app}"; Flags: ignoreversion
Source: "install-service.ps1";   DestDir: "{app}"; Flags: ignoreversion
Source: "uninstall-service.ps1"; DestDir: "{app}"; Flags: ignoreversion

[Dirs]
Name: "{commonappdata}\BingooPrintAgent"

[Icons]
Name: "{group}\Re-pair Bingoo Print Agent"; Filename: "{app}\BingooPrintAgent.exe"; Parameters: "setup"
Name: "{group}\Agent Status";               Filename: "{app}\BingooPrintAgent.exe"; Parameters: "status"

[Run]
; 1) Pair using the details from the custom wizard page (pair then exit).
Filename: "{app}\BingooPrintAgent.exe"; \
  Parameters: "setup --server ""{code:GetServerUrl}"" --code ""{code:GetPairingCode}"" --no-start"; \
  StatusMsg: "Pairing with Bingoo POS..."; Flags: runhidden waituntilterminated
; 2) Register + start the auto-start service (survives reboot).
Filename: "powershell.exe"; \
  Parameters: "-ExecutionPolicy Bypass -File ""{app}\install-service.ps1"""; \
  StatusMsg: "Setting up auto-start..."; Flags: runhidden waituntilterminated

[UninstallRun]
Filename: "powershell.exe"; Parameters: "-ExecutionPolicy Bypass -File ""{app}\uninstall-service.ps1"""; RunOnceId: "RemoveBpaTask"; Flags: runhidden

[Code]
var
  PairPage: TInputQueryWizardPage;

procedure InitializeWizard;
begin
  PairPage := CreateInputQueryPage(wpSelectDir,
    'Connect to Bingoo POS',
    'Enter the details shown in Bingoo POS',
    'Open Bingoo POS in your browser: Printing > Print Agents > Create Agent. ' +
    'Copy the Server URL and the 6-digit pairing code shown there.');
  PairPage.Add('Server URL (e.g. https://yourshop.bingoopos.com):', False);
  PairPage.Add('Pairing code (6 digits):', False);
end;

function GetServerUrl(Param: String): String;
begin
  Result := Trim(PairPage.Values[0]);
end;

function GetPairingCode(Param: String): String;
begin
  Result := Trim(PairPage.Values[1]);
end;

function NextButtonClick(CurPageID: Integer): Boolean;
begin
  Result := True;
  if CurPageID = PairPage.ID then
  begin
    if GetServerUrl('') = '' then
    begin
      MsgBox('Please enter the Server URL from Bingoo POS.', mbError, MB_OK);
      Result := False;
    end
    else if GetPairingCode('') = '' then
    begin
      MsgBox('Please enter the 6-digit pairing code.', mbError, MB_OK);
      Result := False;
    end;
  end;
end;
