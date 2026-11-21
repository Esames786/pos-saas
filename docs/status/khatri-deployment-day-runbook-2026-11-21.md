# Khatri deployment day — step runbook (21 Nov 2026, T-8 h)

One list, in order. Each step says WHO runs it (OWNER = you, in your browser / on the PC; CLAUDE = me, only on your word in chat),
the exact command, the expected result, and the STOP rule. Nothing here has been run on production yet. Phases 1–2 are the Cloud
(20–30 min, from anywhere with internet); phases 3–5 are the Branch Server PC at Khatri (60–90 min, needs internet at the shop for
phase 4). The appliance ends the day as a WARM STANDBY: the counters keep selling on the Online POS exactly as today. No takeover.

Prerequisite state (read-only, true at the time of writing): production `be3693e4` live; candidate `integ/khatri-cloud-edge-20261121`
gated (GO/NO-GO report `docs/status/khatri-cloud-edge-go-no-go-2026-11-21.md`); kit `C:\Users\Dell\.bingoo-edge-release\kits\khatri-20261121\`
on the USB stick; Khatri plan `khatri_restaurant` without `offline_edge`; `EDGE_FEATURE_ENABLED` absent on production.

## Phase 1 — Cloud code goes live (CLAUDE on "GO MERGE" then "DEPLOY NOW", or OWNER by the same commands)

| # | who | command | expect | STOP if |
|---|---|---|---|---|
| 1.1 | CLAUDE | merge the candidate into the production branch, no-ff: `git checkout feat/14d-2-plan-upgrade-requests && git merge --no-ff integ/khatri-cloud-edge-20261121 -m "Merge KHATRI-CLOUD-EDGE: Edge server half (heartbeat/sync/config-refresh/authority/returns/recovery) + shared-view contract fix for PRINT-OFFLINE-HERE-1" && git push origin feat/14d-2-plan-upgrade-requests` | one merge commit; `git diff origin/feat/14d-2-plan-upgrade-requests integ/khatri-cloud-edge-20261121 --stat` = only `docs/system-map` (regenerated) or empty | any conflict, any file outside the candidate |
| 1.2 | CLAUDE/OWNER | `ssh -i ~/.ssh/bingoo_prod root@187.77.140.39` → `cd /var/www/html/pos-saas && git rev-parse HEAD && git status --short \| head` | HEAD = `be3693e4`, tree clean | different HEAD or dirty tree → ask before anything |
| 1.3 | same | **backup**: `php artisan tenants:backup` and `mysqldump --single-transaction --routines $(grep ^DB_DATABASE .env \| cut -d= -f2) \| gzip > /var/backups/khatri-edge-master-$(date +%F-%H%M).sql.gz` (reads the DB name from .env inside the shell; nothing printed) → `ls -la /var/backups \| tail -5` | fresh files with today's time, sizes > 0 | any backup error |
| 1.4 | same | pre-flight: `php artisan migrate:status \| grep -c Pending` → `git fetch origin && git log --oneline -1 origin/feat/14d-2-plan-upgrade-requests` | pending on master before pull = 0; origin head = the 1.1 merge | — |
| 1.5 | same | **deploy**: `bash deploy.sh 2>&1 \| tee /root/deploy-khatri-edge-$(date +%F-%H%M).log` | ends `DEPLOY COMPLETE` with `tenants ok=<census> skipped=0`; master shows 4 migrations run, each tenant 7 | any `SKIP:` line → read it, do not re-run blindly; a lock wait → wait 1 min, re-run deploy.sh (idempotent) |
| 1.6 | same | post-checks: `curl -s -o /dev/null -w '%{http_code}\n' https://khatribiryani.bingoopos.com/login` → 200; `curl -s -o /dev/null -w '%{http_code}\n' -X POST https://$(php artisan tinker --execute='echo config("tenancy.central_domain");')/edge/api/authority/heartbeat` → 401; `tail -50 storage/logs/laravel-$(date +%F).log \| grep -c ERROR` → 0; `php artisan migrate:status \| grep -c Pending` → 0 | all as listed | 500 anywhere → Phase 1 rollback |
| 1.7 | OWNER | open `https://khatribiryani.bingoopos.com/pos` on a counter: the POS loads, the printer-health pill works as before, one real receipt prints once through the agent | unchanged behaviour | double print or missing pill → tell me, rollback |

**Rollback Phase 1 (2 min):** `git reset --hard be3693e4 && bash deploy.sh` (the 11 new tables/columns stay, inert under the old code;
no business rows were written). DB restore only from the 1.3 backups if ever needed.

