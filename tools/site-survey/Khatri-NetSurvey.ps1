<#
.SYNOPSIS
  Read-only LAN / printer / PC survey for an Edge "Branch Server" site (prepared for Khatri, 21 Nov 2026).

.DESCRIPTION
  Collects, WITHOUT changing anything on the PC, the network, the printers or the Online Print Agent:
    - PC identity, Windows version, disk, power/sleep settings, network profile (Public/Private)
    - adapters, IPv4 addresses, gateways, DHCP lease, DNS servers, routes, ARP table
    - which router answers as gateway (192.168.100.x = main gate, 192.168.1.x = extender/extra router)
    - double-NAT detection (tracert hops to 8.8.8.8 while online; reachability of both router subnets)
    - printer reachability on TCP 9100 (connect + close, ZERO bytes written, nothing prints) + ping + tracert
    - internet presence (DNS + TCP 53/443 to public resolvers) so the "offline" phase is proven offline
    - listening TCP ports that would collide with Edge (80, 443, 8443, 3306, 8090, 8091)
    - whether the Online Print Agent (node) is running (name/pid/exe only - never its command line or config)
    - inbound ICMP firewall rules (why a ping from a cashier device may fail even when the LAN is fine)
  Writes everything to a report folder on the Desktop and prints a short summary block at the end.

  Secrets: nothing in this script reads tokens, passwords, agent codes or config files.

.PARAMETER Phase
  'online' (WAN connected) or 'offline' (owner has pulled the WAN cable). Only labels the report.

.PARAMETER Printers
  Printer IPs to probe on 9100. Default = Khatri PRINTER-1 (.206 both), PRINTER-2 (.69 kot), planned BBQ KOT (.218).

.EXAMPLE
  powershell -NoProfile -ExecutionPolicy Bypass -File .\Khatri-NetSurvey.ps1 -Phase online
  powershell -NoProfile -ExecutionPolicy Bypass -File .\Khatri-NetSurvey.ps1 -Phase offline
#>
[CmdletBinding()]
param(
    [ValidateSet('online', 'offline')]
    [string]$Phase = 'online',
    [string[]]$Printers = @('192.168.100.206', '192.168.100.69', '192.168.100.218'),
    [int]$PrinterPort = 9100,
    [string[]]$RouterCandidates = @('192.168.100.1', '192.168.1.1', '192.168.0.1', '192.168.2.1', '192.168.10.1'),
    [string]$OutRoot = (Join-Path ([Environment]::GetFolderPath('Desktop')) 'khatri-netsurvey')
)

