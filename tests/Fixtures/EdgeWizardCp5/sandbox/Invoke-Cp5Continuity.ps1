# CP5 scenario h (stream A, Edge 0.9 shift / order continuity) - runs INSIDE the Windows Sandbox after B's scenario e, on the
# appliance and the disposable Cloud that Invoke-Cp5Sandbox.ps1 set up. Nothing on the host is touched.
#
#   & (Join-Path $Kit 'Invoke-Cp5Continuity.ps1') -Evidence $Ev [-LanIp $script:LanIp] [-CaPem $script:CaPem] [-SkipStale]
#
# Returns ONE ordered hashtable (verdict_ok + facts) for B's Invoke-Scenario; writes h-*.json evidence into -Evidence.
#   1  Cloud (real services, cp5-continuity.php cloud-seed): counter X = the 2nd seeded terminal - an OPEN shift, one PAID sale,
#      an open TABLE order whose KOT went to the kitchen, a HELD bill
#   2  standby: wait until the appliance mirrors that state and is fresh enough to take over
#   3  WAN cut = the in-sandbox Cloud web process stops (its recorded pid only); after the lease lapses on the appliance clock the
#      OWNER presses "Switch to Offline" on the status page through the gateway (CSRF, confirm, the appliance lock, no override)
#   4  cashier PIN login through the gateway, FULL TLS against the branch CA (by name, resolve pinned to the LAN IP)
#   5  the counter CONTINUES the online shift (badge open + continuity banner, no open-shift step)
#   6  Add Round on the adopted table order (+1 line, KOT = only the new line) and settle it
#   7  one new sale (print intents skipped: B's kit has no FakePrinter - recorded as skipped)
#   9  WAN back (the Cloud web process restarts, new pid recorded) -> outbox drains -> Cloud invariants
#  10  owner "Return to Online" (untouched adopted drafts released; counters close with the drawer count) -> standby
#   8  stale mirror (second outage, FAULT INJECTION of mirrored_at labelled in the evidence) -> adoption refused, the normal
#      open-shift flow, zero-drawer close, handback again
# Secrets: PINs and the appliance MySQL root password are read by the php helper from B's secrets folder; never on argv, never
# printed, never in the evidence (B's Protect-Evidence scans the folder at the end of the run anyway).
param(
    [Parameter(Mandatory = $true)][string]$Evidence,
    [string]$Kit = 'C:\cp5\kit',
    [string]$Work = 'C:\cp5w',
    [string]$CloudSrc = 'C:\cp5\cloudsrc',
    [string]$CloudPhp = 'C:\cp5\pkgs\BingooEdge-0.9.0-dev\php\php.exe',
    [string]$CloudIni = 'C:\cp5w\cloud\php.ini',
    [string]$CaPem = 'C:\cp5w\branch-ca.pem',
    [string]$LanIp = '',
    [string]$LanHost = 'bingoo-edge.local',
    [int]$HttpsPort = 8443,
    [int]$CloudPort = 9831,
    [int]$CloudDbPort = 3407,
    [int]$SkewMarginSeconds = 30,
    [int]$MaxMirrorAgeSeconds = 120,
    [switch]$SkipStale
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$Helper = Join-Path $Kit 'cp5-continuity.php'
$Secrets = Join-Path $Work 'secrets'
$RunFile = Join-Path $Work 'cp5-run.json'
$StateFile = Join-Path $Work 'cloud\continuity-state.json'
$LogDir = Join-Path $Work 'h-logs'
$RootPassFile = Join-Path $Secrets 'mysql-root.keep'
New-Item -ItemType Directory -Force -Path $LogDir, $Evidence | Out-Null
$env:CP5_TREE = $CloudSrc; $env:CP5_WORK = $Work; $env:CP5_KIT = $Kit
$env:CP5_CLOUD_PORT = [string]$CloudPort; $env:CP5_CLOUD_DB_PORT = [string]$CloudDbPort

function Write-H([string]$Level, [string]$Message) {
    $line = '{0} [H-{1}] {2}' -f (Get-Date -Format 'HH:mm:ss'), $Level.ToUpper(), $Message
    $color = @{ step = 'Cyan'; ok = 'Green'; warn = 'Yellow'; error = 'Red'; info = 'Gray' }[$Level]
    if (-not $color) { $color = 'Gray' }
    Write-Host $line -ForegroundColor $color
    Add-Content -Path (Join-Path $Evidence 'h-driver.log') -Value $line -Encoding ASCII
}
function Save-H([string]$Name, $Object) {
    $text = $(if ($Object -is [string]) { $Object } else { $Object | ConvertTo-Json -Depth 14 })
    [IO.File]::WriteAllText((Join-Path $Evidence $Name), $text, (New-Object Text.UTF8Encoding($false)))
}
function ConvertTo-ArgLine([string[]]$Arguments) {
    return (($Arguments | ForEach-Object {
                if ($_ -eq '') { '""' }
                elseif ($_ -match '[\s"]') { '"' + (($_ -replace '(\\*)"', '$1$1\"') -replace '(\\+)$', '$1$1') + '"' }
                else { $_ }
            }) -join ' ')
}
function Read-Shared([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path)) { return '' }
    $fs = [IO.File]::Open($Path, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::ReadWrite)
    try { return (New-Object IO.StreamReader($fs)).ReadToEnd() } finally { $fs.Dispose() }
}
function Read-Run { if (Test-Path $RunFile) { return (Get-Content -Raw $RunFile | ConvertFrom-Json) } return $null }
function Save-Run([hashtable]$Patch) {
    $h = @{}
    if (Test-Path $RunFile) { (Get-Content -Raw $RunFile | ConvertFrom-Json).PSObject.Properties | ForEach-Object { $h[$_.Name] = $_.Value } }
    foreach ($k in $Patch.Keys) { $h[$k] = $Patch[$k] }
    [IO.File]::WriteAllText($RunFile, ($h | ConvertTo-Json -Depth 6), (New-Object Text.UTF8Encoding($false)))
}