## Phase 2 — Khatri module, flag, pairing material (OWNER in the browser; CLAUDE for the flag on your word)

| # | who | command / click | expect | STOP if |
|---|---|---|---|---|
| 2.1 | CLAUDE/OWNER | on the server: add `EDGE_FEATURE_ENABLED=true` to `/var/www/html/pos-saas/.env` (one line, `printf 'EDGE_FEATURE_ENABLED=true\n' >> .env` only if `grep -c ^EDGE_FEATURE_ENABLED .env` prints 0) → `bash deploy.sh` again (rebuilds config cache safely, idempotent) | `php artisan tinker --execute='var_dump(config("app.edge_feature_enabled"));'` → `bool(true)` | — |
| 2.2 | OWNER | central admin → **Modules** → `Offline Branch Edge (offline_edge)` → attach to plan **khatri_restaurant** only, enabled. CLI equivalent on your word: `php artisan tinker --execute='$p=\App\Models\Master\Plan::where("code","khatri_restaurant")->firstOrFail(); $m=\App\Models\Master\Module::where("key","offline_edge")->firstOrFail(); $p->modules()->syncWithoutDetaching([$m->id=>["is_enabled"=>true]]); echo $p->enabledModules()->pluck("key")->implode(",");'` | the printed list contains `offline_edge`; no other plan changed (`Plan::whereHas("enabledModules", fn($q)=>$q->where("key","offline_edge"))->pluck("code")` = only khatri_restaurant) | another plan gained it |
| 2.3 | OWNER | Khatri tenant (owner login) → Settings → **Offline Edge** (`/settings/offline-edge`) | page opens, branch 1 listed, 0 devices | 403/404 → 2.1/2.2 incomplete |
| 2.4 | OWNER | Offline Edge → branch 1 → **Generate pairing code** (valid 15 min: generate it only when the PC is at step 4.2) | code shown once | — |
| 2.5 | OWNER | Offline Edge → **Enrollment assertion** for: primary operator, backup operator, each cashier, the approver with `may_approve_pos` | one assertion text per user | — |

**Rollback Phase 2:** detach the module from the plan (same screen / `detach`), remove the .env line, `bash deploy.sh`.

## Phase 3 — Branch Server PC preparation (OWNER on site, elevated PowerShell where marked; ≈ 45 min)

