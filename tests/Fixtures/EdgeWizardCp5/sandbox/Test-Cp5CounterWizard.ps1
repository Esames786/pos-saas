# CP5 (stream B) - headless proof of stream D's counter self-service wizard INSIDE the sandbox (elevated, same PC as the
# Branch Server). The REAL generated file from the Cloud is parsed; its data block and its functions are loaded (the window
# part is never run) and the same step functions the buttons call are driven in order:
#   1. CA without confirmation  -> must NOT be trusted ("waiting for your confirmation")
#   2. CA with confirmation     -> trusted in LocalMachine\Root
#   3. hosts line + shortcuts   (Invoke-NetworkStep)
#   4. HTTPS checks by name AND by IP with full validation (Invoke-EdgeChecks: health, health, login page)
#   5. Repair = the same steps again: CA "already trusted", ONE hosts line, shortcuts refreshed, checks OK
#   6. Remove (Invoke-UninstallCore -RemoveCa): shortcuts, hosts line, CA gone
# Writes counter-steps.json into -Evidence. Public data only (the file carries no secret: no self-enrollment requested).
param(
    [Parameter(Mandatory = $true)][string]$WizardFile,
    [Parameter(Mandatory = $true)][string]$Evidence,
    # host pre-flight: load the file's data + functions and run ONLY step 1 (no confirmation = nothing may change)
    [switch]$DryLoad
)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Off

$tokens = $null; $errs = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile($WizardFile, [ref]$tokens, [ref]$errs)
if (@($errs).Count -gt 0) { throw ('the counter wizard does not parse: ' + ($errs | Select-Object -First 1)) }
$sb = New-Object System.Text.StringBuilder
$dataVars = '^\$(SetupKind|TenantLabel|BranchLabel|EdgeIp|EdgeHost|EdgePort|EdgeBaseByName|EdgeBaseByIp|HostsLine|LanHint|UrlOnline|UrlOffline|UrlStatus|ShortcutNames|CaName|CaThumbprint|CaSha256|CaSha256Display|CaValidUntil|CaPem|IconB64|SignedProfile\w*|Enroll\w+|Terminal\w+)$'
foreach ($st in $ast.EndBlock.Statements) {
    if ($st -is [System.Management.Automation.Language.FunctionDefinitionAst]) { [void]$sb.AppendLine($st.Extent.Text); continue }
    if ($st -is [System.Management.Automation.Language.AssignmentStatementAst] -and $st.Left.Extent.Text -match $dataVars) { [void]$sb.AppendLine($st.Extent.Text) }
}
. ([scriptblock]::Create($sb.ToString()))

function Get-HostsCount { @(Get-Content -Path (Join-Path $env:SystemRoot 'System32\drivers\etc\hosts') | Where-Object { $_ -match ('(^|\s)' + [regex]::Escape($EdgeHost) + '(\s|$)') -and $_ -notmatch '^\s*#' }).Count }
function Test-Root { [bool](Get-ChildItem Cert:\LocalMachine\Root | Where-Object { $_.Thumbprint -eq $CaThumbprint }) }
function Get-Shortcuts { $d = [Environment]::GetFolderPath('CommonDesktopDirectory'); @($ShortcutNames | Where-Object { Test-Path (Join-Path $d ($_ + '.url')) }) }
function Get-Snapshot([string]$label) {
    [ordered]@{ label = $label; ca_in_root = (Test-Root); hosts_lines_for_edge = (Get-HostsCount); shortcuts = @(Get-Shortcuts) }
}
function Format-Checks($c) { [ordered]@{ ok = [bool]$c.Ok; lines = @($c.Lines); hints = @($c.Hints); health_status = $(if ($c.Health) { [string]$c.Health.status } else { $null }) } }

$steps = New-Object System.Collections.ArrayList
$facts = [ordered]@{ edge_host = $EdgeHost; edge_ip = $EdgeIp; edge_port = $EdgePort; by_name = $EdgeBaseByName; by_ip = $EdgeBaseByIp; ca_thumbprint = $CaThumbprint; ca_sha256 = $CaSha256; signed_profile = [bool]$SignedProfileB64 }
[void]$steps.Add((Get-Snapshot 'before'))

$r = Invoke-CaStep -Confirmed $false
[void]$steps.Add([ordered]@{ step = '1 CA without confirmation'; ok = [bool]$r.Ok; detail = [string]$r.Detail; after = (Get-Snapshot 'after-1') })
if ($DryLoad) {
    $doc = [ordered]@{ facts = $facts; steps = @($steps); functions = @($ast.EndBlock.Statements | Where-Object { $_ -is [System.Management.Automation.Language.FunctionDefinitionAst] }).Count }
    [IO.File]::WriteAllText((Join-Path $Evidence 'counter-dryload.json'), ($doc | ConvertTo-Json -Depth 8), (New-Object Text.UTF8Encoding($false)))
    $doc | ConvertTo-Json -Depth 8 -Compress
    return
}
$r = Invoke-CaStep -Confirmed $true
[void]$steps.Add([ordered]@{ step = '2 CA confirmed'; ok = [bool]$r.Ok; added = [bool]$r.Added; detail = [string]$r.Detail; after = (Get-Snapshot 'after-2') })
$r = Invoke-NetworkStep
[void]$steps.Add([ordered]@{ step = '3 hosts line + shortcuts'; ok = [bool]$r.Ok; lines = @($r.Lines); after = (Get-Snapshot 'after-3') })
Clear-DnsCache
$c = Invoke-EdgeChecks
[void]$steps.Add([ordered]@{ step = '4 HTTPS checks by name and by IP (full validation)'; checks = (Format-Checks $c) })

$r1 = Invoke-CaStep -Confirmed $true
$r2 = Invoke-NetworkStep
$c2 = Invoke-EdgeChecks
[void]$steps.Add([ordered]@{ step = '5 Repair (all steps again)'; ca = [string]$r1.Detail; network_ok = [bool]$r2.Ok; network = @($r2.Lines); checks = (Format-Checks $c2); after = (Get-Snapshot 'after-5') })

$u = Invoke-UninstallCore -RemoveCa $true
[void]$steps.Add([ordered]@{ step = '6 Remove (with the certificate)'; ok = [bool]$u.Ok; lines = @($u.Lines); after = (Get-Snapshot 'after-6') })

$s = @($steps)
$verdict = [ordered]@{
    ca_refused_without_confirmation = (-not $s[1].ok) -and (-not $s[1].after.ca_in_root)
    ca_trusted_after_confirmation   = $s[2].ok -and $s[2].after.ca_in_root
    hosts_and_shortcuts             = $s[3].ok -and $s[3].after.hosts_lines_for_edge -eq 1 -and $s[3].after.shortcuts.Count -eq 3
    tls_by_name_and_ip              = [bool]$s[4].checks.ok
    repair_idempotent               = $s[5].network_ok -and $s[5].checks.ok -and $s[5].after.hosts_lines_for_edge -eq 1
    removed                         = $s[6].ok -and (-not $s[6].after.ca_in_root) -and $s[6].after.hosts_lines_for_edge -eq 0 -and $s[6].after.shortcuts.Count -eq 0
}
$verdict.all = -not ($verdict.Values -contains $false)
$doc = [ordered]@{ facts = $facts; steps = $s; verdict = $verdict }
[IO.File]::WriteAllText((Join-Path $Evidence 'counter-steps.json'), ($doc | ConvertTo-Json -Depth 8), (New-Object Text.UTF8Encoding($false)))
$doc.verdict | ConvertTo-Json -Compress
