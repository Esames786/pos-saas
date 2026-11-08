# REAL Offline LAB acceptance — one-page executable checklist (prepared 8 Nov 2026; NOT authorized to run yet)

Owner rule: **no takeover, no Local Mode, no handback without the owner's separate, explicit authorization**, given with the
owner present. Every numbered step is read back to the owner before it runs; steps marked **GO** need the owner's word at
that moment. Nothing production; tenants Khatri/Kashif/Tawakal untouched. Verified TLS only (`scripts\labhttp.php`).

Actors: appliance host DESKTOP-0024EPM (192.168.1.9; LAB Cloud :9701, gateway :8443, FakePrinter 127.0.0.1:9100);
REAL second laptop "nb laptop" (192.168.1.24, trusts `lab-ca.crt`, hosts entry) — the cashier sits here;
cashier LAB2C5D (owner types the credential), approver LABMF2DE (Phase C PIN, when provisioned).

| # | step | command / action (appliance host unless stated) | pass when | evidence |
|---|---|---|---|---|
| 0 | **Baseline** | `edge:local:health --json`, `edge:local:sync-status --json`; `lab-snapshot.sql` → `T_acc0`; dump the three LAB schemas (SHA256SUMS); Cloud: tenant sales count + last sale no; hourly backup `completed` < 60 min | STANDBY_READY, authority standby/online, outbox pending 0, open shifts 0, T_acc0 saved | `evidence\offline-acceptance\<ts>\00-*` |
| 1 | **Authority/lease checks** | `edge:local:authority-status` (lease holder cloud, heartbeat ack < 60 s, lease TTL/skew sane, `AUTO_FAILOVER_ENABLED=no`), LAB Cloud `/up` 200 | all green | `01-authority.txt` |
| 2 | **GO — supervised takeover** | owner's word; the documented supervised takeover command on the appliance (manager credential typed by the owner), then WAN loss **simulated only as approved** (LAB Cloud paused by its recorded pid, never a cable/router change) | `authority.state=local_active`, badge LOCAL MODE ACTIVE, `can_mutate=true`, laptop page repaints within 20 s (F-1 poll) | `02-*` |
| 3 | **Open shift from the REAL laptop** | cashier on the laptop: Open shift (Lab Counter 1, float as owner says) | `shifts` row open, badge "Shift open", business date correct | `03-shift.png`, SQL |
| 4 | **LAB-only cash sale** | laptop: add 1× Lab Burger (+1 item as owner says) → Review & Pay → cash → complete | `sales_orders` +1 paid, `sale_payments` +1, local sale no issued, stock moved locally | `04-sale.png`, SQL |
| 5 | **Print on the LAB FakePrinter** | receipt printed from the sale (auto or Print) ; KOT if the item has a kitchen route | `print_jobs` printed, FakePrinter bytes grew, worker `delivered` +1 | `05-print.txt` |
| 6 | **Confirm local sales/outbox** | `edge:local:sync-status --json`; SQL on `edge_sync_outbox` | outbox pending = 1 sale (+ shift/payment families), failed 0, `local_selling_safe=true` | `06-outbox.txt` |
| 7 | **GO — reconnect Cloud** | resume the LAB Cloud (same recorded identity); watch `connection_state` → CONNECTION RESTORED → SYNCHRONIZING | heartbeats acknowledged again | `07-reconnect.txt` |
| 8 | **Exactly-once ingestion** | SyncSender runs (2-min period, or `edge:local:sync-run`); Cloud tenant DB: the sale appears ONCE (`edge_sale_uuid` unique), outbox entries → acknowledged; re-run the sender | Cloud sales +1 exactly, outbox pending 0, second run acknowledges nothing new | `08-sync.txt`, SQL both sides |
| 9 | **Reconcile** | `edge:local:reconcile` (or the Cloud reconcile endpoint); compare Cloud vs Edge: sale totals, payments, inventory movements for the sold items, shift state/float/cash | 0 differences; `reconcile_clean_at` set | `09-reconcile.txt` |
| 10 | **GO — controlled handback** | close the shift on the laptop (cash count as owner says) **first**, then the documented handback command (owner's word) | `authority.state` handing_back → standby; badge "RETURNING TO ONLINE" then "STANDBY · CLOUD AUTHORITY"; laptop page repaints (F-1), Review & Pay disabled | `10-handback.txt` |
| 11 | **STANDBY_READY restored** | `edge:local:health --json`; `lab-snapshot.sql` → `T_acc1`; diff vs `T_acc0` = exactly the sale/shift/outbox/print rows of this exercise; hourly backup completes | STANDBY_READY, problems=[] , Cloud authority, outbox 0 | `11-after.txt` |

Abort/rollback at any step: stop, do not improvise; record the state; the owner decides (handback is itself the rollback for
steps 2–9 once the Cloud is reachable; nothing is deleted on either side).

Prerequisites still open before step 0 can be scheduled: Phase C (temporary admin → approver PIN for W07/W08, needs
`secrets\admin.pass`); the owner's decision on running the exercise on 3306 (as today) or after the 3317 switchover
(`docs/plans/lab-mysql-switchover-2026-11-07.md`); F-1 UX gate deployed to the LAB only if a packaged release is made
before the demo (owner: not solely for F-1 — the exercise works without it; the 422 refusals are then only in the log).
