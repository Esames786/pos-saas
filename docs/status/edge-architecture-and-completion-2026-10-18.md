# OFFLINE EDGE — ARCHITECTURE, LAB WIRING, AND HOW MUCH IS LEFT (18 Oct 2026)

Written for the owner and GPT. Facts come from the repo (routes/edge.php, routes/edge_runtime.php, config/edge.php, the LAB scripts in
C:\Users\Dell\BingooEdgeLab) and the Phase 3/4 reports. Secrets are never listed here.

## 1. The product in one picture

```
                           INTERNET / WAN (yellow cable)
                                     |
 +-----------------------------------v------------------------------------------+
 |  BINGOO CLOUD (official financial truth)   tenant site: https://<tenant>.bingoopos.com   |
 |                                                                                 |
 |  Online POS  /pos  (ONE shared cashier Blade: resources/views/tenant/pos/index)   |
 |  Cloud runtime adapter: CloudPosRuntimeFactory -> /pos, /held-sales, /printing/... |
 |  Manager approval: manager PIN -> ManagerApprovalService (self-approval refused,   |
 |     approver must hold the action's permission)                                  |
 |  Device API  /api/edge/*  (device-authenticated, epoch-fenced):                   |
 |     pair, device/me, bootstrap/snapshots..., config/refresh, compatibility/report,|
 |     authority/heartbeat + handback, sync/sales, sync/reconcile, sync/baseline,    |
 |     returnable/refresh + sync/returns, supplier-finance/*, purchase-returns/*,     |
 |     backup/recovery-keys                                                         |
 |  Owner screens: /settings/offline-edge (pairing codes, devices, version reported,  |
 |     revoke), /settings/offline-edge/security                                      |
 +----------------------------------------^--------------------------------------+
                                          | HTTPS, device secret, lease heartbeat every ~20 s
                                          | (TTL 120 s + 30 s skew)
 +----------------------------------------v--------------------------------------+
 |  BRANCH SERVER (Windows appliance, "Bingoo Edge")   install: C:\...\BingooEdge  |
 |  nginx gateway  https://<hostname>.local:8443  (TLS)  -> PHP web workers         |
 |     cashier login  /edge/local/login      POS  /edge/local/pos  (SAME Blade)     |
 |     health         /edge/local/health     finance screens (suppliers, journal,  |
 |     purchase returns), logout - reached from the menu behind #pos-sidebar-toggle |
 |  Edge runtime adapter: EdgePosRuntimeFactory -> /edge/local/pos/* twins of every |
 |     Online endpoint; capability flags; status slot STANDBY / LOCAL MODE          |
 |  Local DB  bingoo_edge_*  (MySQL): tenant tables + edge_* tables                 |
 |  Workers (Windows tasks): Gateway, Web1, Web2, PrintWorker, SyncSender,           |
 |     AuthorityWorker, Backup                                                      |
 |  Authority: lease state machine (STANDBY_READY / LOCAL_ACTIVE / HANDING_BACK),   |
 |     takeover only after the lease lapses, controlled handback                    |
 +---------------------+--------------------------------------------+-------------+
                       | TCP 9100 (ESC/POS) per printer             | LAN
             +---------v----------+                       +---------v-----------+
             | KOT printer(s)     |                       | cashier laptop(s)   |
             | receipt printer(s) |                       | browser -> :8443    |
             +--------------------+                       +---------------------+
```

The SAME cashier page runs in both places. The only switch is `window.POS_RUNTIME` (mode cloud | edge): routes, capabilities,
authority labels, manager credential type (PIN on Cloud, employee code + Edge credential on the appliance), asset base.

## 2. The two modes of the appliance

```
 STANDBY (normal day)                                  LOCAL MODE (WAN down, after takeover)
 ---------------------------------------------         ----------------------------------------------------
 Cloud is the writer. Cashiers use the ONLINE POS.     Appliance is the writer for its branch ONLY.
 Appliance: heartbeat -> Cloud ACK (lease renewed,     Cashiers log in at https://<host>:8443 and use
   capabilities advertised, e.g. customer_create);       /edge/local/pos (identical screen).
   config refresh pulls menu/prices/users/roles/        Sales, holds, KOT, table ops, cash tenders, returns
   permissions/printers/tables/customers/aliases;        (cash), discounts with manager approval, shift
   returnable-sale mirror for returns; backups.          open/close, add-customer (Phase 4) - all local.
 Every mutation on /edge/local/pos/* is REFUSED         Every sale -> outbox envelope (sale_uuid + hash).
   (422 STANDBY) - no split brain.                      Card / bank / cheque / email: never faked - refused
                                                          with a hint (Online needed). Finance posting
 Takeover: only when the lease has LAPSED on the          (daily closing, journals) stays on the Cloud.
   Cloud side and an operator runs                       Printing: local print_jobs -> PrintWorker -> TCP 9100.
   edge:local:authority-takeover --confirm --by=...
   (AUTO_FAILOVER_ENABLED=no).                          Handback (WAN back): edge:local:authority-handback
                                                          [--assess] --by=... -> SyncSender pushes the outbox
                                                          (exactly-once: sale_uuid + content_hash, Cloud
                                                          ingestion ledger) -> Cloud is the writer again ->
                                                          STANDBY_READY. Cloud remains the financial truth.
```

Security: one-time pairing code from /settings/offline-edge; device secret and activation epoch; every Edge credential is
epoch-fenced; approvals need an eligible approver (manager PIN on the Cloud -> `may_approve_pos` in the bootstrap), never the
requester, never a deactivated user; approvals are one-time, replay refused.

