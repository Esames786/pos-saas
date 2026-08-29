# TENANT-AUTO-BACKUP-1 — per-tenant scheduled DB backups (on/off, up to 3 daily times, 7-day retention)

**Status:** DESIGN / PLAN (nothing built or deployed yet — this document first, per owner)
**Date:** 2026-08-26
**Author:** Claude
**Target tenant for first rollout:** `khatribiryani` (LIVE — do not disturb its data)

---

## 1. Goal

Give each tenant a **self-managed automatic backup schedule**, configured from the existing
Central → Tenants → Backups screen:

- **On/off** toggle per tenant.
- **1, 2, or 3 backup times per day** (operator picks how many and at what times).
- **Retention:** keep only the **last 7 days** of automatic backups; older ones auto-pruned.
- All times interpreted in **Pakistan Standard Time (Asia/Karachi, PKT)**.
- Every automatic backup is a normal `TenantBackup` row → **visible in the same UI**, downloadable,
  restorable, exactly like today's manual backups.

**First rollout:** enable for `khatribiryani` with three daily slots — **14:30, 19:30, 02:30 PKT**
(2:30 PM, 7:30 PM, 2:30 AM Pakistan time). ⚠️ *7:30 assumed PM — confirm (see Open Questions).*

Plus a **one-time server-side audit** of the backup files that earlier automated work left on the
production server (invisible to this UI), reported to the owner **before** any deletion.

---

## 2. Current system (what exists today) — grounded in code

There are **two independent backup mechanisms**, and only one is visible in the UI. This is the root
cause of the "backups on the server that human eyes can't see".

