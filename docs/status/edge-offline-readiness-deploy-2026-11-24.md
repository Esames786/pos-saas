# Edge offline-readiness fix: production deploy, 24 Nov 2026

**Result: deployed and verified.**
- Production = `59b4c31d`: the merge `73670d68` of `fix/edge-offline-readiness-20261124` (`f0a9e557`) onto `27353b88`, plus the system map.
- The Khatri Branch Server (0.9.0-edge, unchanged) now passes every takeover gate except `AUTHORITY_TAKEOVER_SAFE`, which is correct while the Cloud lease is live. A supervised **Switch to Offline** during an internet outage is no longer refused.
- The Cloud stayed the branch writer throughout: no takeover, handback, release or appliance change. Auto-takeover is still off, and the lease TTL is still 120 s.

Readers: the owner and the engineers who run the next window. Times are PKT; the server logs in UTC (PKT − 5 h).

## 1. Why the takeover was blocked

- **Main cause** (confirmed read-only on production, 24 Nov ~04:50):
  - `STOCK_AUTHORITY_READY` needs an accepted stock baseline, and it has no supervisor override.
  - Khatri branch 1 has **0 positive `stock_balances` rows**: stock tracking was stopped on 21 Oct.
  - So the Cloud's baseline package was empty. Every `POST /api/edge/sync/baseline` (about 1,485 of them, all HTTP 200) answered the same **494 bytes**.
  - The appliance refuses an empty package whole (`BASELINE_EMPTY`, EdgeBaselineClient::assertResolvable). The gate had been false since the 21 Nov install.
- **Second cause:**
  - The config fingerprint hashed `max(updated_at)` of `printers` and `restaurant_tables`. Every print-agent heartbeat and every dine-in table status change moved it.
  - The result was a new config revision (about 140 an hour) and a **902 KB** `config/refresh` on almost every appliance tick.
  - An internet drop between a tick's config apply and its baseline fetch would have left the baseline at the old revision, with no override.
- **Safe for Khatri:**
  - All 102 POS products use inventory method `none`, and none is stock-tracked.
  - There are no recipes and no modifier-linked products.
  - So a zero baseline can never cause "Insufficient stock" offline (`allow_negative_stock` = 0 does not matter).

## 2. The fix (Cloud-only, no migration, no appliance change)

- **Stock baseline package:**
  - It names only the products and variants the appliance holds.
  - An empty position is sent as explicit zero rows. The appliance already reads a missing row as 0.
  - The advertised stock watermark is unchanged.
- **Config fingerprint:**
  - Printers and restaurant tables are hashed by the configuration content shipped to the appliance (table status only as active or inactive).
  - Real edits (IP, port, name, role, enabled; a table added, renamed, disabled or deleted) still create a revision.
- **Plan and tests:**
  - Plan: `docs/plans/edge-offline-readiness-fix-2026-11-24.md`.
  - New tests: `EdgeStandbyChurnReadinessHttpMySqlTest` (4) and `EdgeConfigWatermarkMySqlTest` (+2), 8/8 green.
  - The focused Edge regression is green apart from the 2 pre-existing `EdgeStandbyFreshnessHttpMySqlTest` failures, which come from test-database order and fail the same way on unfixed code.

## 3. Timeline (24 Nov)

| PKT | Step |
|---|---|
| 03:38 | The Khatri PC or the shop internet went silent: Edge and the print agent both stopped. The lease expired (it would have blocked branch-1 Cloud sales; no sale was attempted, nothing fenced). |
| 10:17 | Owner GO ("okay kardo deploy"). Precheck clean except Khatri not heartbeating → owner chose "turn PC on, then deploy". |
| 11:27 | Khatri heartbeat back. Precheck: server `be57aeba`, branch `27353b88`, 0 pending, lease holder cloud (1 s), **no sales in 15 min**. |
| 11:27–11:29 | Backups: `tenants:backup` for all 13 tenants + master into `storage/app/private/backups/20261124_062731` (15 files, none empty). Master dump `/var/backups/edge09-cloud-master-20261124-0627.sql.gz`, sha256 `6ba47c61880a34da42b1ddbd08bdfef6629c359b1c59312361e6aea3588b9fac`, gzip OK. |
| 11:29 | Before-snapshot `snap-before5.json`; 3-second lease watch started. Churn visible: 14 config revisions in the previous 10 min. |
| 11:30 | Sales gate re-checked (none). Push `27353b88..59b4c31d` to the production branch (fast-forward). |
| 11:30:20–11:31:08 | `deploy.sh` rc 0, "Nothing to migrate", "DEPLOY COMPLETE", head `59b4c31d`. Log `/root/deploy-edge-offline-readiness-2026-11-24-0630.log`. |
| 11:31 | Appliance: one `sync/baseline` 200 of **6,347 bytes** (the zero position), accepted; afterwards heartbeats only. |
| 11:34 | Branch Server status page (owner, RustDesk): every gate passes except `AUTHORITY_TAKEOVER_SAFE`. |

