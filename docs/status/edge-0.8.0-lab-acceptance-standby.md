# Bingoo Edge 0.8.0-edge — LAB acceptance in warm standby, WITHOUT takeover (Team FB4, 24 Oct 2026)

Scope: the owner's "verify the upgraded LAB without takeover" step on the REAL LAB pair after the 0.8.0-edge update
(`edge-0.8.0-lab-update-record.md`). LAB Cloud `http://127.0.0.1:9701` (export `cloud\src-ac5b168d`, tenant `edgehomelab`,
`pos_lab_master_edge` / `pos_lab_tenant_edge`); LAB appliance `https://127.0.0.1:8443` (0.8.0-edge, STANDBY_READY, bootstrap v8,
config revision 9, 7 workers running, FakePrinter 127.0.0.1:9100). Rules kept: no Local Mode, no takeover/handback, no worker or
Cloud stop/start, no permission/PIN/mapping change, SQL = SELECT only, secrets read from `secrets\*.pass` into env, never printed.
Evidence: `C:\Users\Dell\BingooEdgeLab\evidence\release-0.8.0-edge\acceptance\` (`00-baseline-before.txt`, `01-online-full-run.log` +
`online\report.json`, `02-online-blocked-diagnosis.txt`, `03-edge-standby-run.log` + `edge-standby\report.json` + shots,
`04-appliance-wedge\*`, `05-counts-after.txt`).

## Status: STOPPED on a product defect found on the real LAB appliance (step 3) — owner action needed

```
ONLINE_LAB_ACCEPTANCE   = NOT_RUNNABLE (environment, not a product defect): on http://127.0.0.1:9701 the login POST answers 419 and
                          GET /pos 404 for every workflow -> no login, no sale created. W01..W17 FAIL/SKIP for that single reason.
                          Cause: the running LAB Cloud (scripts/Start-LabCloud.ps1) sets TENANT_BASE_DOMAIN=127.0.0.1 and
                          SESSION_DRIVER=array; tenant web routes are mounted on {subdomain}.<base> (routes/tenant.php:111) ->
                          host edgehomelab.127.0.0.1 (400), edgehomelab.localhost (404); an array session cannot carry a login.
                          The LAB Cloud was started as the appliance's API/sync target only; fixing it needs a LAB Cloud restart
                          (outside this authorization).
BLOCKED_PIN             = W07 (not executed on purpose, see below), W08 (not reached). Precondition fact: manager_pins has 0 rows
                          for the LAB tenant -> Online approvals would answer 422 "Invalid manager PIN." (ManagerApprovalService).
W18_ONLINE              = NOT_RUN (same Online block); Reminder Pause preconditions verified by SELECT: lab.cashier holds
                          tenant.pos.pause-reminders; category_printer_mappings id 4 = reminder rule, terminal 1 -> printer 1
                          "LAB FakePrinter (TCP 9100)" supports_reminder=1 active.
APPLIANCE_STANDBY       = W01 PASS; W15 FAIL (gateway 504); W18 FAIL (gateway 502/504) -> both failures are the appliance web tier
                          wedging, NOT the STANDBY 422 negatives (never reached). Defect F-FB4-1 below.
  W01 facts             = slot "STANDBY · CLOUD AUTHORITY", pending "0 pending sync"; terminal "Lab Counter 1" (id 1), branch
                          "Home Lab Branch"; 15 tiles, 15/15 with the SKU line (LABFAMILY, LABTEA, LAB-36A73F); capabilities
                          reminderPause true, customerCreate true, customerAddressCreate true, websiteOrders false, partnerShare
                          false, returnWithoutBill false, nonCashTender false, quickReportEmail false, splitBill true,
                          salesReturn true, printHere true; #pos-reminder-toggle present, no disabled attr, no title (wrap hidden
                          while no shift is open); #web-orders-btn present+disabled with the D4 hint; #qr-email "Send to owner"
                          disabled with the E1 hint; disabled-with-hint census: #branch_id, #web-orders-btn, #qr-email (+ the
                          #bill-preview-btn tooltip); no medical-counter chrome; login 302 -> /edge/local/pos; managerCredential
                          employee_code_and_credential; 0 fenced 409s. Manager-approval prompt shape: not exercised (no
                          approval-gated action is reachable in W01/W15/W18).
EDGE_VS_ONLINE_PIXELS   = NOT_RUN (Online side not reachable; appliance web tier wedged before the captures)
EDGE_VS_ONLINE_GEOMETRY = NOT_RUN (same). Note for the next attempt: 087278e9..ac5b168d touched no cashier-view file, so Team FB2's
                          online-baseline-087278e9 / geometry-087278e9\online remain a valid Online proxy for ac5b168d.
FAKEPRINTER_BYTES       = 24105 before (file mtime 2026-10-03 02:34) -> 24105 after every step. Nothing printed: the Online run never
                          logged in, the appliance in STANDBY prints nothing, and the Cloud's print path is not the LAB FakePrinter.
