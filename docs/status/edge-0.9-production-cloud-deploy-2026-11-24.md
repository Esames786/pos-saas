# Edge 0.9 production Cloud deploy + authority release fix: 24 Nov 2026

**Result: both deployed and verified. Production = `be57aeba` (server and branch `feat/14d-2-plan-upgrade-requests`).**
The Khatri Branch Server stays on 0.8.5-edge. No appliance update, takeover, handback, auto-takeover or release publication.
The lease TTL change to 600 s was **not** applied: the owner put it on hold (24 Nov, ~00:45 PKT).

Readers: the owner and the engineers who run the next window. Times are PKT (UTC+5); the server logs in UTC.

## 1. Authority and gates

- Owner GO: "PRODUCTION CLOUD 0.9 AND AUTHORITY RELEASE FIX" (23 Nov), then "URGENT OWNER GO — START PRODUCTION DEPLOYMENT AT BRANCH CLOSING" (24 Nov ~00:45), then the operator's "Yes, deploy now" (~01:34). The operator was at the terminal throughout.
- **Trading gate:** zero sales created or updated at any tenant in the preceding 15 minutes, checked at 01:05 and again at 01:36 and 01:38.
  - Kashif Food's last activity was 00:50, Khatri's 00:33 and Tawakal's 00:00.
  - The owner confirmed Kashif Food was done billing.
- **Khatri Branch Server:** on and heartbeating throughout. The shop kept the PC on at the owner's request.

## 2. Timeline

| PKT | Step |
|---|---|
| 01:34:31–01:35:53 | Backups: `tenants:backup` for all 13 tenants + master into `storage/app/private/backups/20261123_203436`. Separate master dump `/var/backups/edge09-cloud-master-20261123-2034.sql.gz`, sha256 `4afed452c33e0b536a54f8ddd5795fa73b884a10010f39258b7795a2a6eb5681`, gzip OK, "Dump completed". |
| 01:36 | Precheck: server `ead217f5`, branch `9d93ee96`, 0 pending migrations, Khatri lease holder cloud (10 s), no sales in 15 min. Before-snapshot taken; 3-second lease watch started. |
| 01:36 | Push `9d93ee96..6be3d9a1` to the production branch (fast-forward). |
| 01:36:32–01:37:20 | `deploy.sh` rc 0, "DEPLOY COMPLETE". Log `/root/deploy-edge09-cloud-2026-11-23-2036.log`. |
| 01:37–01:38 | 0.9 verification (§3): all PASS. |
| 01:38 | Push `6be3d9a1..be57aeba` (fast-forward). |
| 01:38:38–01:38:58 | `deploy.sh` rc 0, "Nothing to migrate", "DEPLOY COMPLETE". Log `/root/deploy-authority-release-fix-2026-11-23-2038.log`. |
| 01:39–01:41 | Release-fix verification (§4): all PASS. |

## 3. Edge 0.9 Cloud (`6be3d9a1`)

- **Migrations:**
  - Master: exactly `2026_11_21_090001` … `090005`.
  - Every tenant (13/13, ok=13, skipped=0): exactly `2026_11_21_000001_add_edge_auto_takeover_to_branches`, alone in its batch.
  - 0 pending afterwards.
- **Snapshot compare (`compare.php … 090`): RESULT all PASS.**
  - Master: plans, modules, plan modules and features, tenants, tenant databases and subscriptions identical; offline_edge per plan identical; `edge_releases` 0 rows (nothing published); `edge_setup_sessions` 0; route catalog 842 → 862.
  - Khatri device row: pre-existing columns unchanged (ready, 0.8.5-edge); the 14 new columns are all null.
  - Every tenant: +1 migration; only the Owner role gained the 11 `tenant.offline-edge.*` permissions; settings, payments, printers, users, roles, branches and permissions otherwise identical; auto-takeover 0 on every branch; no business rows changed.
- **Khatri lease watch (3 s, through the deploy):**
  - largest heartbeat age 34 s;
  - never blocked, never fenced, never released;
  - holder cloud; sequence advancing.
- **HTTP** (equal to the 23 Nov baseline):
  - central `/up` 200;
  - Khatri `/login` 200;
  - Khatri `/pos` and `/settings/offline-edge` 302 → `/login`;
  - kashiffood, kashifkitchen, thekashiffoods and tawakkalbiryani `/login` 200;
  - unauthenticated `POST /api/edge/authority/heartbeat` 401.
- **Error log:** 34 ERROR lines today before and after: nothing new.
- **Manager PIN:** `app/Services/Sales/ManagerApprovalService.php` blob `0a7a1644ad89`, the same as before the deploy.

