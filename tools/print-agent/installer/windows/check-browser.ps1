# Bingoo Print Agent - browser ki jaanch. Kuch install nahi karti, kuch badalti nahi.
#
# .NET Process istemal hota hai, Start-Process nahi: agent Node ke execFile se
# chalata hai jo child ke MARNE ka intezar karta hai aur us ki stderr parhta
# hai. Start-Process -Wait wo nahi karta - us se ye jaanch chalte hue browser
# ko bhi "BANI NAHI" keh deti thi, yani jawab hi ghalat.
$ErrorActionPreference = 'SilentlyContinue'

$tmp = Join-Path $env:TEMP ('bpa-diag-' + (Get-Date -Format 'HHmmss'))
New-Item -ItemType Directory -Force $tmp | Out-Null
$html = Join-Path $tmp 'test.html'
'<!DOCTYPE html><html><head><style>@page{size:A5 portrait;margin:8mm}</style></head><body><h1>BINGOO TEST</h1></body></html>' | Set-Content $html -Encoding UTF8
$fileUrl = 'file:///' + ($html -replace '\', '/')

function Try-Browser($exe, $cargs, $pdf) {
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = $exe
    $psi.Arguments = ($cargs | ForEach-Object { '"' + $_ + '"' }) -join ' '
    $psi.UseShellExecute = $false
    $psi.RedirectStandardError = $true
    $psi.RedirectStandardOutput = $true
    $psi.CreateNoWindow = $true

    $p = [System.Diagnostics.Process]::Start($psi)
    $err = $p.StandardError.ReadToEnd()
    $null = $p.StandardOutput.ReadToEnd()
    if (-not $p.WaitForExit(90000)) { try { $p.Kill() } catch { }; return @{ exit = 'TIMEOUT'; err = $err } }

    return @{ exit = $p.ExitCode; err = $err }
}

Write-Host ''
Write-Host ('Khule hue browser:  chrome=' + @(Get-Process chrome).Count + '   msedge=' + @(Get-Process msedge).Count)
Write-Host ''
Write-Host ('{0,-12} {1,-18} {2,-15} {3,-9} {4}' -f 'BROWSER','VERSION','FLAG','EXIT','PDF')
Write-Host ('-' * 74)

$list = @(
    @{ n = 'Chrome';     p = "$env:ProgramFiles\Google\Chrome\Application\chrome.exe" },
    @{ n = 'Chrome x86'; p = "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe" },
    @{ n = 'Edge';       p = "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe" },
    @{ n = 'Edge x86';   p = "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe" }
)

$notes = @()
foreach ($b in $list) {
    if (-not (Test-Path $b.p)) { Write-Host ('{0,-12} NAHI HAI' -f $b.n); continue }

    # Version FILE se, command se nahi: Windows par chrome.exe --version
    # bharosemand nahi - GUI app hai, console se judta hi nahi. Isi liye asli
    # error me "Opening in existing browser session" aaya tha, version nahi.
    $ver = (Get-Item $b.p).VersionInfo.ProductVersion

    foreach ($flag in @('--headless=new', '--headless')) {
        $tag  = ($b.n -replace ' ', '') + ($flag -replace '[^a-z]', '')
        $prof = Join-Path $tmp ('prof-' + $tag)
        $pdf  = Join-Path $tmp ($tag + '.pdf')
        $cargs = @($flag, '--disable-gpu', '--no-sandbox', "--user-data-dir=$prof",
                   '--disable-extensions', '--disable-background-networking', '--no-first-run',
                   '--no-pdf-header-footer', '--print-background', "--print-to-pdf=$pdf", $fileUrl)

        $r = Try-Browser $b.p $cargs $pdf
        $made = (Test-Path $pdf) -and ((Get-Item $pdf).Length -gt 0)
        $size = if ($made) { [string](Get-Item $pdf).Length + ' bytes  OK' } else { 'BANI NAHI' }

        Write-Host ('{0,-12} {1,-18} {2,-15} {3,-9} {4}' -f $b.n, $ver, $flag, $r.exit, $size)
        if ($r.err -and -not $made) { $notes += ($b.n + ' ' + $flag + ': ' + ($r.err -replace '\s+', ' ').Trim()) }
        $ver = ''
    }
}

if ($notes.Count -gt 0) {
    Write-Host ''
    Write-Host 'Browser ki apni shikayat:'
    $notes | Select-Object -First 6 | ForEach-Object { Write-Host ('  ' + $_.Substring(0, [Math]::Min(300, $_.Length))) }
}

Write-Host ''
Remove-Item $tmp -Recurse -Force
Write-Host 'Jis satar par "OK" hai, wo browser kaam karta hai.'