### 2a. Per-tenant service backups — VISIBLE in the UI
- `App\Services\Central\TenantBackupService::backup(Tenant, type, userId, notes)`
  ([TenantBackupService.php:32](../../app/Services/Central/TenantBackupService.php#L32)).
- Writes one SQL dump to the private disk: `backups/tenants/{code}/{Ymd_His}_{code}.sql`
  (`config('backup.disk')`, default `local` → on Laravel 12 the root is `storage/app/private`, so
  physically `storage/app/private/backups/tenants/{code}/…`).
- Records a `TenantBackup` master row (tenant_id, code, db, disk, path, file, size, `backup_type`,
  `status`, created_by…) — [TenantBackup.php](../../app/Models/Master/TenantBackup.php).
- Triggered by the **Create Backup** button (`backup_type='manual'`) and automatically as a
  `pre_restore` / `pre_reset` safety copy. **This is what the Backups page lists.**
- ⚠️ **No automatic retention at all** — these per-tenant `.sql` files accumulate forever unless a
  human clicks Delete. (Khatri already shows 15.88 MB + 9 older files, growing.)

### 2b. Nightly full-server dump — INVISIBLE in the UI
- `php artisan tenants:backup --prune` — [TenantsBackupCommand.php](../../app/Console/Commands/TenantsBackupCommand.php).
- Dumps **master + every tenant DB + a storage archive** into a timestamped folder
  `backups/{Ymd_His}/` with a `manifest.json`.
- **Creates NO `TenantBackup` rows** → these folders are invisible in the Backups UI. They live only
  on disk. **These are the "invisible" backups the owner saw building up on the server.**
- Scheduled nightly at **02:00 server time**, gated by `config('backup.schedule_enabled')`
  (`BACKUP_SCHEDULE_ENABLED`) — [routes/console.php:34](../../routes/console.php#L34). Pruned to
  `backup.retention_days` (**14 days**) *only when the schedule actually runs `--prune`*.

### 2c. Scheduler + timezone facts
- The whole Cloud scheduler is registered only under `EdgeRuntime::isCloudSafe()` and needs OS cron
  `php artisan schedule:run` every minute (LIVE on prod per ops notes).
- Existing idempotent-dispatch precedent: `reports:dispatch-scheduled` runs every 15 min and claims
  each (schedule, period) once via a unique run row, so retries/overlaps never double-fire
  ([DispatchScheduledReportsCommand.php:16](../../app/Console/Commands/DispatchScheduledReportsCommand.php#L16)).
  **We mirror this exactly for backup slots.**

---

## 3. Requirements → design decisions

| Requirement | Decision |
|---|---|
| Per-tenant on/off | New `tenant_backup_settings` master row, `is_enabled` bool |
| 1–3 times/day | `times` JSON array of `"HH:MM"` (max 3), validated |
| Times in PKT | `timezone` column, default `Asia/Karachi`; dispatcher matches slots in that tz |
| Keep last 7 days only | `retention_days` column (default 7); prune **scheduled** backups older than that after each run |
| Visible in UI | Automatic backups use `TenantBackupService::backup(..., 'scheduled')` → normal `TenantBackup` rows |
| No double-fire | `tenant_backup_runs` claim table, `UNIQUE(tenant_id, slot_date, slot_time)` |
| Don't disturb other mechanisms | Nightly `tenants:backup` left as-is (owner decides in the audit whether to keep it) |

---

## 4. Detailed design

### 4.1 Data model (MASTER db — the central scheduler iterates master tenants)

**New migration (master):** `tenant_backup_settings`
```
id, tenant_id (FK→tenants, unique), is_enabled bool default 0,
times json (e.g. ["14:30","19:30","02:30"]),
timezone varchar default 'Asia/Karachi',
retention_days unsignedTinyInt default 7,
created_at, updated_at
```
One row per tenant. Absent row = feature off (safe default).

**New migration (master):** `tenant_backup_runs` (idempotency claim + audit trail)
```
id, tenant_id, slot_date date, slot_time varchar(5) 'HH:MM',
tenant_backup_id nullable (FK→tenant_backups), status enum(claimed|done|failed),
error nullable, created_at, updated_at,
UNIQUE(tenant_id, slot_date, slot_time)
```

**New models:** `App\Models\Master\TenantBackupSetting`, `App\Models\Master\TenantBackupRun`
(+ `Tenant::backupSetting()` hasOne).

### 4.2 Dispatcher command — `tenants:auto-backup`

New `App\Console\Commands\DispatchTenantAutoBackupCommand`:

```
For each Tenant with an enabled backup setting:
  tz     = setting.timezone (Asia/Karachi)
  nowTz  = now(tz)
  slotDate = nowTz->toDateString()
  for each "HH:MM" in setting.times:
     slotMoment = today at HH:MM in tz
     # fire if we're within the slot window and haven't run it yet
     if nowTz is within [slotMoment, slotMoment + GRACE(=10 min)]:
        claim = TenantBackupRun::firstOrCreate(
                  {tenant_id, slot_date:slotDate, slot_time:"HH:MM"}, {status:'claimed'})
        if not claim->wasRecentlyCreated: continue        # already handled → idempotent no-op
        try:
          backup = TenantBackupService::backup(tenant, 'scheduled')
          claim->update(status:'done', tenant_backup_id:backup->id)
          pruneTenant(tenant, setting.retention_days)      # keep last N days
        catch e:
          claim->update(status:'failed', error:e)          # visible for follow-up, not fatal
```

- **Window + grace:** because cron runs `schedule:run` each minute and we schedule the dispatcher
  `->everyFiveMinutes()`, a 10-minute grace guarantees each slot is caught even if a run is skipped,
  while the unique claim guarantees it fires **exactly once** per slot per day.
- **Cross-midnight is a non-issue:** slots are matched against `now(tz)`; 02:30 PKT is just another
  slot on that PKT calendar day, regardless of server (UTC) time.
- `2:30 AM` correctness: server is UTC; 02:30 PKT = 21:30 UTC prev day. The tz-aware match handles it
  with no static `dailyAt`.

### 4.3 Retention prune (fixes the "no per-tenant prune" gap)

`pruneTenant(tenant, days)`:
- Select `TenantBackup` where `tenant_id` = tenant, **`backup_type = 'scheduled'`**,
  `created_at < now()->subDays(days)`.
- For each → `TenantBackupService::deleteBackup()` (removes the file **and** the row).
- **Only `scheduled` backups are auto-pruned.** Manual + pre_restore/pre_reset are deliberate human
  actions and are left intact (see Open Questions — owner may opt to prune manual too).

### 4.4 Schedule wiring — [routes/console.php](../../routes/console.php)

Inside the existing `if (EdgeRuntime::isCloudSafe())` block, gated by the same
`backup.schedule_enabled` flag:
```php
if (config('backup.schedule_enabled', false)) {
    Schedule::command('tenants:backup --prune')->dailyAt('02:00')->withoutOverlapping(); // existing
    Schedule::command('tenants:auto-backup')->everyFiveMinutes()->withoutOverlapping();  // NEW
}
```
Cloud-only + Edge-console-boundary already deny it on a branch appliance.

### 4.5 UI — [central/tenants/backups.blade.php](../../resources/views/central/tenants/backups.blade.php)

Add an **Auto-Backup** card above the history table:
- Toggle **Enabled**.
- **Up to 3 time pickers** (HH:MM), "+ add time" / remove; helper text "Times are Pakistan time (PKT)".
- **Retention (days)** number, default 7.
- **Next run** preview (computed from times + tz).
- **Save** → new route.

**New route** (central, superadmin group — [routes/central.php:65](../../routes/central.php#L65)):
```
POST /tenants/{tenant}/backup-settings  → TenantController@saveBackupSettings  (name: central.tenants.backup-settings.save)
```
Controller validates: `enabled` bool; `times` array max 3, each `H:i`, distinct; `retention_days`
1–30. `updateOrCreate` the setting row. No tenant-DB mutation.

### 4.6 Permissions / safety
- Superadmin-only (same `central` middleware group as the other backup routes).
- No new tenant-side route → **no tenant permission wiring needed** (unlike the printer routes).
- Backups remain on the **private** disk; download still streams via the existing guarded action.

---

## 5. Khatri Biryani rollout (after build + tests + owner OK)

1. Deploy (migrations additive; config flag; no tenant-DB change).
2. Ensure `BACKUP_SCHEDULE_ENABLED=true` on prod (already true if the nightly folders exist — the
   audit confirms) and OS cron `schedule:run` is live (it is).
3. In the UI (or a one-off tinker), set `khatribiryani`:
   `is_enabled=1, times=["14:30","19:30","02:30"], timezone="Asia/Karachi", retention_days=7`.
4. Verify: within 10 min of the first slot, a `scheduled` backup row appears; after 7 days, the
   oldest scheduled backup is auto-pruned. Khatri POS/data untouched (backups are read-only dumps).

---

## 6. Server backup-file audit (the "invisible files" clean-up)

**Read-only first, delete only after the owner approves each group.** Proposed inspection over SSH
(`ssh -i ~/.ssh/bingoo_prod root@187.77.140.39`), all non-mutating:

```bash
# where backups actually live (Laravel 12 private root)
du -sh storage/app/private/backups 2>/dev/null; du -sh storage/app/backups 2>/dev/null
# 2b nightly full-dump folders (INVISIBLE to UI) + their ages/sizes
ls -1dt storage/app/private/backups/[0-9]*_* 2>/dev/null | head -50
du -sh storage/app/private/backups/[0-9]*_* 2>/dev/null | sort -h | tail -30
# 2a per-tenant service dumps
du -sh storage/app/private/backups/tenants/* 2>/dev/null
# orphans: .sql on disk NOT referenced by any TenantBackup row (failed/manual server dumps)
#   → compare `find … -name '*.sql'` against `SELECT path FROM tenant_backups`
# ad-hoc dumps earlier work may have dropped OUTSIDE the backups dir:
find /root /tmp /var/www -maxdepth 3 -name '*.sql' -size +1M -printf '%s\t%p\n' 2>/dev/null | sort -h
ls -lah /root/*.sql* /tmp/*.sql* 2>/dev/null
```

**Likely removable (to confirm with real numbers before deleting):**
- `backups/{Ymd_His}/` nightly folders older than the intended retention (invisible, large — each is a
  full master + all-tenants + storage archive).
- Ad-hoc `*.sql` dumps in `/root`, `/tmp`, or the project dir created during Claude/Codex debugging
  sessions (prod DB dumps were taken for analysis — see ops notes).
- `pre_restore` / `pre_reset` backups from past ops, once the owner confirms they're not needed.
- Repo stray artifacts already noticed locally (not on the prod backup path but worth flagging):
  `tools/print-agent/dist/FakePrinter.exe`, loose `public/*.pdf`, `public/*.png`, `public/old_software.xlsx`.

**Deliverable of the audit:** a table — path, size, age, mechanism, "safe to delete? why" — sent to
the owner. **Nothing deleted without explicit per-group approval.**

**Structural fix so it stops recurring:** the new per-tenant retention (§4.3) handles the visible
`scheduled` backups; separately decide (owner) whether to (a) keep the nightly `tenants:backup` as an
off-UI safety net with a tighter `BACKUP_RETENTION_DAYS`, or (b) also surface/prune those folders.

---

## 7. Testing plan (MySQL feature tests — real DB, no prod)

`tests/MySql/TenantAutoBackupMySqlTest` (mirrors the reports-dispatch idempotency proofs):
1. **Slot due → backs up once**, creates a `scheduled` TenantBackup + a `done` run row.
2. **Idempotent:** running the dispatcher again in the same window is a **no-op** (unique claim).
3. **Not due:** outside the window → nothing happens.
4. **Timezone:** a tenant with `Asia/Karachi` fires at the PKT slot even though the test clock is UTC
   (assert with a frozen time).
5. **Disabled tenant** → skipped entirely.
6. **Retention:** with `retention_days=7`, a `scheduled` backup dated 8 days ago is pruned (file+row);
   6 days ago is kept; a **manual** backup 30 days ago is **kept** (policy §4.3).
7. **Settings save** validation: >3 times / bad `HH:MM` / retention out of range rejected.

Backup service call itself is exercised against a real test tenant DB (mysqldump available locally).

---

## 8. Deploy plan (additive, no prod mutation until approved)

1. Green tests → commit.
2. `bash deploy.sh` (migrations additive: 2 new master tables; no tenant-DB change).
3. Confirm `BACKUP_SCHEDULE_ENABLED=true` + cron on prod.
4. Enable Khatri (§5). Smoke: a scheduled row lands at the next slot; retention prunes >7d.
5. **Audit (§6) is a separate, read-only step**; deletions only after owner sign-off.

**No POS / inventory / finance / tenant-DB writes anywhere in this feature.** Backups are read-only
`mysqldump` snapshots; all new state is master-side config + claim rows.

---

## 9. Decisions (owner, 2026-08-26)

1. **7:30 = PM** → Khatri slots **14:30 / 19:30 / 02:30 PKT**. ✅
2. **7-day retention prunes ONLY `scheduled` backups** — manual / pre-restore are never auto-deleted. ✅
3. **Retention editable per tenant**, default 7. ✅
4. Nightly full-server dump (§2b): decide alongside the audit below (owner).

---

## 10. Server audit — FINDINGS (read-only, 2026-08-26; NOTHING deleted)

Prod `187.77.140.39`, app `/var/www/html/pos-saas`. **Disk is healthy: 194G total, 9.4G used, 185G
free (5%)** — this is housekeeping, not urgent. All `.sql` under `storage/app`: **197 files, 337 MB**.

| # | Group | Where | Size | What it is | Recommend |
|---|---|---|---|---|---|
| A | Migration dumps | `/root/migrate/*.sql` (~14 files) | ~15 MB | DO→Hostinger cutover dumps (`pos_tenant_*` + `final_*` + master), 2026-08-11 | **Delete** — migration long done, all tenants live |
| B | Manual pre-op safety dumps | `/root/backups/*.sql[.gz]` (~25 files) | ~130 MB | Claude/Codex pre-deploy/pre-fix copies (pre_menu, pre_gl, POSDRAFT, CATALOGGUARD, PRINTSCOPE…), 08-11→08-23; their deploys all succeeded | **Delete old (≤08-21); keep last ~3** as recent safety — owner picks |
| C | Nightly + manual full-dump folders | `storage/app/private/backups/{Ymd_His}/` (24 folders) | ~300 MB | UI-INVISIBLE full master+all-tenants+storage dumps; many are duplicate same-day deploy runs (7 on 08-19 alone) | **Delete older ones; keep last ~3 days.** Then tighten `BACKUP_RETENTION_DAYS` |
| D | Per-tenant service dumps | `storage/app/private/backups/tenants/khatribiryani/` | 70 MB | The UI-VISIBLE backup history (this very page) — legit, not junk | **Leave** — manage from the UI; new 7-day retention self-prunes future `scheduled` ones |
| E | Repo stray files (local wt) | `tools/print-agent/dist/FakePrinter.exe`, `public/*.pdf|*.png|old_software.xlsx` | small | Debug/working leftovers in the local checkout, not on the prod backup path | **Delete locally** (separate) |

**Notes:** outlier `20260819_170229` = 41 MB (a run that bundled a big storage archive). Group C's
retention is set to 14 days but extra *manual* `tenants:backup` runs during deploys accumulate beyond
the nightly one. **Removing A+B+C(old) reclaims ≈ 350–400 MB and removes all the invisible clutter.**
Nothing here is load-bearing for the running app — every item is a point-in-time snapshot.
