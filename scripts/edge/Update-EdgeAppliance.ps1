# P4 WINDOWS APPLIANCE - apply a SIGNED update package to an installed Branch Server (P4 section 9).
#
# Sequence: verify the new package (hashes) -> cooperative drain/stop of the services -> `edge:local:update`
# (Ed25519 signature, tamper check, product/version/schema/target checks, PRE-UPDATE BACKUP, atomic stage into
# runtime\versions\<to> + `current` pointer switch, forward-only schema upgrade, rollback of the pointer on failure;
# the outbox and every local table are preserved) -> start services -> health. Never run against a LIVE branch
# while it is LOCAL_ACTIVE with an open shift: hand back first (the health report shows the authority state).
#
# Usage (elevated):
#   .\Update-EdgeAppliance.ps1 -InstallRoot "C:\Program Files\Bingoo Edge" -PackageRoot D:\pkg\BingooEdge-0.2.0
#
# EDGE 0.9 (setup wizard, update mode): -ReRegisterServices re-registers the tasks from the NEW plan (so an appliance updated
# from 0.8.x gains the BingooEdgeUpdater task and the Win10-safe repetition), the installed scripts\ are refreshed from the
# package (Register-EdgeServices, wizard, ...), and the auto-update work dir + runtime rights for the service account are put
# in place when elevated. Health evaluation and the K6 rollback rule are the wizard's job (it compares health before/after).
param(
    [string]$InstallRoot = 'C:\Program Files\Bingoo Edge',
    [Parameter(Mandatory = $true)][string]$PackageRoot,
    [string]$UpdateFile,
    [switch]$NoServices,
    [switch]$NoGateway,
    [switch]$AllowLocalActive,
    [string]$ExpectedPlanHash,
    [switch]$ReRegisterServices,
    [string]$ServiceAccount = 'NT AUTHORITY\LOCAL SERVICE'
)
$ErrorActionPreference = 'Stop'
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
function Fail($text) { Write-Host "UPDATE FAILED: $text" -ForegroundColor Red; exit 1 }

$layout = Get-Content -Path (Join-Path $InstallRoot 'appliance.json') -Raw | ConvertFrom-Json
$php = [string]$layout.php
$launcher = Join-Path $InstallRoot 'artisan'
$planFile = Join-Path ([string]$layout.data_root) 'gateway\service-plan.json'

# EDGE 0.9 CP6 - the authority/heartbeat worker keeps the Cloud lease alive (TTL 120 s). It is NOT stopped with the other
# services: it keeps heartbeating through the verify, dry-run, pre-update backup, staging and schema upgrade, and is stopped
# only right before the new runtime's services start. Otherwise the Cloud fences the branch's Online POS once the update
# takes longer than the lease (CP6 Windows Sandbox, 0.8.5 -> 0.9.0 through the wizard: 136 s without a heartbeat, Online POS
# paused ~16 s; on a slow disk the 0.8.x staging re-verify alone takes ~5 min). A 0.8.x `edge:local:update` (and a 0.9 one
# run by hand) never stops workers itself, and the CP6 LAB-copy rehearsal ran the whole 0.8.2 -> 0.9.0 update, schema
# upgrade included, with every old worker running: no lapse, no duplicate, no harm.
function Get-EdgeAuthorityTaskName([string]$PlanPath) {
    if (-not (Test-Path $PlanPath)) { return $null }
    $plan = Get-Content -Path $PlanPath -Raw | ConvertFrom-Json
    $t = @($plan.tasks | Where-Object { [string]$_.artisan_command -eq 'edge:local:authority-worker' -or [string]$_.arguments -match 'edge:local:authority-worker' }) | Select-Object -First 1
    if ($t) { return [string]$t.name }
    return $null
}
$authorityTask = Get-EdgeAuthorityTaskName $planFile
# Failure paths: start what step 3 stopped, with the old plan. The authority task was never stopped (IgnoreNew would make a
# second Start a no-op anyway); start it only if it is not running.
function Restore-EdgeServices {
    if ($NoServices -or -not (Test-Path $planFile)) { return }
    $restoreArgs = @{ Action = 'Start'; PlanFile = $planFile }
    if ($NoGateway) { $restoreArgs['NoGateway'] = $true }
    if ($authorityTask) { $restoreArgs['Exclude'] = @($authorityTask) }
    & (Join-Path $scriptDir 'Register-EdgeServices.ps1') @restoreArgs
    if ($authorityTask) {
        $st = Get-ScheduledTask -TaskName $authorityTask -ErrorAction SilentlyContinue
        if ($st -and $st.State -ne 'Running') { Start-ScheduledTask -TaskName $authorityTask -ErrorAction SilentlyContinue }
    }
}
# Right before the new runtime's services start: cooperative stop (the worker records a graceful stop), then the task itself.
function Stop-EdgeAuthorityWorker {
    if ($NoServices -or -not $authorityTask) { return }
    $eap = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $null = & $php $launcher edge:local:authority-worker --stop 2>&1
        if ($LASTEXITCODE -ne 0) { Write-Warning 'Authority worker did not confirm a graceful stop - its task is stopped instead.' }
    } catch {
        Write-Warning ('Cooperative authority stop failed (the task is stopped anyway): ' + $_.Exception.Message)
    } finally {
        $ErrorActionPreference = $eap
    }
    Stop-ScheduledTask -TaskName $authorityTask -ErrorAction SilentlyContinue
    $deadline = (Get-Date).AddSeconds(30)
    do {
        $st = Get-ScheduledTask -TaskName $authorityTask -ErrorAction SilentlyContinue
        if (-not $st -or $st.State -ne 'Running') { break }
        Start-Sleep -Milliseconds 500
    } while ((Get-Date) -lt $deadline)
}