# One helper call: php (the Cloud's package runtime) + cp5-continuity.php; the WHOLE stdout is one JSON document.
function Invoke-H([string[]]$A, [int]$TimeoutSec = 600, [hashtable]$Env = @{}, [string]$EvidenceName = $null) {
    $log = Join-Path $LogDir ('h-' + ($A[0] -replace '[^a-z-]', '') + '-' + (Get-Date -Format 'HHmmssfff') + '.out')
    foreach ($k in $Env.Keys) { Set-Item -Path ('env:' + $k) -Value $Env[$k] }
    try {
        $p = Start-Process -FilePath $CloudPhp -ArgumentList (ConvertTo-ArgLine (@('-c', $CloudIni, $Helper) + $A)) -RedirectStandardOutput $log -RedirectStandardError ($log + '.err') -NoNewWindow -PassThru -WorkingDirectory $Work
    } finally {
        foreach ($k in $Env.Keys) { Remove-Item -Path ('env:' + $k) -ErrorAction SilentlyContinue }
    }
    $null = $p.Handle
    if (-not $p.WaitForExit($TimeoutSec * 1000)) { & taskkill.exe /PID $p.Id /T /F 2>$null | Out-Null; throw ('TIMEOUT: helper ' + $A[0]) }
    $p.WaitForExit()
    $out = Read-Shared $log
    $err = Read-Shared ($log + '.err')
    $json = $null
    try { $json = $out | ConvertFrom-Json } catch { }
    if ($EvidenceName) { Save-H $EvidenceName ($out + $(if ($err) { "`n--- stderr ---`n" + $err } else { '' })) }
    return @{ Code = [int]$p.ExitCode; Json = $json; Out = $out; Err = $err }
}
$EdgeDbEnv = @{ CP5_EDGE_ROOT_PASSFILE = $RootPassFile }
function Get-EdgeFacts([string]$Name = $null) { return (Invoke-H @('edge-db', 'facts') 120 $EdgeDbEnv $Name).Json }
function Get-CloudFacts([string]$Name = $null) {
    $r = Invoke-H @('cloud-facts', $StateFile) 600 @{} $Name
    if ($r.Code -ne 0 -or -not $r.Json) { throw ('cloud-facts failed: ' + ($r.Err + $r.Out).Trim()) }
    return $r.Json
}
function Get-Pin([int]$UserId) { $f = Join-Path $Secrets ('pin-' + $UserId); if (-not (Test-Path $f)) { throw "no PIN file for user $UserId" }; return $f }
$GatewayBase = "https://${LanHost}:$HttpsPort"
function Get-GatewayEnv { return @{ CP5_RESOLVE = "${LanHost}:${HttpsPort}:$LanIp" } }