## 4. Authority release fix (`be57aeba` = `494eebd5` tenant lookup + `ced83beb` safe-release guard)

- **Diff vs `6be3d9a1`:** `EdgeAuthorityReleaseCommand.php`, `EdgeAuthorityLeaseService.php`, `EdgeAuthorityLeaseHttpMySqlTest.php` and the two system-map files. No migrations.
- **Tests:** the deployed code and test blobs are identical to `ced83beb`, where the file's 4 tests / 96 assertions passed on 23 Nov. The new test fails on `494eebd5` alone, so it does detect the unsafe release.
- **Snapshot compare (`compare.php … none`): RESULT all PASS.** 0 migrations, 0 permission or role changes, configuration identical. Khatri lease seq 3215 → 3216, holder cloud, not fenced, not released.
- **Safe-release proof on production** (Khatri touched with `--dry-run` only):

```
edge:authority:release khatribiryani 1 --reason=… --by=deploy-verify --dry-run
  Lease: … holder cloud, state standby, seq 3216, last heartbeat 22 s ago, expired no, released no
  DRY RUN: REFUSED LEASE_LIVE — … a live lease is never released. Nothing written.   rc=1
edge:authority:release no-such-tenant 1 …                → Tenant not found.                                rc=1
edge:authority:release khatribiryani 1 --reason=…        → Both --reason and --by are required (audited).  rc=1
edge:authority:release khatribiryani 1 --by=…            → Both --reason and --by are required (audited).  rc=1
edge:authority:release demo 1 …                          → No lease exists for that branch — nothing to release.  rc=0
```

  Khatri's lease afterwards: holder cloud, seq advancing, `fenced_at` null, `released_at` null.

## 5. Rollback (prepared, not used)

- `rollback/edge09-cloud-20261124` (`e732bdb2`, code = `9d93ee96`) and `rollback/authority-release-fix-20261124` (`5a079914`, code = `6be3d9a1`).
- Each is a fast-forward push to the production branch followed by `deploy.sh`.
- `deploy.sh` pulls `--ff-only`, so a server-side reset would be undone by the next deploy.
- Additive tables and columns stay. No down-migration, no force-push.

## 6. Correction to the 23 Nov Khatri incident note

The owner's incident doc ("Khatri Edge incident — cloud POS blocked, 23 Nov 2026") could not be edited from Claude (the connector refused with "forbidden: role"). Its correction is recorded here.
The nginx evidence below was read-only, gathered 23 Nov ~20:20 PKT.

- **5:51–5:58 PM, 455 s gap: a whole-PC outage**, not a slow Edge tick.
  - The print agent on the same PC (DESKTOP-B9MO2QG, ~19 heartbeats a minute) stopped at 12:51:33 UTC and resumed only at 13:01:49 UTC.
  - The Edge worker, which starts at boot, returned at 12:58:41 UTC.
  - Counter browsers on the same shop IP kept working, so the internet was up.
  - The cause (power loss, restart or sleep) is only in that PC's Windows System event log:
    `Get-WinEvent -FilterHashtable @{LogName='System'; Id=41,1074,6005,6006,6008,42,1; StartTime='2026-11-23 17:45'; EndTime='2026-11-23 18:05'}`
- **22 Nov 12:26 PM (131 s) and 23 Nov 12:35 PM (148 s): heartbeat-only delays.** The print agent kept running through both, so only the Edge worker was slow. The heartbeat waits for each tick's refresh work, and the ~10 MB per-tick downloads are the likely contributor.
- **Overnight 22 → 23 Nov:** the PC was switched off after closing (both services silent 20:43–07:24 UTC). Khatri's first sale came 5 minutes after the PC started. While that PC is off, the Cloud refuses Khatri branch-1 sales.
- **What the release changes:** neither the 0.9 Cloud nor the 0.9 appliance changes this. The appliance keeps one loop (heartbeat, then refresh work).
- **Open items:**
  - on site: UPS, sleep set to Never, Windows Update active hours covering trading, PC on before the first sale;
  - engineering: the heartbeat on its own timer (C3), less download churn (C1/C2), a fence alert (C4);
  - the 600 s TTL is on hold pending the owner's confirmation of its takeover and fencing implications.

## 7. Next

- **Read-only checks today:** 11:47 PKT (Khatri lease alive before opening; notify if the PC is off) and 12:53 PKT (sales accepted, no 409s, no new errors).
- **Khatri appliance 0.8.5 → 0.9.0:** Cloud preconditions P1 and P2 of `docs/status/khatri-0.9-maintenance-runbook-2026-11-24.md` (branch `edge09/integ`) are now met. The rest still needs a separate Khatri GO, named operators and the on-site backup size.