## 4. Verification

- **Snapshot compare (`compare.php … none`):** all PASS.
  - Master: plans, modules, tenants, databases and subscriptions identical; `offline_edge` per plan identical; `edge_releases` 0; route catalog 862 → 862; Khatri device row unchanged (ready, 0.9.0-edge).
  - Every tenant: +0 migrations, +0 permissions, roles and configuration identical, auto-takeover 0, no business rows changed.
  - Two lines were checked by hand:
    - `edge_setup_sessions` rows = 1 before and after. This is the owner's 03:00 setup code; the check expects 0 from the first deploy night.
    - Kashif Kitchen `journal_entries` +1: a back-office catering receipt posted at 11:29:58, before `deploy.sh` started. User work, not the deploy.
- **Khatri lease watch:** largest heartbeat age 44 s; never blocked, fenced or released; the Cloud was the holder throughout.
- **Config revisions:** exactly one bump with the new fingerprint (4120 at 11:30:53).
- **HTTP** (equal to the earlier baseline):
  - central `/up` 200;
  - Khatri `/login` 200; Khatri `/pos` and `/settings/offline-edge` 302;
  - kashiffood, kashifkitchen, thekashiffoods and tawakkalbiryani `/login` 200;
  - unauthenticated `POST /api/edge/authority/heartbeat` 401.
- **Error log:** 0 ERROR lines since the deploy. Today's 9 are from 00:00 UTC, the nightly job.
- **Branch Server status page (11:34):**
  - Offline readiness: "blocked: AUTHORITY_TAKEOVER_SAFE" only.
  - `STOCK_AUTHORITY_READY` pass, `STANDBY_FRESH_ENOUGH` pass; config fresh (rev 4120), stock fresh.
  - Heartbeat failures 0 / 2497.
- **10-minute settle check:** see §6.

## 5. Rollback (prepared, not used)

- `rollback/edge-offline-readiness-20261124-r2` restores the Cloud Edge code (app and tests) of `27353b88` on top of this record commit. To roll back: fast-forward push it to the production branch, then run `deploy.sh`.
- It costs one more config revision. The appliance would return to the blocked state; no data needs undoing.
- The first rollback branch (`…-20261124`, `068080e1`) sits on top of `59b4c31d` only. It is superseded because it no longer fast-forwards.

## 6. Settle check and open items

- **Settle check (11:31–11:42):** PASS.
  - Config revision flat at 4120, 0 minted, while the shop's print agents made 1,070 calls. Before the fix: 14 revisions in 10 min.
  - Appliance Edge traffic: heartbeats (11) plus the one accepted baseline only. No `config/refresh` and no further `sync/baseline`.
  - Lease: 293 samples, max age 44 s, 0 blocked, 0 fenced, Cloud the holder throughout.
  - 0 new ERROR lines. The single unauthenticated heartbeat 401 is the smoke check.
- **Still open:**
  - **The Branch Server PC must be on before opening.** While it is off, the lease expires and the Cloud refuses Khatri branch-1 sales (this morning 03:38–11:27).
  - **Printers:** at 11:34 only 2 of 6 were reachable from the Branch Server (counters not yet on). Offline, it prints directly to them, so they must be reachable before any switch.
  - **Returnable projection:** the cache never commits. It does not affect takeover; returns of Cloud sales fail closed offline.
  - **Not changed by this deploy:** the full offline drill (runbook, LAB rehearsal, closed window, owner GO); the TTL 600 decision (on hold); C3, the heartbeat on its own timer (a future appliance build).