LAB COUNTS before/after = Cloud sales_orders 5 -> 5, ingestions 5 -> 5, shifts 0 -> 0 (0 open), customers 2 -> 2, print_jobs 0 -> 0,
                          lease holder cloud / standby / fenced NULL / released NULL (heartbeat live) both times;
                          appliance sales_orders 6 -> 6 (rowset MD5 unchanged), open shifts 0 -> 0, customers 2 -> 2,
                          print_jobs 33 -> 33, outbox 5/5 acknowledged -> 5/5; edge_local_meta edge-bootstrap-v8 / edge-config-v1 /
                          revision 9 (seen 9) / standby / online / bootstrapped / last_error NULL both times.
LOCAL_MODE_ENTERED      = NO
```

## F-FB4-1 (DEFECT, real LAB): both appliance web backends wedge after ~37 requests each; the gateway answers 502/504

What happened (12:52:48 local / 07:52:48 UTC, during W18's first request after W01): `GET /edge/local/pos/shift?terminal_id=1`
never answered (nginx `upstream timed out` after 120 s -> 502), the trailing 404 asset requests of the W01 page wedged the second
backend at the same moment, and from then on every request to `https://127.0.0.1:8443` (health included) times out: nginx
`gateway-error.log` shows both upstreams 8090/8091 "timed out while reading response header" and "no live upstreams"; direct probes
of 8090/8091 hang too. The appliance is still wedged at the time of writing (health 000 after 5 s at 08:21 UTC) while the authority
worker (tick 139, `standby_freshness` all current), sync sender, print worker and the LAB Cloud keep running normally and the LAB
databases are unchanged. In a real branch this means: the Branch Server's cashier UI is unreachable while in standby (Cloud sales
unaffected), and a takeover would have no working web tier.

Facts gathered (all read-only):

| fact | value |
|---|---|
| gateway requests since the 12:32 worker start until the wedge | 74 (2 at 12:34, 1 at 12:37, 71 at 12:52) = 2 backends x 37 |
| wedged backends (pids 160868 / 187884, `php -S 127.0.0.1:8090/8091`) | alive, 1 thread each, CPU delta 0 ms over 8 s, NO sockets except the listen socket and nginx's CloseWait leftovers, thread wait reason Executive (kernel synchronization wait) |
| their supervisors (pids 188456 / 199008, `edge:local:serve --worker=1/2`) | thread wait reason UserRequest in 6/6 samples (never DelayExecution), i.e. blocked in a read — a healthy 200 ms `usleep` loop samples as DelayExecution like the authority and print workers do |
| MySQL | 0 lock waits, only sleeping connections; the SELECT counts above |
| appliance file locks | probe over 59 files under `runtime\versions\0.8.0-edge\storage\framework`, `bootstrap\cache`, `data\BingooEdge\{backups,config,logs}`, `printer\`: 0 locked |
| appliance logs | `data\BingooEdge\logs\edge-2026-10-24.log` nothing after the 12:32 start; `D:\laragon2\tmp\php_errors.log` (the runtime php.ini's error_log) nothing in the window; `BingooEdgeWeb1/2.out.log` contain only the 139-byte banner although ~37 requests per backend should have been relayed |
| `php.ini` of the appliance runtime | `max_execution_time = 36000`, `default_socket_timeout = 60`, no opcache; it is a copy of the Laragon dev ini (`error_log`, `extension_dir`, `session.save_path`, `include_path` all under `D:/laragon2/...`) — packaging observation, see O-FB4-2 |
| code | `EdgeLocalServeCommand` (unchanged since P4 67d5b733; identical in the 0.8.0 runtime copy and in 0.7.0 7b8f886) spawns the backend with `proc_open(..., [1 => pipe, 2 => pipe])`, calls `stream_set_blocking(false)` on both pipes and relays them with `stream_get_contents` every 200 ms. `EdgeSupervisionPlan` has launched the web backends through this supervisor since P4 (0.7.0 too) |

Reproduction OUTSIDE the LAB (`04-appliance-wedge\repro-*`, scratch port 9655, plain `php -S` child with a trivial router, the
supervisor's exact pipe loop, no appliance component): `stream_set_blocking(false)` returned **false** for both pipes (PHP 8.3.16 on
Windows, `proc_open` anonymous pipes); the parent's heartbeat stopped at `loop=1 before_relay_stdout` (blocked in the "non-blocking"
read of the stdout pipe the CLI server never writes to), stderr was never drained; the child answered **exactly 37 requests** and
timed out on #38 — the CLI server writes one log line per request to stderr and blocks once that pipe is full. The same number as on
the LAB (74 / 2). Classification: product defect in the Windows appliance supervisor (web runtime), present since P4; it surfaces
after ~37 requests per backend from a fresh start. Open question for the owner: the 0.7.0 LAB served 433 (26 Sep) and 352 (27 Sep)
gateway requests with 0 5xx under the same supervisor code — something in how the backends were started then (console vs redirected
output, launcher) differs from today's `Start-EdgeLabWorkers.ps1` launch (`Start-Process -RedirectStandardOutput/-RedirectStandardError`);
not resolved here. Recovery needs a restart of the two web workers (forbidden to this team) — the supervisor restarts a backend only
when it EXITS (no liveness probe), and `max_execution_time = 36000` keeps a wedged backend for up to 10 h.

## Steps as run

| # | step | result |
|---|---|---|
| 1 | baseline (SELECT) 07:37–07:38 UTC | Cloud sales 5 / ingestions 5 / shifts 0 / customers 2 / print_jobs 0; lease cloud-standby; appliance sales 6 / open shifts 0 / customers 2 / print_jobs 33 (all printed) / outbox 5 acknowledged; meta v8 / rev 9 / standby / online; FakePrinter running pid 22824, bytes 24105; LAB Cloud /login 200, appliance /health 200 |
| 2 | Online suite `--mode online --base-url http://127.0.0.1:9701 --user lab.cashier@edgehomelab.test --only W01,W02,W03,W03M,W04,W05,W06,W08,W09,…,W17` (07:41:52–07:50:01 UTC) | login POST **419**, `/pos` **404** -> W01..W17 FAIL/SKIP, nothing created. **W07 was deliberately excluded**: with no approver PIN it would hold a KOT-sent order on a real LAB table BEFORE the refused approval, and the shared ShiftService refuses the close while a held sale/open table exists — W16's only path for that order is "cancel with approver" (also refused), so the run would strand open work that only a manager approval or a forbidden SQL write can clear. W08 was kept (its refused approval leaves nothing stranded) but never reached. The W18 Reminder-Pause pass was not attempted |
| 2b | diagnosis | `02-online-blocked-diagnosis.txt`: central host /login 200 + cookie, /pos 404; `Host: edgehomelab.127.0.0.1` 400; `Host: edgehomelab.localhost` 404; `Start-LabCloud.ps1` lines 20/24 (`TENANT_BASE_DOMAIN=127.0.0.1`, `SESSION_DRIVER=array`); `routes/tenant.php:111 Route::domain('{subdomain}.'.config('tenancy.tenant_base_domain'))` |
| 3 | appliance `--mode edge --base-url https://127.0.0.1:8443 --path /edge/local/pos --user LAB2C5D --manager-user LABMF2DE --only W01,W15,W18` (07:52:15–08:01:48 UTC; TLS via Playwright `ignoreHTTPSErrors`, no `NODE_TLS_REJECT_UNAUTHORIZED` needed — no node-level fetch in the harness) | W01 PASS (facts above; already slow: `/edge/local/pos` 6.0 s, `/shift` 5.4 s vs ≤ 0.7 s on the dev instances); W18: `GET /shift?terminal_id=1` 502 after 120 s, `GET /pos/shared` 504; W15: `GET /pos/shared` 504 -> **STOP** (F-FB4-1) |
| 4 | D5 reference shots / pixel diff / geometry | NOT RUN — Online unreachable, appliance wedged |
| 5 | printing | FakePrinter 24105 -> 24105 bytes throughout; appliance print_jobs 33 -> 33; Cloud print_jobs 0 -> 0 |
| 6 | counts after (SELECT) 08:21 UTC | identical to the baseline (block above); gateway health still 000 |

