# Bingoo Print Agent - LOGON par khud chalu, APNE hi naam par.
#
# `install-service.ps1` se farq kyun:
#
#   Wo task SYSTEM ke naam par banta hai. SYSTEM ke paas apna Chrome profile
#   nahi hota, aur - ye zyada ahem hai - Windows me jo printer kisi USER ne
#   apne liye joda ho (network printer aksar aise hi jurte hain) wo SYSTEM ko
#   nazar nahi aata. Document printing printer ke NAAM se hoti hai, IP se
#   nahi, is liye wahan ye farq seedha nakami ban jata hai.
#
#   Thermal/network parchi (seedha IP:9100) par ye farq nahi parta - wahan
#   SYSTEM theek hai. Masla sirf A4/A5 document wale raaste ka hai.
#
# Is liye ye script task ko USI user ke naam par banati hai jo PC par baitha
# hai: printer bilkul wohi dikhte hain jo haath se chalate waqt dikhte the.
# Qeemat: koi log in na ho to agent nahi chalta - counter ke PC par ye theek
# hai, aur haqeeqat me abhi bhi yehi soorat hai.
#
# Chalane se PEHLE agent ki window band kar dein (warna exe ka naam nahi badlega).
# PowerShell "Run as Administrator" chahiye.

$ErrorActionPreference = 'Stop'

$dir = Join-Path $env:ProgramFiles 'BingooPrintAgent'
$exe = Join-Path $dir 'BingooPrintAgent.exe'

# Naam hamesha ek hi rakho. Download version-wala naam deta hai
# (BingooPrintAgent-2.6.1.exe) - agar task usi naam par bane to AGLI update
# task ko khamoshi se tod degi: task purani file dhoondta rahega jo ab hai hi
# nahi. Is liye yahan har baar sab se nayi exe ko ek hi naam par le aate hain.
if (-not (Test-Path $exe)) {
    $newest = Get-ChildItem $dir -Filter 'BingooPrintAgent*.exe' -ErrorAction SilentlyContinue |
              Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if (-not $newest) { throw "BingooPrintAgent ki koi exe '$dir' me nahi mili." }
    Move-Item $newest.FullName $exe
    Write-Host "Naam theek kiya: $($newest.Name) -> BingooPrintAgent.exe"
}

$task = 'BingooPrintAgent'

$action  = New-ScheduledTaskAction -Execute $exe -Argument 'run' -WorkingDirectory $dir
$trigger = New-ScheduledTaskTrigger -AtLogOn -User "$env:USERDOMAIN\$env:USERNAME"

# Mar jaye to khud uthe. Bijli jane ya net tootne par agent ka band reh jana
# wo soorat hai jis me malik ko pata hi nahi chalta - aur parchi nahi nikalti.
$settings = New-ScheduledTaskSettingsSet -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) `
            -StartWhenAvailable -DontStopIfGoingOnBatteries -AllowStartIfOnBatteries `
            -ExecutionTimeLimit (New-TimeSpan -Days 3650)

# RunLevel LIMITED - aur ye sab se ahem satar hai.
#
# 9 Oct ko yahan pehle 'Highest' likha tha, aur usi ne Kashif Kitchen par poora
# din kha liya. Chromium ELEVATED process se chalne par kaam karne se inkar kar
# deta hai: Chrome ek second me exit 0 de kar nikal jata, bina PDF banaye aur
# bina kisi shikayat ke. Edge ne bhi bilkul wohi kiya. Browser naye thay
# (Chrome 154, Edge 155), koi policy nahi thi, --user-data-dir bhi ja raha tha.
#
# Sabit aise hua: WOHI command, WOHI PC, browser khule hue - sirf aam (non-admin)
# PowerShell me - aur PDF foran ban gayi, 12,409 bytes.
#
# Is script ko chalane ke liye Administrator chahiye (task banana admin ka kaam
# hai), magar TASK khud aam darje par chalta hai. Dono alag cheezein hain, aur
# yehi farq poore document printing ko zinda rakhta hai.
#
# Agent ab khud bhi pehchanta hai: elevated chalne par shuru me warning deta hai
# aur document ki nakami par ise pehla sabab bana kar likhta hai.
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" `
             -LogonType Interactive -RunLevel Limited

try { Unregister-ScheduledTask -TaskName $task -Confirm:$false -ErrorAction Stop } catch { }
Register-ScheduledTask -TaskName $task -Action $action -Trigger $trigger -Settings $settings -Principal $principal | Out-Null
Start-ScheduledTask -TaskName $task

Write-Host ""
Write-Host "Ho gaya. Task '$task' ab har logon par agent chalu karega - aur gir jaye to 1 minute me dobara."
Write-Host "Band karne ko:  Unregister-ScheduledTask -TaskName $task -Confirm:`$false"
Write-Host "Halat dekhne ko: Get-ScheduledTask $task | Get-ScheduledTaskInfo"
