; Bingoo Print Agent — one-click Windows installer (2.7.0+: PER-USER, koi admin nahi).
; Node.js is bundled inside BingooPrintAgent.exe (pkg) — the customer installs NOTHING else.
;
; Build:  "%LOCALAPPDATA%\Programs\Inno Setup 6\ISCC.exe" BingooPrintAgent.iss
; Prereq: dist\BingooPrintAgent.exe already built (npm run build:win / pkg).
; Unsigned builds trigger SmartScreen — sign OutputDir\BingooPrintAgent-Setup.exe for a clean client experience.
;
; ⚠️ AppVersion KO print-agent.js KE `AGENT_VERSION` KE BARAABAR RAKHO (4 Oct: setup ke sar par "2.5.0" aur screen
; par "2.6.0" — ek badla, doosra reh gaya). `tools/print-agent/test/version-match-test.js` dono ko milata hai.
;
; ── AGENT-UPGRADE-1 (2.7.0, 11 Nov) — owner: "bas ek click pe sab; zero se setup karna ho ya old agent remove kar ke
;    new dalna ho to wo bhi easy ho; admin / super admin permission ka masla na aaye" ──────────────────────────────────
;
;  1. PER-USER (`PrivilegesRequired=lowest`): `%LOCALAPPDATA%\Programs\BingooPrintAgent`. Windows ka admin popup KABHI
;     nahi — install me bhi, update me bhi. Auto-start `HKCU\...\Run` se (admin ki zaroorat nahi), `run-hidden.vbs`
;     chowkidar ke saath: koi window nahi, gir jaye to dobara.
;
;  2. PAIRING BACHI RAHE: config pehle se ho (naya ya 2.6.x ka `ProgramData` wala) to pairing ka safha AATA HI NAHI —
;     wohi agent, wohi token. Pehle har install naya code maangta tha = naya agent record, purane ka token bekaar
;     (Kashif Food ka "401 wala doosra agent"). Code sirf tab: pehli dafa, ya Bingoo se "code already filled in" wali
;     file (file ke NAAM me server + code: `BingooPrintAgent-Setup-2.7.0~host~123456.exe`), ya `/SERVER= /CODE=`.
;
;  3. PURANA (2.6.x, Program Files) MILE: pehle us ki pairing naye ghar me copy, phir us ka apna uninstaller EK DAFA
;     admin (UAC) se, chupke. Ijazat na mile ya purana na hate to install WAHIN RUKTA hai — do agent ek saath = ek
;     parchi do dafa. Ye wohi ek admin hai jo owner ko pehle se bataya gaya.
;
;  4. KHUD CHALE, AUR SABIT HO (owner: "thermal install ke baad tum script se haath se agent on karwa rahe the"):
;     install ke baad agent chalaya jata hai aur DO dafa dekha jata hai ke chal raha hai; aakhri safha natija saaf
;     batata hai — pairing nakaam, ya log ka raasta. Upgrade se pehle `stop.flag` + purane process band, taake exe
;     badalte waqt chowkidar use dobara na utha le.
;
;  5. Hatana: Windows › Apps › Uninstall (admin nahi) — process, Run value, files, config, `ready\` sab. Har version
;     (purana bhi) ek saath: Start Menu › "Remove Bingoo Print Agent completely" (`remove-everything.cmd`).

[Setup]
; NAYA AppId — 2.6.x machine-wide tha (AppId = AppName). Per-user ka apna; purana [Code] me dhoond kar hataya jata hai.
AppId={{2E8B4C6A-9D3F-4A71-B5E2-7C1D0F9A3B64}
AppName=Bingoo Print Agent
AppVersion=2.7.0
AppPublisher=Bingoo POS
DefaultDirName={localappdata}\Programs\BingooPrintAgent
DisableDirPage=yes
UsePreviousAppDir=no
DefaultGroupName=Bingoo Print Agent
DisableProgramGroupPage=yes
OutputBaseFilename=BingooPrintAgent-Setup
OutputDir=output
Compression=lzma2
SolidCompression=yes
PrivilegesRequired=lowest
ArchitecturesInstallIn64BitMode=x64compatible
WizardStyle=modern
CloseApplications=no
SetupLogging=yes
UninstallDisplayName=Bingoo Print Agent
UninstallDisplayIcon={app}\BingooPrintAgent.exe

[Files]
Source: "..\..\dist\BingooPrintAgent.exe"; DestDir: "{app}"; Flags: ignoreversion
; SumatraPDF - PDF ko printer tak pahunchane ke liye (Windows me command line se PDF chhapne ka apna tareeqa nahi;
; "printto" verb sirf PDF reader darj karta hai, Edge/Chrome nahi). Alag program, bina badla, GPLv3 - THIRD-PARTY-NOTICE.
Source: "SumatraPDF.exe";           DestDir: "{app}"; Flags: ignoreversion
Source: "THIRD-PARTY-NOTICE.txt";   DestDir: "{app}"; Flags: ignoreversion
; run-hidden.vbs - agent BINA WINDOW ke + chowkidar (gire to dobara). Dekho us file ka note.
Source: "run-hidden.vbs";           DestDir: "{app}"; Flags: ignoreversion
Source: "remove-everything.cmd";    DestDir: "{app}"; Flags: ignoreversion

[Registry]
; Logon par khud chale — apne user ka Run, admin nahi. (2.6.x me ye admin wala scheduled task tha.)
Root: HKCU; Subkey: "Software\Microsoft\Windows\CurrentVersion\Run"; ValueType: string; ValueName: "BingooPrintAgent"; ValueData: """{sys}\wscript.exe"" //B //Nologo ""{app}\run-hidden.vbs"""; Flags: uninsdeletevalue

[Icons]
Name: "{group}\Re-pair Bingoo Print Agent"; Filename: "{app}\BingooPrintAgent.exe"; Parameters: "setup"
Name: "{group}\Agent Status";               Filename: "{app}\BingooPrintAgent.exe"; Parameters: "status"
Name: "{group}\Remove Bingoo Print Agent completely"; Filename: "{app}\remove-everything.cmd"

[UninstallDelete]
; Config, log, lock, `ready\` PDF, stop.flag — sab. (Upgrade = uninstall nahi; is liye update par pairing bachi rehti hai.)
Type: filesandordirs; Name: "{localappdata}\BingooPrintAgent"
Type: filesandordirs; Name: "{app}"

[Code]
var
  PairPage: TInputQueryWizardPage;
  PrefillServer, PrefillCode: String;
  PairFailed, AgentStarted: Boolean;

function AgentDataDir(): String;
begin
  Result := ExpandConstant('{localappdata}\BingooPrintAgent');
end;

function NewConfigPath(): String;
begin
  Result := AgentDataDir() + '\config.json';
end;

function LegacyConfigPath(): String;
begin
  Result := ExpandConstant('{commonappdata}\BingooPrintAgent\config.json');
end;

function IsDigits(S: String): Boolean;
var
  I: Integer;
begin
  Result := Length(S) > 0;
  for I := 1 to Length(S) do
    if (S[I] < '0') or (S[I] > '9') then
    begin
      Result := False;
      Exit;
    end;
end;

{ Server + code: pehle /SERVER= /CODE=, warna file ka naam "BingooPrintAgent-Setup-2.7.0~host~123456.exe"
  (browser " (1)" jore to bhi — code sirf pehle 6 adad). }
procedure ReadPrefill();
var
  Name, Rest, Host, Code: String;
  P: Integer;
begin
  PrefillServer := ExpandConstant('{param:SERVER|}');
  PrefillCode := ExpandConstant('{param:CODE|}');
  if (PrefillServer <> '') and (PrefillCode <> '') then
    Exit;
  PrefillServer := '';
  PrefillCode := '';

  Name := ExtractFileName(ExpandConstant('{srcexe}'));
  P := Pos('~', Name);
  if P = 0 then
    Exit;
  Rest := Copy(Name, P + 1, Length(Name));
  P := Pos('~', Rest);
  if P = 0 then
    Exit;
  Host := Copy(Rest, 1, P - 1);
  Code := Copy(Rest, P + 1, 6);
  if (Host = '') or (Length(Code) <> 6) or (not IsDigits(Code)) then
    Exit;

  if (Pos('.test', Host) > 0) or (Pos('localhost', Host) = 1) or (Pos('127.0.0.1', Host) = 1) then
    PrefillServer := 'http://' + Host
  else
    PrefillServer := 'https://' + Host;
  PrefillCode := Code;
end;

function HasPrefill(): Boolean;
begin
  Result := (PrefillServer <> '') and (PrefillCode <> '');
end;

{ Pairing tab hi jab koi config na ho — naya ya purana. Pehle se paired PC par code maangna = naya agent record. }
function HasConfig(): Boolean;
begin
  Result := FileExists(NewConfigPath()) or FileExists(LegacyConfigPath());
end;

procedure InitializeWizard;
begin
  ReadPrefill();
  PairPage := CreateInputQueryPage(wpWelcome,
    'Connect to Bingoo POS',
    'Enter the details shown in Bingoo POS',
    'Open Bingoo POS in your browser: Printing > Print Agents > Create Agent. ' +
    'Copy the Server URL and the 6-digit pairing code shown there.');
  PairPage.Add('Server URL (e.g. https://yourshop.bingoopos.com):', False);
  PairPage.Add('Pairing code (6 digits):', False);
  PairPage.Values[0] := PrefillServer;
  PairPage.Values[1] := PrefillCode;
end;

function ShouldSkipPage(PageID: Integer): Boolean;
begin
  Result := False;
  if PageID = PairPage.ID then
    Result := HasPrefill() or HasConfig();
end;

function GetServerUrl(): String;
begin
  Result := Trim(PairPage.Values[0]);
end;

function GetPairingCode(): String;
begin
  Result := Trim(PairPage.Values[1]);
end;

function NextButtonClick(CurPageID: Integer): Boolean;
begin
  Result := True;
  if CurPageID = PairPage.ID then
  begin
    if GetServerUrl() = '' then
    begin
      MsgBox('Please enter the Server URL from Bingoo POS.', mbError, MB_OK);
      Result := False;
    end
    else if GetPairingCode() = '' then
    begin
      MsgBox('Please enter the 6-digit pairing code.', mbError, MB_OK);
      Result := False;
    end;
  end;
end;

function AgentRunning(): Boolean;
var
  RC: Integer;
begin
  Exec(ExpandConstant('{cmd}'), '/C tasklist /FI "IMAGENAME eq BingooPrintAgent.exe" | find /I "BingooPrintAgent.exe" >nul',
    '', SW_HIDE, ewWaitUntilTerminated, RC);
  Result := RC = 0;
end;

{ Chowkidar ko roko (stop.flag) aur chalte agent band karo — exe badalte waqt koi use dobara na uthaye. Apne user ke
  process admin ke baghair band hote hain (2.6.6 ka task bhi console user ke naam par chalta tha). }
procedure StopAgents();
var
  RC: Integer;
begin
  ForceDirectories(AgentDataDir());
  SaveStringToFile(AgentDataDir() + '\stop.flag', 'stop', False);
  Exec(ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe'),
    '-NoProfile -ExecutionPolicy Bypass -Command "Get-CimInstance Win32_Process -Filter \"Name=''wscript.exe''\" | ' +
    'Where-Object { $_.CommandLine -like ''*BingooPrintAgent*run-hidden.vbs*'' } | ' +
    'ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }"',
    '', SW_HIDE, ewWaitUntilTerminated, RC);
  Exec(ExpandConstant('{sys}\taskkill.exe'), '/F /IM BingooPrintAgent.exe', '', SW_HIDE, ewWaitUntilTerminated, RC);
  Sleep(1500);
end;

{ 2.6.x machine-wide install ka uninstaller (AppId = "Bingoo Print Agent"). }
function OldUninstaller(var Path: String): Boolean;
var
  S, Key: String;
begin
  Result := False;
  Key := 'SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\Bingoo Print Agent_is1';
  S := '';
  if IsWin64() then
    RegQueryStringValue(HKLM64, Key, 'UninstallString', S);
  if S = '' then
    RegQueryStringValue(HKLM32, Key, 'UninstallString', S);
  if S <> '' then
  begin
    Path := RemoveQuotes(S);
    Result := FileExists(Path);
  end;
end;

function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  OldUninst: String;
  RC: Integer;
begin
  Result := '';
  StopAgents();

  { Purani pairing naye ghar me — purana hatane se PEHLE. }
  if FileExists(LegacyConfigPath()) and (not FileExists(NewConfigPath())) then
    FileCopy(LegacyConfigPath(), NewConfigPath(), False);

  if OldUninstaller(OldUninst) then
  begin
    Log('Purana machine-wide agent mila: ' + OldUninst);
    if not WizardSilent() then
      MsgBox('Purana Bingoo Print Agent (2.6 ya pehle) is PC par mila. Use hataane ke liye Windows ek dafa ' +
        'ijazat (admin) maangega. Ye sirf is ek dafa hai - aage kabhi nahi. Pairing wohi rahegi.', mbInformation, MB_OK);
    if not ShellExec('runas', OldUninst, '/VERYSILENT /SUPPRESSMSGBOXES /NORESTART', '', SW_HIDE, ewWaitUntilTerminated, RC) then
    begin
      Result := 'Purana agent hataya nahi ja saka (admin ki ijazat nahi mili). Do agent ek saath nahi chal sakte - ' +
        'warna parchi do dafa chhapegi - is liye install roka gaya. Admin ke saath isi file ko dobara chalayein.';
      Exit;
    end;
    Sleep(2000);
    if OldUninstaller(OldUninst) then
    begin
      Result := 'Purana agent abhi bhi mojood hai. Do agent ek saath nahi chal sakte - is liye install roka gaya.';
      Exit;
    end;
    StopAgents();
  end;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  RC, I: Integer;
begin
  if CurStep <> ssPostInstall then
    Exit;

  DeleteFile(AgentDataDir() + '\stop.flag');

  { Pairing: Bingoo ki "code already filled in" file / params, ya pehli dafa ka safha. Paired PC par nahi. }
  if HasPrefill() or (not HasConfig()) then
  begin
    WizardForm.StatusLabel.Caption := 'Pairing with Bingoo POS...';
    Exec(ExpandConstant('{app}\BingooPrintAgent.exe'),
      'setup --server "' + GetServerUrl() + '" --code "' + GetPairingCode() + '" --no-start',
      '', SW_HIDE, ewWaitUntilTerminated, RC);
    PairFailed := RC <> 0;
    Log('Pairing exit code: ' + IntToStr(RC));
  end;

  WizardForm.StatusLabel.Caption := 'Starting the agent...';
  Exec(ExpandConstant('{sys}\wscript.exe'), '//B //Nologo "' + ExpandConstant('{app}\run-hidden.vbs') + '"',
    '', SW_HIDE, ewNoWait, RC);

  { Tasdeeq DO dafa: pair na hua agent ek lamhe chal kar band (exit 4) ho jata hai — pehli jhalak kaafi nahi. }
  AgentStarted := False;
  for I := 1 to 24 do
  begin
    Sleep(500);
    if AgentRunning() then
    begin
      Sleep(3000);
      AgentStarted := AgentRunning();
      Break;
    end;
  end;
  Log('Agent running after install: ' + IntToStr(Ord(AgentStarted)));
end;

procedure CurPageChanged(CurPageID: Integer);
begin
  if CurPageID <> wpFinished then
    Exit;
  if AgentStarted then
    WizardForm.FinishedLabel.Caption :=
      'Bingoo Print Agent chal raha hai. Bingoo > Printing > Print Agents par ye PC "Online" dikhega. ' +
      'Windows restart / logon par ye khud chalega - kuch aur nahi karna.'
  else if PairFailed then
    WizardForm.FinishedLabel.Caption :=
      'Agent install ho gaya magar PAIRING NAHI HUI - code ghalat tha ya 15 minute se purana. ' +
      'Bingoo > Printing > Print Agents se naya code le kar isi file ko dobara chalayein.'
  else
    WizardForm.FinishedLabel.Caption :=
      'Agent install ho gaya magar CHALTA NAZAR NAHI AAYA. Wajah is file me hai: ' +
      ExpandConstant('{localappdata}\BingooPrintAgent\agent.log');
end;

procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
begin
  if CurUninstallStep = usUninstall then
    StopAgents();
end;
