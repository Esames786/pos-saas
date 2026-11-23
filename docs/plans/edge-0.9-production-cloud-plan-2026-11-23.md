# Edge 0.9: production Cloud delta (5 master + 1 tenant migrations). PLAN FOR OWNER APPROVAL

Status: **prepared, NOT executed.** Owner GO of 23 Nov 2026: "Once LAB passes, prepare the exact production Cloud plan (5 master + 1 tenant
migrations) for separate approval." The real LAB passed on 23 Nov (record: `docs/status/edge-0.9.0-real-lab-acceptance-2026-11-23.md`).
Nothing here touches Khatri's appliance. Khatri stays on 0.8.5 until its own GO.

## 1. What is deployed

| item | value |
|---|---|
| production today | branch `feat/14d-2-plan-upgrade-requests` = `9d93ee96` (code `ead217f5` MANAGER-PIN-SHARED-1 on top of `dc0babbf`, live since 22 Nov ~00:55 PKT) |
| 0.9 Cloud half | `edge09/integ` (source of the signed 0.9.0-edge r2 = `3328afb8`, plus docs) |
| candidate | a new merge `Merge EDGE-0.9-CLOUD: …` of `edge09/integ` into production. `git merge-tree 9d93ee96 3328afb8`: the only conflicts are the generated `docs/system-map/index.html` + `system-map.json` (rebuild them). `ManagerApprovalService` merges cleanly |
| code delta dc0babbf → 3328afb8 (Cloud side) | setup codes + one-time setup token, release registry + signed download, device status / LAN profile, live-state mirror endpoint, update-now + maintenance window, automatic-takeover branch setting (default OFF), Install Offline Counter (cashier self-service), counter setup + phone pages |

## 2. Migrations: the exact list (all additive, every one guarded by hasTable/hasColumn, no data rewrite)

| # | migration | DB | what it does | rows touched |
|---|---|---|---|---|
| M1 | `2026_11_21_090001_create_edge_setup_sessions_table` | master | CREATE TABLE `edge_setup_sessions` (hashed setup codes/tokens only) | 0 |
| M2 | `2026_11_21_090002_create_edge_releases_table` | master | CREATE TABLE `edge_releases` | 0 |
| M3 | `2026_11_21_090003_add_edge_devices_install_status_columns` | master | ADD nullable columns to `edge_devices` (install_phase/ok/message/reported_at, reported_version, lan_hostname/ip/https_port/ca_pem/ca_sha256/reported_at, update_now_requested_at, maintenance window…) | 0 (existing device rows get NULLs) |
| M4 | `2026_11_21_090004_add_approved_plan_hashes_to_edge_releases` | master | ADD `approved_plan_hashes` JSON NULL | 0 |
| M5 | `2026_11_21_090005_add_pairing_codes_issued_to_edge_setup_sessions` | master | ADD `pairing_codes_issued` INT default 0 | 0 |
| T1 | `tenant/2026_11_21_000001_add_edge_auto_takeover_to_branches` | each of the 13 tenants | ADD `edge_auto_takeover_enabled` BOOL default **false**, `edge_auto_takeover_after_seconds` default 300, `…_set_by_user_id` NULL, `…_set_at` NULL | 0 (every branch stays OFF) |

`database/migrations/edge/*` (7 Edge-local migrations) run only on Branch Servers, never on the Cloud.
Proven: the same code applied on the real LAB Cloud on 23 Nov (28 pending there because the LAB Cloud was older: 7 master + 21 tenant,
0 failures, 31.6 s). On production only these 6 are pending (the other 22 are already live).

## 3. Impact (from the system map)

- **Tables:** `edge_setup_sessions`, `edge_releases` (new); `edge_devices` (master; 18 screens, all `tenant.offline-edge.*`, plus the device
  API); `branches` (tenant; 491 screens use it. The 4 new columns are only read by the Offline Edge settings and the heartbeat/takeover path).
- **Screens and endpoints:** `tenant.offline-edge.*`: index, download, setup-code generate/cancel, pairing-code, devices update-now and
  maintenance-window, auto-takeover, counter-setup, counter-install setup/my-enrollment, phone, ca.crt, security. Device API
  `/edge/api/setup/{redeem,pairing-code,enrollment-assertions}`, `/edge/api/device/{status,lan-profile}`, `/edge/api/updates/{latest,{release}/download}`,
  `/edge/api/live-state`. On the Online POS, the Cloud-only "Install Offline Counter" entry (permission `tenant.offline-edge.counter-install`).