| # | command | expect |
|---|---|---|
| 3.1 | plug the PC into the main gate's LAN (`192.168.100.x`); DHCP reservation for its MAC on the main router → `ipconfig /release; ipconfig /renew; ipconfig \| findstr IPv4` | the reserved `192.168.100.___` |
| 3.2 | `Test-NetConnection 192.168.100.206 -Port 9100` (and `.69`, `.218`); `Test-NetConnection khatribiryani.bingoopos.com -Port 443` | all `TcpTestSucceeded : True` |
| 3.3 | MySQL from the kit (`mysql\README-MYSQL.md` steps 1–4; step 3 elevated): `Get-Service BingooEdgeMySQL` | `Running`; `bingoo_edge_local` + user `bingoo_edge`; password file `C:\BingooEdge\secrets\edge.pass` |
| 3.4 | certificates (`KHATRI-INSTALL-WORKSHEET.md` §5): `New-EdgeBranchCA.ps1 -BranchName Khatri-01 -OutDir C:\BingooEdge\ca` → `New-EdgeServerCertificate.ps1 -Hostname bingoo-edge.local -ReservedIp 192.168.100.___ -CaThumbprint <thumb> -OutDir C:\BingooEdge\certs -ExportPfx -PfxPasswordFile C:\BingooEdge\secrets\pfx.pass` | `.pfx` in `C:\BingooEdge\certs`, public `khatri-01-ca.crt` |
| 3.5 | network profile Private: Settings → Network → the connection → **Private** | `Get-NetConnectionProfile` → Private |
| 3.6 | owner writes the secret files (Notepad, one line each): `C:\BingooEdge\secrets\recovery.key` (your chosen recovery key, also in your password manager), `C:\BingooEdge\secrets\enrol.jwt` (2.5, primary operator's assertion), `C:\BingooEdge\secrets\pairing.code` (2.4, generated now) | files exist; never shown to me |

## Phase 4 — Install the appliance (OWNER, elevated PowerShell, internet ON; ≈ 15 min)

```powershell
cd <USB>\BingooEdge-0.8.3-edge\scripts
.\Install-EdgeAppliance.ps1 -PackageRoot <USB>\BingooEdge-0.8.3-edge `
  -DbHost 127.0.0.1 -DbPort 3306 -DbName bingoo_edge_local -DbUser bingoo_edge -DbPasswordFile C:\BingooEdge\secrets\edge.pass `
  -CloudUrl https://khatribiryani.bingoopos.com -PairingCodeFile C:\BingooEdge\secrets\pairing.code -DeviceName KHATRI-BRANCH-SERVER-1 `
  -EnrollmentAssertionFile C:\BingooEdge\secrets\enrol.jwt -RecoveryKeyFile C:\BingooEdge\secrets\recovery.key `
  -LanHostname bingoo-edge.local -LanIp 192.168.100.___ -CertPfx C:\BingooEdge\certs\<file>.pfx -CertPfxPasswordFile C:\BingooEdge\secrets\pfx.pass `
  -HttpsPort 8443 -HttpPort 80
```
Expect, in order: package signature OK → layout → `appliance.env` written → db-init (232 migrations) → **pair** (device id printed) →
**bootstrap-pull** (snapshot id, sections, config revision 1, ack ok) → gateway cert → service plan → tasks registered under
`NT AUTHORITY\LOCAL SERVICE` → health. Then:

| # | command | expect | STOP if |
|---|---|---|---|
| 4.1 | `.\Get-EdgeHealth.ps1` (or `& "C:\Program Files\Bingoo Edge\php\php.exe" "C:\Program Files\Bingoo Edge\artisan" edge:local:health`) | `STANDBY_READY`, authority `standby`, lease holder `cloud`, problems `none`, printers `reachable` | anything else: send me the output, change nothing |
| 4.2 | firewall (elevated): `New-NetFirewallRule -DisplayName "Bingoo Edge gateway" -Direction Inbound -Action Allow -Protocol TCP -LocalPort 8443,80 -Program "C:\Program Files\Bingoo Edge\gateway\nginx.exe" -Profile Private,Public` | rule listed | — |
| 4.3 | Cloud side (OWNER browser): Settings → Offline Edge → devices | `KHATRI-BRANCH-SERVER-1`, last heartbeat < 1 min | no heartbeat after 2 min → send me the health output |
| 4.4 | `Get-ScheduledTask \| Where-Object TaskName -like 'Bingoo*'` | gateway, web ×2, print worker, sync sender, authority worker, backup — all Ready/Running | — |

**Rollback Phase 4:** `.\Uninstall-EdgeAppliance.ps1` + revoke the device on the Cloud page. The counters never stopped selling online.

## Phase 5 — Cashier devices + evidence (OWNER; ≈ 15 min)

| # | command | expect |
|---|---|---|
| 5.1 | on each cashier device: install `khatri-01-ca.crt` → Trusted Root (current user); hosts line `192.168.100.___  bingoo-edge.local` (elevated Notepad) | — |
| 5.2 | browser on the device: `https://bingoo-edge.local:8443/edge/local/health` | green padlock, `STANDBY_READY` |
| 5.3 | `scripts\Test-EdgeCashierTrust.ps1 -Hostname bingoo-edge.local -Port 8443` from a Windows cashier PC | OK |
| 5.4 | evidence to USB: installer transcript, health output, device id, bootstrap snapshot id, task list, firewall rule, photo of the health page on a cashier device, the survey reports | folder on the stick |

## What does NOT happen today

- No `edge:local:authority-takeover`, no Local Mode, no cashier sale on Edge (D-D: the LAB acceptance on this same 0.8.3 package comes first; the operators' takeover/handback drill is then done with you present).
- No change to Khatri printers, routing, the Online Print Agent, menu, terminals, other tenants.
- `AUTO_FAILOVER_ENABLED=no` stays. If the shop internet drops tonight, the counters are exactly where they were yesterday (Online POS down, counter prints its own slips) — Edge does nothing until an operator is trained and you say so.

## Timeline for the 8 hours

| T | what |
|---|---|
| now → T-7h | full MySQL suite ends; GO/NO-GO final line; merge prepared on a release branch (not pushed to production) |
| on your "GO MERGE" | 1.1 (5 min) |
| on your "DEPLOY NOW" | 1.2–1.6 (20 min), you do 1.7 from any counter |
| on your "ENABLE KHATRI" | 2.1 + 2.2 (10 min); 2.3–2.5 are your clicks |
| at the shop | Phase 3 → 4 → 5 (≈ 75 min) |