$ErrorActionPreference = 'Continue'
$stamp = (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ')
$outDir = Join-Path $OutRoot "$Phase-$stamp"
New-Item -ItemType Directory -Force -Path $outDir | Out-Null
$report = Join-Path $outDir 'report.txt'
$summary = @{}

function Write-Section {
    param([string]$Title)
    $line = "`r`n===== $Title =====`r`n"
    $line | Out-File -FilePath $report -Append -Encoding utf8
    Write-Host $line
}
function Write-Out {
    param($Value)
    $text = ($Value | Out-String -Width 200)
    $text | Out-File -FilePath $report -Append -Encoding utf8
    Write-Host $text
}
function Invoke-Safe {
    param([string]$Title, [scriptblock]$Block)
    Write-Section $Title
    try { Write-Out (& $Block) } catch { Write-Out ("ERROR: " + $_.Exception.Message) }
}
function Test-TcpConnect {
    # Connect + close with a timeout. Never writes a byte. Returns 'reachable' | 'refused' | 'timeout' | 'unreachable'.
    param([string]$Ip, [int]$Port, [int]$TimeoutMs = 3000)
    $c = New-Object System.Net.Sockets.TcpClient
    try {
        $ar = $c.BeginConnect($Ip, $Port, $null, $null)
        if (-not $ar.AsyncWaitHandle.WaitOne($TimeoutMs, $false)) { return 'timeout' }
        $c.EndConnect($ar)
        return 'reachable'
    } catch [System.Net.Sockets.SocketException] {
        if ($_.Exception.SocketErrorCode -eq 'ConnectionRefused') { return 'refused' }
        return 'unreachable (' + $_.Exception.SocketErrorCode + ')'
    } catch {
        return 'unreachable (' + $_.Exception.Message + ')'
    } finally {
        $c.Close()
    }
}

"Khatri Edge site survey - phase=$Phase - $stamp (UTC) - read-only" | Out-File -FilePath $report -Encoding utf8
Write-Host "Report folder: $outDir"

# ---------- 1. PC ----------
Invoke-Safe '1. PC identity / Windows' {
    $ci = Get-ComputerInfo -Property CsName, OsName, OsVersion, OsArchitecture, CsTotalPhysicalMemory, OsLastBootUpTime
    $ci
    "hostname: $env:COMPUTERNAME  user: $env:USERNAME  domain/workgroup: $env:USERDOMAIN"
    $summary['PC'] = "$($ci.CsName) $($ci.OsName) $($ci.OsVersion) $($ci.OsArchitecture)"
}
Invoke-Safe '1b. Disk free (system drive)' {
    $d = Get-PSDrive -Name ($env:SystemDrive.TrimEnd(':'))
    "free GB: " + [math]::Round($d.Free / 1GB, 1) + "  used GB: " + [math]::Round($d.Used / 1GB, 1)
    $summary['DISK_FREE_GB'] = [math]::Round($d.Free / 1GB, 1)
}
Invoke-Safe '1c. Power / sleep (read-only)' {
    powercfg /getactivescheme
    powercfg /a
    "--- sleep / hibernate timeouts (AC) of the active scheme ---"
    powercfg /query SCHEME_CURRENT SUB_SLEEP | Select-String -Pattern 'Power Setting GUID|Current AC Power Setting Index' | ForEach-Object { $_.Line.Trim() }
}
Invoke-Safe '1d. Network connection profile (Public/Private) - Edge firewall rules depend on it' {
    Get-NetConnectionProfile | Format-List Name, InterfaceAlias, NetworkCategory, IPv4Connectivity, IPv6Connectivity
    $summary['NET_PROFILE'] = (Get-NetConnectionProfile | ForEach-Object { "$($_.InterfaceAlias)=$($_.NetworkCategory)/$($_.IPv4Connectivity)" }) -join '; '
}

# ---------- 2. Addresses ----------
Invoke-Safe '2. Adapters' {
    Get-NetAdapter | Sort-Object ifIndex | Format-Table ifIndex, Name, InterfaceDescription, Status, LinkSpeed, MacAddress -AutoSize
}
Invoke-Safe '2b. IPv4 configuration (DHCP lease, gateway, DNS)' {
    ipconfig /all
}
Invoke-Safe '2c. Default gateways / IPv4 routes' {
    Get-NetRoute -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' | Format-Table ifIndex, InterfaceAlias, NextHop, RouteMetric, InterfaceMetric -AutoSize
    route print -4
    $gws = (Get-NetRoute -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' | ForEach-Object { $_.NextHop }) -join ','
    $ips = (Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } | ForEach-Object { "$($_.IPAddress)/$($_.PrefixLength) on $($_.InterfaceAlias)" }) -join '; '
    $summary['PC_IPV4'] = $ips
    $summary['PC_GATEWAY'] = $gws
}
Invoke-Safe '2d. DNS servers' {
    Get-DnsClientServerAddress -AddressFamily IPv4 | Where-Object { $_.ServerAddresses } | Format-Table InterfaceAlias, ServerAddresses -AutoSize
}
Invoke-Safe '2e. ARP table (who is on the wire)' {
    arp -a
}

# ---------- 3. Routers / topology ----------
Invoke-Safe '3. Router candidates (ping + TCP 80/443 connect, read-only)' {
    foreach ($r in $RouterCandidates) {
        $p = Test-Connection -ComputerName $r -Count 1 -Quiet -ErrorAction SilentlyContinue
        $t80 = Test-TcpConnect -Ip $r -Port 80 -TimeoutMs 1500
        $t443 = Test-TcpConnect -Ip $r -Port 443 -TimeoutMs 1500
        "$r  ping=$p  tcp80=$t80  tcp443=$t443"
        $summary["ROUTER_$r"] = "ping=$p tcp80=$t80"
    }
}
Invoke-Safe '3b. tracert to the internet (hop list shows double NAT: two private hops before a public one)' {
    if ($Phase -eq 'online') { tracert -d -h 6 -w 1500 8.8.8.8 } else { 'skipped in offline phase' }
}
Invoke-Safe '3c. tracert to the main gate 192.168.100.1 (1 hop = same LAN; 2 hops = behind an extender router)' {
    tracert -d -h 4 -w 1500 192.168.100.1
}

# ---------- 4. Printers ----------
Invoke-Safe "4. Printers on TCP $PrinterPort (connect + close, zero bytes, nothing prints)" {
    foreach ($ip in $Printers) {
        $ping = Test-Connection -ComputerName $ip -Count 2 -Quiet -ErrorAction SilentlyContinue
        $tcp = Test-TcpConnect -Ip $ip -Port $PrinterPort -TimeoutMs 3000
        $rtt = $null
        try { $rtt = (Test-Connection -ComputerName $ip -Count 2 -ErrorAction Stop | Measure-Object -Property ResponseTime -Average).Average } catch {}
        "$ip`:$PrinterPort  tcp=$tcp  ping=$ping  avg_rtt_ms=$rtt"
        $summary["PRINTER_$ip"] = "tcp=$tcp ping=$ping"
    }
}
Invoke-Safe '4b. tracert to each printer (1 hop = same LAN as this PC)' {
    foreach ($ip in $Printers) { "--- $ip ---"; tracert -d -h 4 -w 1500 $ip }
}
Invoke-Safe '4c. Test-NetConnection detail for each printer' {
    foreach ($ip in $Printers) { Test-NetConnection -ComputerName $ip -Port $PrinterPort -WarningAction SilentlyContinue | Format-List ComputerName, RemoteAddress, InterfaceAlias, SourceAddress, PingSucceeded, TcpTestSucceeded }
}

# ---------- 5. Internet presence ----------
Invoke-Safe '5. Internet presence (proves the offline phase is really offline)' {
    $dns = $null
    try { $dns = (Resolve-DnsName -Name 'example.com' -Type A -DnsOnly -ErrorAction Stop | Select-Object -First 1).IPAddress } catch { $dns = 'FAIL: ' + $_.Exception.Message }
    "DNS example.com -> $dns"
    $t53 = Test-TcpConnect -Ip '8.8.8.8' -Port 53 -TimeoutMs 3000
    $t443 = Test-TcpConnect -Ip '1.1.1.1' -Port 443 -TimeoutMs 3000
    "tcp 8.8.8.8:53 = $t53 ; tcp 1.1.1.1:443 = $t443"
    $online = ($t53 -eq 'reachable' -or $t443 -eq 'reachable')
    "INTERNET = $online"
    $summary['INTERNET'] = $online
    if ($Phase -eq 'offline' -and $online) { "WARNING: phase=offline but the internet is still reachable - the cable pulled was not the WAN uplink" }
    if ($Phase -eq 'online' -and -not $online) { "WARNING: phase=online but no internet - check the uplink before judging anything else" }
}
Invoke-Safe '5b. Local name resolution while offline (router DNS, mDNS)' {
    $h1 = $(try { (Resolve-DnsName -Name $env:COMPUTERNAME -ErrorAction Stop | Select-Object -First 1).IPAddress } catch { 'no answer' })
    $h2 = $(try { (Resolve-DnsName -Name ($env:COMPUTERNAME + '.local') -ErrorAction Stop | Select-Object -First 1).IPAddress } catch { 'no answer' })
    "hostname -> $h1"
    "hostname.local (mDNS) -> $h2"
}

# ---------- 6. Ports / processes ----------
Invoke-Safe '6. Listening TCP ports that collide with Edge (80, 443, 8443, 3306, 8090, 8091)' {
    $edgePorts = 80, 443, 8443, 3306, 8090, 8091
    $rows = Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue | Where-Object { $edgePorts -contains $_.LocalPort } | Sort-Object LocalPort
    if ($rows) {
        $rows | ForEach-Object {
            $pname = try { (Get-Process -Id $_.OwningProcess -ErrorAction Stop).ProcessName } catch { '?' }
            "port $($_.LocalPort) on $($_.LocalAddress) pid $($_.OwningProcess) ($pname)"
        }
        $summary['PORT_CONFLICTS'] = ($rows | ForEach-Object { $_.LocalPort } | Sort-Object -Unique) -join ','
    } else { 'none'; $summary['PORT_CONFLICTS'] = 'none' }
}
Invoke-Safe '6b. Online Print Agent / MySQL / nginx processes (name, pid, exe path only - no command lines, no config)' {
    Get-Process -ErrorAction SilentlyContinue | Where-Object { $_.ProcessName -in @('node', 'mysqld', 'nginx', 'php', 'php-cgi', 'httpd') } |
        Format-Table ProcessName, Id, StartTime, Path -AutoSize
    $node = @(Get-Process -Name node -ErrorAction SilentlyContinue)
    $summary['NODE_PROCESSES'] = $node.Count
}
Invoke-Safe '6c. Scheduled tasks mentioning Bingoo / print (read-only list)' {
    Get-ScheduledTask -ErrorAction SilentlyContinue | Where-Object { $_.TaskName -match 'bingoo|print|edge' } | Format-Table TaskName, State, TaskPath -AutoSize
}
Invoke-Safe '6d. Inbound ICMPv4 (ping) firewall rules - explains a failed ping FROM a cashier device TO this PC' {
    Get-NetFirewallRule -Direction Inbound -Enabled True -ErrorAction SilentlyContinue |
        Where-Object { ($_ | Get-NetFirewallPortFilter -ErrorAction SilentlyContinue).Protocol -eq 'ICMPv4' } |
        Format-Table DisplayName, Profile, Action -AutoSize
}

# ---------- Summary ----------
Write-Section 'SUMMARY'
$summaryText = ($summary.GetEnumerator() | Sort-Object Name | ForEach-Object { "$($_.Name) = $($_.Value)" }) -join "`r`n"
Write-Out $summaryText
$summaryText | Out-File -FilePath (Join-Path $outDir 'summary.txt') -Encoding utf8
Write-Host "`r`nDONE. Report: $report"