# ---- the in-sandbox Cloud web process: the WAN cut and the reconnect (ONLY the pid B's driver recorded / we started) ----
function Get-CloudListenerPid {
    $c = @(Get-NetTCPConnection -State Listen -LocalPort $CloudPort -ErrorAction SilentlyContinue | Select-Object -First 1)
    if ($c.Count -gt 0 -and $c[0]) { return [int]$c[0].OwningProcess }
    return $null
}
function Stop-CloudWeb {
    $run = Read-Run
    $recorded = $(if ($run -and $run.cloud_php_pid) { [int]$run.cloud_php_pid } else { $null })
    $listener = Get-CloudListenerPid
    if (-not $listener) { return 'not-listening' }
    if ($recorded -ne $listener) { throw "the Cloud listener on $CloudPort is pid $listener, not the recorded cloud_php_pid $recorded - refusing to stop a process this kit did not start" }
    Stop-Process -Id $listener -Force
    $deadline = (Get-Date).AddSeconds(30)
    while ((Get-CloudListenerPid) -and (Get-Date) -lt $deadline) { Start-Sleep -Milliseconds 500 }
    if (Get-CloudListenerPid) { throw 'the Cloud web process did not stop' }
    return "stopped pid $listener"
}
function Start-CloudWeb {
    if (Get-CloudListenerPid) { return 'already-listening' }
    $log = Join-Path $Work ('cloud\serve-h-' + (Get-Date -Format 'HHmmss') + '.log')
    $p = Start-Process -FilePath $CloudPhp -ArgumentList ('-c "' + $CloudIni + '" -S 127.0.0.1:' + $CloudPort + ' -t "' + (Join-Path $CloudSrc 'public') + '" "' + (Join-Path $Kit 'cp5-router.php') + '"') -RedirectStandardOutput $log -RedirectStandardError ($log + '.err') -WindowStyle Hidden -PassThru
    Save-Run @{ cloud_php_pid = $p.Id }
    $deadline = (Get-Date).AddSeconds(60)
    while (-not (Get-CloudListenerPid) -and (Get-Date) -lt $deadline) { Start-Sleep -Milliseconds 700 }
    if (-not (Get-CloudListenerPid)) { throw 'the Cloud web process did not listen again' }
    return "started pid $($p.Id)"
}
function Wait-LeaseLapse([string]$Label) {
    $l = (Invoke-H @('edge-db', 'lapse', [string]$SkewMarginSeconds) 60 $EdgeDbEnv).Json
    $wait = [Math]::Max(0, [int]$l.lapse_in_s) + 5
    Write-H 'info' "$Label : last ack $($l.last_ack_at) UTC, lease ttl $($l.ttl) s + skew $SkewMarginSeconds s -> waiting $wait s on the appliance clock"
    if ($wait -gt 900) { throw "implausible lease lapse wait $wait s" }
    Start-Sleep -Seconds $wait
    return $l
}
function Invoke-OwnerAction([string]$Action, [string]$ExpectState, [int]$Attempts, [string]$Label) {
    $log = @()
    for ($i = 1; $i -le $Attempts; $i++) {
        $r = Invoke-H @('edge-owner', $GatewayBase, $script:OwnerCode, (Get-Pin $script:OwnerId), $CaPem, $Action) 300 (Get-GatewayEnv)
        $state = [string](Get-EdgeFacts).authority.authority_state
        $log += [ordered]@{ attempt = $i; post_status = $(if ($r.Json) { $r.Json.post_status } else { $null }); message = $(if ($r.Json) { $r.Json.message } else { ($r.Err + $r.Out).Trim() }); authority_state = $state
            tls_verified = $(if ($r.Json -and $r.Json.session) { $r.Json.session.tls.verified } else { $null }) }
        Write-H 'info' ("$Label attempt ${i}: " + $log[-1].message + " -> authority $state")
        if ($state -eq $ExpectState) { break }
        if ($i -lt $Attempts) { Start-Sleep -Seconds 15 }   # the buttons are throttled (6 / minute)
    }
    return , $log
}
function Wait-Synced([string]$Label, [int]$TimeoutSec = 600) {
    $deadline = (Get-Date).AddSeconds($TimeoutSec)
    do {
        Start-Sleep -Seconds 10
        $f = Get-EdgeFacts
        $states = @($f.outbox | ForEach-Object { [string]$_.state })
        $open = @($states | Where-Object { $_ -ne 'acknowledged' }).Count
        $acks = [int]$f.authority.heartbeat_consecutive_acks
        $clean = [bool]$f.authority.reconcile_clean_at
        if ($open -eq 0 -and $clean -and $acks -ge 2) { Write-H 'ok' "$Label : outbox drained, reconciliation clean, $acks consecutive acks"; return $f }
    } while ((Get-Date) -lt $deadline)
    Write-H 'warn' "$Label : not fully synced after $TimeoutSec s (outbox states: $($states -join ','), clean $clean, acks $acks)"
    return $f
}
function Close-OpenShifts([string]$Label) {
    $f = Get-EdgeFacts
    $close = @($f.shifts | Where-Object { $_.status -eq 'open' } | ForEach-Object { [ordered]@{ terminal_id = [int]$_.terminal_id; counted_cash = [double]$_.expected_cash } })
    if ($close.Count -eq 0) { return $null }
    $spec = Join-Path $Work 'h-close-spec.json'
    [IO.File]::WriteAllText($spec, ([ordered]@{ close = $close } | ConvertTo-Json -Depth 4), (New-Object Text.UTF8Encoding($false)))
    $r = Invoke-H @('edge-close', $GatewayBase, $script:OwnerCode, (Get-Pin $script:OwnerId), $CaPem, $spec) 300 (Get-GatewayEnv) ("h-$Label-close-shifts.json")
    Write-H 'info' ("$Label : closed " + $close.Count + ' open shift(s) with the drawer count: ' + $(if ($r.Json) { $r.Json.ok } else { 'no answer' }))
    return $r.Json
}