## Observations

- **O-FB4-1 (LAB environment):** the LAB Cloud as started cannot host a browser POS session (see ONLINE_LAB_ACCEPTANCE). Any future
  Online acceptance on the LAB needs the LAB Cloud restarted with a browser-resolvable tenant host (e.g. `TENANT_BASE_DOMAIN=localhost`
  -> `edgehomelab.localhost:9701`, as the dev clones use) and a persistent session driver; the appliance's API target
  (`EDGE_CLOUD_BASE_URL=http://127.0.0.1:9701`, central domain) is unaffected by that.
- **O-FB4-2 (packaging):** the appliance runtime's `php.ini` is the Laragon dev ini (paths under `D:/laragon2/`), so PHP errors of the
  appliance go to the dev box's `php_errors.log` and `max_execution_time` is 10 h for web requests too.
- **O-FB4-3 (harness):** `--only W01,W15,W18` on the LAB gateway: every page load fans ~60 asset requests (the shared layout's 404
  theme images among them) over HTTP/2 into two single-threaded backends — the 5–6 s page times in W01 are queueing, which also means
  each cashier page load consumes a large share of the ~37-request budget of F-FB4-1.
- Harness `tools/edge-browser-proof/*.mjs` not edited; nothing under `app/ resources/ routes/ tests/` touched; nothing committed by
  this team; no system-map rebuild needed (no schema/route/model change).

## Owner decisions needed

1. F-FB4-1: restart the two LAB web workers (recovers the UI) and schedule the supervisor fix + a release decision (the defect is in the
   0.8.0 package and in 0.7.0).
2. LAB Cloud launch configuration for a browser-reachable Online POS (O-FB4-1), then re-run steps 2, 2-W18 and 4.
3. Approver manager PIN (unchanged prerequisite for W07/W08/W16-cancel on Online).