## 3. The HOME LAB as it is wired today (all on the owner's laptop + second laptop)

```
 LAB CLOUD (plays bingoopos.com)      http://127.0.0.1:9701  (tenant host edgehomelab.localhost:9701)
    code: export of 7b8f886 (0.7.0)   DBs: pos_lab_master_edge / pos_lab_tenant_edge   script Start-LabCloud.ps1
    -> the appliance pairs to it: central API http://localhost:9701/api/edge/...

 LAB APPLIANCE (0.7.0-edge, STANDBY_READY)                 install C:\Users\Dell\BingooEdgeLab\install\BingooEdge
    gateway  https://DESKTOP-0024EPM.local:8443 (TLS, cashier laptops)   http fallback :8081
    web workers  127.0.0.1:8090 / :8091   DB bingoo_edge_lab_local
    workers  Start-EdgeLabWorkers.ps1 (Gateway, Web1, Web2, PrintWorker, SyncSender, AuthorityWorker, Backup)
    printers configured on the appliance: network KOT + receipt printers at 127.0.0.1:9100  ---+
                                                                                               |
 FAKE PRINTER  Start-FakePrinter.ps1  -> TCP listener 127.0.0.1:9100  <----------------------+
    (tests/MySql/Support/fake_printer.php as a hidden persistent process)
    every accepted connection's bytes are APPENDED to C:\Users\Dell\BingooEdgeLab\printer\fake-printer.bin
    -> proves the application/transport path (job -> PrintWorker -> ESC/POS over TCP -> one delivery, no duplicates)
    -> does NOT prove a physical LAN printer (PHYSICAL_PRINT_CERTIFIED=no until a real printer is used)

 DEV ONLY (Phase 2-4 proofs, disposable, never the LAB appliance):
    dev Cloud 9702  http://edgehomelab.localhost:9702  = live worktree on the LAB DBs (render checks only)
    dev Cloud 9704  http://edgehomelab.localhost:9704  = live worktree on a CLONE (pos_devonline_*) - Online browser proofs
    dev Edge  8095  http://127.0.0.1:8095/edge/local/pos  = worktree as a branch_server, dev seed (bingoo_edge_devtest_local)
    dev Edge  8096  http://127.0.0.1:8096/edge/local/pos  = worktree PAIRED to the 9704 clone (same dataset as Online)
```

Printing path in detail (identical code on Cloud and Edge; only the delivery differs):

```
 cashier completes sale / sends KOT  ->  print_jobs row(s) (document, printer, logical key, copy no)
   ONLINE (Cloud):  print agent on the shop PC (PRINT-AGENT 2.6.x) polls the Cloud and prints; "Print Here" browser fallback
   EDGE   (local):  BingooEdgePrintWorker (edge:local:print-worker) claims the job -> ESC/POS over TCP ip:port
                    -> LAB: 127.0.0.1:9100 = FakePrinter (bytes appended)   -> shop: the real network printer
   retry rule (same text both sides): "Only failed or cancelled jobs can be retried."; reminder plan; exactly-once delivery
```

## 4. What is complete, what is left (approved scope only)

| Workstream | State | Done |
|---|---|---|
| Sync engine (outbox, envelopes, exactly-once ingestion, baseline, reconcile, returnable mirror, supplier finance, purchase returns) | shipped in 0.6.0/0.7.0 | 100 % |
| Authority: lease, state machine, supervised takeover, controlled handback, no split brain | shipped, LAB-proven 26 Sep | 100 % |
| Appliance: installer, gateway TLS, workers, backups, recovery keys, signed updates | shipped; updater defect FIXED in source (e45b8b7), not yet shipped | 95 % |
| Cashier POS parity: ONE shared Online view on Edge, old page deleted, 17/17 workflows on both runtimes, geometry 0 diff | source complete, gates green (Phase 3) | 100 % (source) |
| Security: approver eligibility contract, self-approval refused, action permission (Cloud + Edge) | source complete | 100 % (source) |
| Offline add-customer (uuid, phone normaliser, envelope v3, create-or-match, aliases, refresh by uuid) | built, gates green, UNCOMMITTED (classifier blocked the commit) | 95 % |
| Release 0.8.x: merge assessment vs canonical 70c4e43, signed package, dry-runs, pre-install report | not started | 0 % |
| LAB update + second-laptop paired acceptance (same dataset), re-test return / shift count / add-customer / handback | not started (needs owner approval) | 0 % |
| Physical printer certification (real LAN printer instead of FakePrinter) | not started | 0 % |
| P6 production pilot (first real branch) | not started (owner gate) | 0 % |
| Out of scope by owner decision: offline card/bank/cheque tenders, daily closing / journals offline, customer/printer admin on Edge | — | n/a |

Weighted estimate for the approved pilot scope: **about 80–85 % done**. Remaining effort, in order:
1. commit Phase 4 and re-run the Edge browser proof (hours);
2. merge/rebase assessment against canonical and a green full gate on the merged tree (1–2 days: canonical moved a lot);
3. signed 0.8 package + update/rollback dry-runs + pre-install report (1 day);
4. owner-approved LAB update (Cloud first, then appliance), LAB data fixes (approver PIN + permissions), second-laptop acceptance (1–2 days with the owner);
5. physical printer certification (needs a real printer in the lab);
6. P6 pilot planning (owner decision).

## 5. Owner decisions still open

1. Gap C — standby Recent Orders mirror (cancelled sales + table label).
2. LAB tenant data before the update (approver PIN + action permissions; missing shift/split/quick-store permission rows).
3. Allow the Phase 4 commit (the tool's permission classifier blocked it twice).
4. Go for the release build after the merge assessment.