# ======================================================================================================================
$t0 = Get-Date
$checks = [ordered]@{}
$doc = [ordered]@{ scenario = 'h'; title = 'shift / order continuity: mirror -> supervised takeover -> continue -> merge -> handback (+ stale mirror fails closed)'; started = $t0.ToString('s') }
try {
    if (-not $LanIp) { $run = Read-Run; if ($run -and $run.lan_ip) { $LanIp = [string]$run.lan_ip } }
    if (-not $LanIp) { throw 'no LAN IP (pass -LanIp or run B''s prereqs first)' }
    foreach ($p in @($Helper, $CloudPhp, $CloudIni, $CaPem, $RootPassFile)) { if (-not (Test-Path $p)) { throw "missing: $p" } }

    # 1 ---- Cloud live state through real services
    Write-H 'step' '1 Cloud: counter X shift, paid sale, table order with KOT, held bill (real services)'
    $seed = Invoke-H @('cloud-seed', $StateFile) 900 @{} 'h-1-cloud-seed.json'
    if ($seed.Code -ne 0 -or -not $seed.Json.ok) { throw ('cloud-seed failed: ' + ($seed.Err + $seed.Out).Trim()) }
    $S = $seed.Json
    $script:OwnerId = [int]$S.owner_id; $script:OwnerCode = [string]$S.owner_code
    $checks['cloud_seed_real_services'] = $true
    $facts0 = Get-CloudFacts 'h-1-cloud-facts-before.json'

    # 2 ---- standby mirrors it and is fresh enough to take over
    Write-H 'step' '2 standby: waiting for the live-state mirror + standby freshness'
    $deadline = (Get-Date).AddSeconds(420)
    do { Start-Sleep -Seconds 10; $m = (Invoke-H @('edge-db', 'mirror', $StateFile) 60 $EdgeDbEnv).Json } while (-not $m.fresh -and (Get-Date) -lt $deadline)
    Save-H 'h-2-mirror.json' $m
    $checks['mirror_fresh_before_takeover'] = [bool]$m.fresh
    if (-not $m.fresh) { throw 'the appliance never mirrored counter X''s live state (see h-2-mirror.json)' }
    Write-H 'ok' ("mirror revision " + $m.mirror.revision + ', expected cash ' + $m.mirrored_expected_cash)

    # 3 ---- WAN cut + supervised takeover (status-page button)
    Write-H 'step' '3 WAN cut (Cloud web stops) + owner Switch to Offline after the lease lapses'
    $doc['wan_cut'] = Stop-CloudWeb
    $doc['lapse'] = Wait-LeaseLapse 'takeover'
    $doc['takeover'] = Invoke-OwnerAction 'switch-offline' 'local_active' 8 'Switch to Offline'
    $checks['supervised_takeover'] = ($doc['takeover'][-1].authority_state -eq 'local_active' -and ([string]$doc['takeover'][-1].message).StartsWith('OFFLINE'))
    if (-not $checks['supervised_takeover']) { throw 'the supervised takeover did not activate Local Mode (see the takeover attempts)' }

    # 4-7 ---- cashier through the gateway: continue the shift, Add Round + KOT, settle the adopted order, ring a new sale
    Write-H 'step' '4-7 cashier PIN login (full TLS by name), shift continues, Add Round + KOT, settle the adopted table order, new sale'
    $spec = Join-Path $Work 'h-cashier-spec.json'
    [IO.File]::WriteAllText($spec, ([ordered]@{ terminal_id = [int]$S.counter_x; cloud_shift_uuid = [string]$S.shift.uuid; table_order_sale_no = [string]$S.table_order.sale_no
                table_order_uuid = [string]$S.table_order.uuid; product_id = [int]$S.product_id; unit_price = [double]$S.unit_price; cash_method_id = [int]$S.cash_method_id } | ConvertTo-Json), (New-Object Text.UTF8Encoding($false)))
    $cash = Invoke-H @('edge-cashier', $GatewayBase, [string]$S.cashier_code, (Get-Pin ([int]$S.cashier_id)), $CaPem, $spec) 600 (Get-GatewayEnv) 'h-4-7-cashier.json'
    $C = $cash.Json
    $checks['cashier_pin_login_full_tls'] = [bool]($C -and $C.session.ok -and $C.session.tls.verified)
    $checks['shift_continues_no_open_step'] = [bool]($C -and $C.shift_before.open -and ([string]$C.shift_before.continuity.banner).StartsWith('Continuing online shift') -and $C.shift_before.continuity.cloud_shift_uuid -eq $S.shift.uuid)
    $checks['mirrored_draft_badge'] = [bool]($C -and $C.held_list.table_order_badge)
    $kotLines = @(); if ($C -and $C.kot) { $kotLines = @($C.kot.lines) }
    $checks['kot_only_new_line'] = ($kotLines.Count -eq 1 -and [double]$kotLines[0].quantity -eq 1)
    $checks['adopted_order_settled'] = [bool]($C -and $C.settle.status -eq 200 -and $C.settle.body.sale_uuid -eq $S.table_order.uuid)
    $checks['new_sale_rung'] = [bool]($C -and @(200, 201) -contains [int]$C.new_sale.status)
    $doc['print_path'] = 'skipped: B''s CP5 kit has no FakePrinter - KOT / receipt intents were "skip"; the KOT business event of the new line is recorded (kot lines)'
    $edgeOffline = Get-EdgeFacts 'h-4-7-edge-facts.json'
    $xShift = @($edgeOffline.shifts | Where-Object { [int]$_.terminal_id -eq [int]$S.counter_x -and $_.continues_cloud_shift_uuid -eq $S.shift.uuid })
    $checks['one_continuation_on_counter_x'] = ($xShift.Count -eq 1 -and [double]$xShift[0].opening_cash -eq [double]$S.shift.expected_cash)
    # The takeover actor rides the adoption audit: 'manual' after the button; an 'auto' row here means the optional automatic
    # takeover fired first (B's auto-takeover setting ON) - then this run did NOT prove the supervised path and says so.
    $adoptedRows = @($edgeOffline.adoptions | Where-Object { $_.status -eq 'adopted' })
    $doc['takeover_audit'] = @($adoptedRows | ForEach-Object { [ordered]@{ kind = $_.kind; takeover_by = $_.takeover_by; takeover_mode = $_.takeover_mode; n = $_.n } })
    $checks['adoption_audited_supervised'] = ($adoptedRows.Count -gt 0 -and -not ($adoptedRows | Where-Object { $_.takeover_mode -ne 'manual' }))

    # 9 ---- WAN back: outbox drains, the Cloud merges
    Write-H 'step' '9 WAN back (Cloud web restarts) -> outbox drains -> Cloud invariants'
    $doc['reconnect'] = Start-CloudWeb
    $synced = Wait-Synced 'reconnect' 600
    Save-H 'h-9-edge-facts-synced.json' $synced
    $F = Get-CloudFacts 'h-9-cloud-facts-after.json'
    $price = [double]$S.unit_price
    $settledTotal = [double]$C.settle.body.grand_total
    $x = [string]$S.counter_x
    $perCounter = $F.shifts_per_counter.PSObject.Properties | ForEach-Object { [ordered]@{ terminal = $_.Name; shifts = [int]$_.Value.shifts } }
    $checks['exactly_one_shift_per_counter'] = (-not ($perCounter | Where-Object { $_.shifts -ne 1 })) -and ([int]$F.shifts_per_counter.$x.shifts -eq 1)
    $to = $F.orders.table_order
    $checks['adopted_order_completed_in_place'] = ($to.status -eq 'paid' -and $to.sale_no -eq $S.table_order.sale_no -and [int]$to.shift_id -eq [int]$S.shift.id -and [int]$to.payments -eq 1 -and [int]$to.lines -eq 3 -and [int]$to.kept_line_uuids -eq 2)
    $checks['no_duplicate_kot_or_print_on_cloud'] = ([int]$F.counts.kot_batches -eq [int]$facts0.counts.kot_batches -and [int]$F.counts.print_jobs -eq [int]$facts0.counts.print_jobs)
    $newUuid = [string]$C.new_sale.body.sale_uuid
    $newOnCloud = @($F.edge_synced_sales | Where-Object { $_.sale_uuid -eq $newUuid })
    $checks['new_sale_in_original_shift'] = ($newOnCloud.Count -eq 1 -and [int]$newOnCloud[0].shift_id -eq [int]$S.shift.id)
    $dOrders = [int]$F.counts.sales_orders - [int]$facts0.counts.sales_orders
    $dPays = [int]$F.counts.sale_payments - [int]$facts0.counts.sale_payments
    $dCashBank = [int]$F.counts.cash_bank_account_transactions - [int]$facts0.counts.cash_bank_account_transactions
    $checks['no_duplicate_order_or_payment'] = ($dOrders -eq 1 -and $dPays -eq 2)
    $checks['no_duplicate_cash_movement'] = ($dCashBank -eq 0 -or $dCashBank -eq $dPays)
    $expectedCash = [double]$S.shift.expected_cash + $settledTotal + $price
    $checks['counter_x_totals'] = ([double]$F.counter_x_shift.expected_cash -eq $expectedCash -and [double]$F.counter_x_shift.total_sales -eq ($price + $settledTotal + $price))
    $applied = @($F.ingestions | Where-Object { $_.status -eq 'applied' })
    $checks['ingestions_applied_no_conflict'] = ($applied.Count -eq 2 -and @($F.ingestions | Where-Object { $_.status -ne 'applied' }).Count -eq 0)
    $checks['held_bill_untouched_on_cloud'] = ($F.orders.held.status -eq 'held' -and [int]$F.orders.held.payments -eq 0)
    $checks['cloud_table_session_closed'] = ($F.table_session_status -eq 'closed')
    $doc['deltas'] = [ordered]@{ sales_orders = $dOrders; sale_payments = $dPays; cash_bank_transactions = $dCashBank; kot_batches = [int]$F.counts.kot_batches - [int]$facts0.counts.kot_batches
        print_jobs = [int]$F.counts.print_jobs - [int]$facts0.counts.print_jobs; stock_out_qty = [double]$F.counts.stock_out_sale_qty - [double]$facts0.counts.stock_out_sale_qty
        counter_x_expected_cash = [ordered]@{ before = [double]$S.shift.expected_cash; after = [double]$F.counter_x_shift.expected_cash; expected = $expectedCash } }

    # 10 ---- owner handback (1st press releases untouched adopted drafts and names the open shifts; counters close; press again)
    Write-H 'step' '10 owner Return to Online (untouched adopted drafts released, counters close with the drawer count) -> standby'
    $first = Invoke-OwnerAction 'return-online' 'standby' 1 'Return to Online (first press)'
    $doc['handback_first_press'] = $first
    $doc['close_shifts'] = Close-OpenShifts 'h10'
    $doc['handback'] = $first + (Invoke-OwnerAction 'return-online' 'standby' 8 'Return to Online')
    $after = Get-EdgeFacts 'h-10-edge-facts-handback.json'
    $checks['handback_to_standby'] = ([string]$after.authority.authority_state -eq 'standby')
    $Fh = Get-CloudFacts 'h-10-cloud-facts-handback.json'
    $checks['cloud_writer_again'] = ($Fh.lease.holder -eq 'cloud')

    # 8 ---- stale mirror: a second outage with a stale mirror -> adoption refused, normal open-shift flow
    if (-not $SkipStale) {
        Write-H 'step' '8 stale mirror: second outage, FAULT INJECTION mirrored_at, owner Switch to Offline -> adoption refused'
        # standby fresh again after the handback (config + stock watermark + a live-state poll) before the second cut
        $deadline = (Get-Date).AddSeconds(240)
        do { Start-Sleep -Seconds 10; $m8 = (Invoke-H @('edge-db', 'mirror', $StateFile) 60 $EdgeDbEnv).Json } while (-not $m8.standby_fresh -and (Get-Date) -lt $deadline)
        Save-H 'h-8-mirror-before-stale.json' $m8
        $doc['stale_wan_cut'] = Stop-CloudWeb
        $doc['stale_lapse'] = Wait-LeaseLapse 'stale takeover'
        $doc['stale_injection'] = (Invoke-H @('edge-db', 'stale-inject', [string]($MaxMirrorAgeSeconds + 180)) 60 $EdgeDbEnv 'h-8-stale-injection.json').Json
        $doc['stale_takeover'] = Invoke-OwnerAction 'switch-offline' 'local_active' 8 'Switch to Offline (stale)'
        $spec8 = Join-Path $Work 'h-stale-spec.json'
        [IO.File]::WriteAllText($spec8, ([ordered]@{ terminal_id = [int]$S.counter_x } | ConvertTo-Json), (New-Object Text.UTF8Encoding($false)))
        $st = Invoke-H @('edge-stale', $GatewayBase, [string]$S.cashier_code, (Get-Pin ([int]$S.cashier_id)), $CaPem, $spec8) 300 (Get-GatewayEnv) 'h-8-cashier-stale.json'
        $staleFacts = Get-EdgeFacts 'h-8-edge-facts-stale.json'
        $checks['stale_mirror_fails_closed'] = [bool]($st.Json -and $st.Json.ok -and $staleFacts.mirror.adoption_status -eq 'refused_stale')
        $checks['stale_no_continuation_shift'] = (@($staleFacts.shifts | Where-Object { $_.continuity_segment -and $_.continuity_segment -eq $staleFacts.mirror.adopted_segment }).Count -eq 0)
        $doc['stale_reason'] = $staleFacts.mirror.adoption_reason
        $doc['stale_reconnect'] = Start-CloudWeb
        $null = Wait-Synced 'stale reconnect' 300
        $doc['stale_handback'] = Invoke-OwnerAction 'return-online' 'standby' 8 'Return to Online (stale)'
        $checks['stale_handback_to_standby'] = ([string](Get-EdgeFacts).authority.authority_state -eq 'standby')
        $Fs = Get-CloudFacts 'h-8-cloud-facts-after-stale.json'
        $checks['stale_cycle_wrote_nothing_on_cloud'] = ([int]$Fs.counts.sales_orders -eq [int]$F.counts.sales_orders -and [int]$Fs.counts.sale_payments -eq [int]$F.counts.sale_payments -and [int]$Fs.shifts_per_counter.$x.shifts -eq 1)
    } else {
        $doc['stale'] = 'skipped (-SkipStale)'
    }
} catch {
    $doc['error'] = $_.Exception.Message
    Write-H 'error' ('scenario h: ' + $_.Exception.Message)
    try { if (-not (Get-CloudListenerPid)) { $doc['recovery'] = Start-CloudWeb } } catch { $doc['recovery_error'] = $_.Exception.Message }
} finally {
    $doc['checks'] = $checks
    $failed = @($checks.Keys | Where-Object { -not $checks[$_] })
    $doc['failed_checks'] = $failed
    $doc['verdict_ok'] = (-not $doc.Contains('error')) -and $failed.Count -eq 0 -and $checks.Count -gt 0
    $doc['seconds'] = [int]((Get-Date) - $t0).TotalSeconds
    Save-H 'h-continuity.json' $doc
    Write-H $(if ($doc['verdict_ok']) { 'ok' } else { 'error' }) ('scenario h: ' + $(if ($doc['verdict_ok']) { 'PASS' } else { 'FAIL ' + ($failed -join ', ') }) + " in $($doc['seconds']) s")
}
return [ordered]@{ verdict_ok = $doc['verdict_ok']; checks = $checks; failed_checks = $doc['failed_checks']; deltas = $doc['deltas']; print_path = $doc['print_path']
    stale_reason = $doc['stale_reason']; error = $doc['error']; evidence = 'h-continuity.json' }