# 1. verify the new package
$manifestPath = Join-Path $PackageRoot 'package-manifest.json'
if (-not (Test-Path $manifestPath)) { Fail 'package-manifest.json not found' }
$manifest = Get-Content -Path $manifestPath -Raw | ConvertFrom-Json
$bad = 0
foreach ($prop in $manifest.files.PSObject.Properties) {
    $rel = $prop.Name
    if ($rel -like 'app/vendor/*') { continue }
    $full = Join-Path $PackageRoot ($rel -replace '/', '\')
    if (-not (Test-Path $full)) { Write-Host "  MISSING  $rel"; $bad++; continue }
    if ((Get-FileHash -Path $full -Algorithm SHA256).Hash.ToLower() -ne ([string]$prop.Value).ToLower()) { Write-Host "  TAMPERED $rel"; $bad++ }
}
if ($bad -gt 0) { Fail "$bad file(s) missing or tampered" }
if (-not $UpdateFile) {
    $candidates = Get-ChildItem -Path (Join-Path $PackageRoot 'update') -Filter 'edge-update-*.json' -ErrorAction SilentlyContinue
    if (-not $candidates) { Fail 'the package carries no signed update file (update\edge-update-<version>.json)' }
    $UpdateFile = $candidates[0].FullName
}
Write-Host "Package OK: Bingoo Edge $($manifest.edge_app_version); update file $UpdateFile"

# 2. authority guard
$healthJson = & $php $launcher edge:local:health --json
$health = ($healthJson -join "`n") | ConvertFrom-Json
if ($health.authority.state -eq 'local_active' -and -not $AllowLocalActive) {
    Fail 'the appliance is LOCAL_ACTIVE (serving the branch). Hand back to the Cloud first, or pass -AllowLocalActive for a deliberate in-place update.'
}

# 3. drain / stop - every service EXCEPT the authority/heartbeat worker (see Get-EdgeAuthorityTaskName above)
if (-not $NoServices -and (Test-Path $planFile)) {
    $stopArgs = @{ Action = 'Stop'; PlanFile = $planFile }
    if ($NoGateway) { $stopArgs['NoGateway'] = $true }
    if ($authorityTask) { $stopArgs['Exclude'] = @($authorityTask) }
    & (Join-Path $scriptDir 'Register-EdgeServices.ps1') @stopArgs
}

# 4a. OWNER DECISION D7 - PRE-INSTALL VERDICT: verify the package and print the EXACT migration plan the new runtime
#     would execute (classification + decision per pending migration, plan hash) BEFORE anything is applied.
#     Pass -ExpectedPlanHash (the plan_hash of the approved pre-install report) for a LAB/production update: the
#     update STOPS when the actual plan differs. Without it the plan printed here is self-confirmed (dev/test only).
$dryLines = & $php $launcher edge:local:update $UpdateFile (Join-Path $PackageRoot 'app') --dry-run --json --no-interaction
$dryCode = $LASTEXITCODE
$dryJson = ($dryLines | Where-Object { $_ -is [string] -and $_.TrimStart().StartsWith('{') } | Select-Object -Last 1)
if ($dryCode -ne 0 -or -not $dryJson) {
    Restore-EdgeServices
    Write-Host ($dryLines -join "`n")
    Fail 'pre-install dry-run refused (package verification or migration plan; see the message above)'
}
$verdict = $dryJson | ConvertFrom-Json
Write-Host "Migration plan ($($verdict.plan.format), manifest $($verdict.plan.manifest_version)): run $($verdict.plan.run.Count), skip $($verdict.plan.skip.Count), blockers $($verdict.plan.blockers.Count)"
foreach ($e in $verdict.plan.entries) { Write-Host ("  {0,-18} {1,-18} {2}" -f $e.decision, $e.classification, $e.migration) }
Write-Host "PLAN_HASH=$($verdict.plan_hash)   verdict=$($verdict.verdict)"
if ($verdict.verdict -ne 'OK') {
    Restore-EdgeServices
    Fail 'RELEASE BLOCKER - the new runtime carries an unclassified tenant migration'
}
if ($ExpectedPlanHash) {
    if ($ExpectedPlanHash -ne [string]$verdict.plan_hash) {
        Restore-EdgeServices
        Fail "the actual migration plan [$($verdict.plan_hash)] differs from the approved pre-install report [$ExpectedPlanHash] - update stopped"
    }
} else {
    $ExpectedPlanHash = [string]$verdict.plan_hash
    if ($verdict.plan.entries.Count -gt 0) { Write-Host 'WARNING: no -ExpectedPlanHash given - the plan above is self-confirmed (acceptable for dev/test only; a LAB update passes the approved report hash).' -ForegroundColor Yellow }
}

# 4b. apply (signature -> tamper -> version/schema/target -> pre-update backup -> stage -> PLAN CHECK -> switch -> schema upgrade)
& $php $launcher edge:local:update $UpdateFile (Join-Path $PackageRoot 'app') "--expected-plan-hash=$ExpectedPlanHash" --no-interaction
$code = $LASTEXITCODE
if ($code -ne 0) {
    Restore-EdgeServices
    Fail 'edge:local:update refused or failed (the previous runtime stays active; see the message above)'
}
# Dev/test packages carry app\vendor as a junction: the staged version needs the same link (a release copies a real vendor).
$pkgVendor = Get-Item (Join-Path $PackageRoot 'app\vendor') -ErrorAction SilentlyContinue
$current = (Get-Content -Path (Join-Path $layout.runtime_root 'current') -Raw).Trim()
$newDir = Join-Path $layout.runtime_root ("versions\" + $current)
if ($pkgVendor -and ($pkgVendor.Attributes -band [IO.FileAttributes]::ReparsePoint) -and -not (Test-Path (Join-Path $newDir 'vendor'))) {
    $target = $pkgVendor.Target; if ($target -is [array]) { $target = $target[0] }
    & cmd /c mklink /J "$(Join-Path $newDir 'vendor')" "$target" | Out-Null
}
foreach ($d in @('bootstrap\cache', 'storage\framework\cache\data', 'storage\framework\views', 'storage\framework\sessions', 'storage\logs', 'storage\app')) {
    New-Item -ItemType Directory -Path (Join-Path $newDir $d) -Force | Out-Null
}
$layout.edge_app_version = $current
[System.IO.File]::WriteAllText((Join-Path $InstallRoot 'appliance.json'), ($layout | ConvertTo-Json), (New-Object System.Text.UTF8Encoding($false)))

# 4c. EDGE 0.9 - refresh the installed operator scripts from the package (a 0.8.x box keeps its old copies otherwise: the
#     Win10 [TimeSpan]::MaxValue Register script, no wizard) and give the service account what the auto-updater needs.
$pkgScripts = Join-Path $PackageRoot 'scripts'
if (Test-Path $pkgScripts) {
    & robocopy $pkgScripts (Join-Path $InstallRoot 'scripts') /E /XJ /NFL /NDL /NJH /NJS /NP /R:1 /W:1 | Out-Null
    if ($LASTEXITCODE -ge 8) { Write-Warning "scripts refresh failed (robocopy exit $LASTEXITCODE) - the previous scripts stay" } else { Write-Host 'Installed scripts refreshed from the package.' }
    $scriptDir = Join-Path $InstallRoot 'scripts'
}
$isAdmin = (New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if ($isAdmin) {
    $updatesDir = Join-Path ([string]$layout.data_root) 'updates'
    New-Item -ItemType Directory -Path $updatesDir -Force | Out-Null
    & icacls $updatesDir /grant "$($ServiceAccount):(OI)(CI)M" | Out-Null
    & icacls ([string]$layout.runtime_root) /grant "$($ServiceAccount):(OI)(CI)M" | Out-Null
}

# 5. re-render the plan for the new version, start, health
$planArgs = @('edge:local:service-plan', "--php=$php", "--app-root=$InstallRoot", "--data-root=$($layout.data_root)", '--write-gateway-config', '--json')
if ($layout.gateway) { $planArgs += "--gateway=$($layout.gateway)" }
$planJson = & $php $launcher @planArgs
if ($LASTEXITCODE -eq 0) { [System.IO.File]::WriteAllText($planFile, ($planJson -join "`n"), (New-Object System.Text.UTF8Encoding($false))) }
# the old heartbeat worker ran through the whole update; stop it only now, right before the new runtime's services start
Stop-EdgeAuthorityWorker
if (-not $NoServices -and (Test-Path $planFile)) {
    $startArgs = @{ Action = $(if ($ReRegisterServices) { 'Register' } else { 'Start' }); PlanFile = $planFile }
    if ($ReRegisterServices) { $startArgs['ServiceAccount'] = $ServiceAccount }
    if ($NoGateway) { $startArgs['NoGateway'] = $true }
    & (Join-Path $scriptDir 'Register-EdgeServices.ps1') @startArgs
}
& $php $launcher edge:local:health
Write-Host "UPDATE COMPLETE - active runtime version: $current" -ForegroundColor Green
exit 0
