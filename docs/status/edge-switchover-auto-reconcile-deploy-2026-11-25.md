# Edge switchover auto-reconcile: production deploy, 25 Nov 2026

**Result: deployed and verified.** Production = `ee0cf931`: the merge of `feat/edge-switchover-auto-reconcile-20261124` (`349457e2`) onto `d72dfc80`, plus the system map.

When offline sales come back after an internet outage, the Cloud now settles them by itself (rules R1–R7 in `docs/plans/edge-switchover-auto-reconcile-2026-11-24.md`):
- the paid offline bill is the money truth;
- nothing is ever paid twice;
- a table re-punched offline closes the old online bill.

The one case that still needs a person is a bill part-paid online in the last seconds before the cut. The change is Cloud-only: no migration, and the Branch Server stays on 0.9.0-edge.

Readers: the owner and the engineers who run the next window. Times are PKT; the server logs in UTC (PKT − 5 h).

## Authority and gates

- **Owner decisions:**
  - "teekh hai kardo build" (24 Nov, the money-truth rule).
  - "kardo na deploy" was held during trading; the owner then chose "Tonight at closing".
  - "deployment chala dena ready raho" (25 Nov 00:20).
  - "Deploy without Khatri PC" (25 Nov ~01:05), after the Khatri Branch Server PC was switched off at 00:58.
- **Trading gate:**
  - No sales at any tenant in the preceding 15 minutes (01:36).
  - Every shift closed at every tenant: Khatri 01:13, Kashif Food 01:15, Tawakal 23:57.
  - No held bills.
- **Khatri Branch Server:** off since 00:58 (Edge and print agent stopped together; the shop internet was up). This change does not touch the lease or heartbeat path.

## Timeline (25 Nov)

| PKT | Step |
|---|---|
| 00:22 | Precheck: Khatri and Kashif Food still selling. Gate watch started. |
| 00:58 | Khatri Branch Server PC switched off (watch stopped: KHATRI_STALE). Owner: deploy without it. |
| 01:36 | Gate clean. All shifts closed, no held bills. |
| 01:36–01:38 | Backups: `tenants:backup` for all 13 tenants + master into `storage/app/private/backups/20261124_203656` (15 files, none empty). Master dump `/var/backups/edge09-cloud-master-20261124-2036.sql.gz`, sha256 `486890b614a307b7cd6d4d059201d9a012ee4c4befaf4f734bfe3a22c93efe27`, gzip OK. |
| 01:38 | Before-snapshot `snap-before6.json`; lease watch started; gate re-checked (none). Push `d72dfc80..ee0cf931` (fast-forward). |
| 01:38:43–01:39:03 | `deploy.sh` rc 0, "Nothing to migrate", "DEPLOY COMPLETE", head `ee0cf931`. Log `/root/deploy-switchover-auto-reconcile-2026-11-25-2038.log`. |

## Verification

- **Snapshot compare (`compare.php … none`):** every line passes except two, both expected.
  - `edge_setup_sessions` rows = 1 before and after: the 24 Nov 03:00 setup code. The check expects 0.
  - Khatri is flagged only because its heartbeat is not moving with the PC off. Lease seq 4887 → 4887, holder cloud, fenced null, released null. Migrations +0, permissions +0, roles and configuration the same, no business rows.
  - Master: plans, modules, tenants, databases, subscriptions and `offline_edge` per plan identical; `edge_releases` 0; route catalog 862 → 862; Khatri device row unchanged.
- **HTTP** (equal to the earlier baseline):
  - central `/up` 200;
  - Khatri `/login` 200; Khatri `/pos` and `/settings/offline-edge` 302;
  - kashiffood, kashifkitchen, thekashiffoods and tawakkalbiryani `/login` 200;
  - unauthenticated `POST /api/edge/authority/heartbeat` and `/api/edge/sync/sales` 401.
- **New code live:**
  - `EdgeInboundSaleIngestionService` carries the reconcile rules, and `EdgeSwitchoverReportService` is present.
  - `EdgeSwitchoverReportService::recent()` runs on the Khatri tenant: 0 fixes (no offline period yet).
  - The route cache loads.
- **Error log:** 0 ERROR lines since the deploy.

## Rollback (prepared, not used)

- `rollback/edge-switchover-auto-reconcile-20261124-r2` restores the Cloud code of `d72dfc80` on top of this record commit. To roll back: fast-forward push it to the production branch, then run `deploy.sh`.
- The first rollback (`af04b1dd`) sits on top of `ee0cf931` only and is superseded.
- Rolling back means offline-sale conflicts park as "needs attention" again; no data needs undoing.

## Open

- **The Khatri Branch Server PC must be on before opening (25 Nov).** While it is off, the Cloud refuses Khatri branch-1 sales.
- **First real use is checked on the Offline Edge page** ("Switchover auto-fixes"). The full internet-off drill is still pending (runbook, LAB rehearsal, closed window, owner GO).