- **Tests:** the CP6 MySQL gate (3402 tests on `0cc1cb2a`, 15 reds all explained, fixed test-only in `da7b3202`; `3328afb8` targeted reruns). The merged
  candidate needs:
  - `CloudManagerApprovalEligibilityMySqlTest` and `SalesReturnManagerApprovalMySqlTest` (production's `ead217f5` tests);
  - the Edge Cloud-half MySQL classes;
  - fast Feature/Edge, Unit/Edge and Feature/Pos;
  - a system-map rebuild.

## 4. Preconditions (all before the window)

1. Owner GO for this plan. Separate from the Khatri appliance GO.
2. Candidate built (the merge above), map rebuilt, the targeted gates in §3 green. No other heavy suite running at the same time.
3. Rehearsal: apply the 6 migrations on a fresh read-only copy of the production schema, structure only. The local `pos_integ_prodschema_*` copies
   predate the 21 Nov deploy, so refresh them or apply all pending on them. Expected result: 5 master + 1 tenant, 0 failures.
4. Backups exactly as on 21 Nov (runbook 1.3): `php artisan tenants:backup` + master `mysqldump --single-transaction --routines | gzip`
   to `/var/backups/`. Record the sizes and sha256.
5. A quiet window outside Khatri's business hours. Khatri's 0.8.5 Branch Server heartbeats every ~20 s; lease TTL 120 s.
   - A php-fpm reload interrupts the device API for seconds only.
   - LAB measurement: Cloud stopped 34 s → longest heartbeat gap 66 s, no fence.
   - Unchanged 0.8.5 against the 0.9 Cloud is proven in the CP5/CP6 Sandbox runs; unchanged 0.8.2 against the 0.9 Cloud on the real LAB, 23 Nov.

## 5. Steps (production; Claude executes only on the owner's word in chat)

| step | action | check |
|---|---|---|
| P1 | push the candidate merge to `feat/14d-2-plan-upgrade-requests` | head = the candidate sha |
| P2 | on the server: backups (precondition 4) | files present, sizes recorded |
| P3 | `bash deploy.sh` (pull --ff-only, composer, master migrate, MasterSeeder, route:clear + routes-sync, per-tenant migrate + chart of accounts + Owner grants, permission cache reset, caches, fpm reload) | `migrate:status` master: 0 pending; every tenant OK, `skipped=0` |
| P4 | post-checks (read-only) | below |

**Post-checks:**
- `edge_releases` = 0 rows (no release published, see §6).
- Every `branches.edge_auto_takeover_enabled` = 0.
- Khatri: `edge_devices` row unchanged except the new NULL columns. The lease `heartbeat_seq` advances, `fenced_at` stays NULL, holder `cloud`.
- Khatri's Online POS opens and its write gate is open.
- `offline_edge` stays enabled on Khatri's plan only; `EDGE_FEATURE_ENABLED` is unchanged.
- No business rows created or updated in any tenant. MasterSeeder only re-touches `updated_at`, as on 21 Nov.

## 6. Deliberately NOT part of this deploy

- **No `edge:release:publish`.** Publishing 0.9.0 to the Cloud is part of the Khatri upgrade, and only after its GO: the
  Cloud-downloaded wizard path, with `--approve-plan=0.8.5-edge=sha256:659f170d966cc87fdf95a4b15fb3871885bf8c02587d897931b5fab18ffc2ffb`
  (the 0.8.5 → 0.9.0 plan hash measured in the CP6 r2 Sandbox run; Khatri's own dry run must reproduce it).
- **Future releases stay on hold.** No 0.9.x release is published to any channel until the auto-updater's heartbeat invariant
  (EdgeAutoUpdateServiceControl still stops the authority worker before the schema upgrade; CP5 70 s) is fixed and proven.
- No automatic-takeover enablement, no Local Mode, no takeover/handback, no Khatri appliance, pairing or printer change.

## 7. Rollback

`git reset --hard 9d93ee96 && bash deploy.sh`. The new tables and columns stay inert under the old code; the migrations are additive,
so a down-migration is not needed. Restore a DB only from the P2 backups, and only if ever needed.

## 8. After this deploy: the Khatri upgrade (needs its own GO)

1. Measured restore-time bound (see the LAB acceptance record, `KHATRI_RESTORE_TIME`).
2. The real-LAB result (passed 23 Nov).
3. A quiet window, then a dry run on Khatri: the plan hash must equal `sha256:659f170d…`.
4. Wizard update with r2: `1cbc7d6c…`, zip `85bbadad…`.
5. The same post-checks as on the LAB.
