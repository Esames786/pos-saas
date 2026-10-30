# Release — Trial Balance by heads + Khatri stock-to-P&L command (21 Oct 2026)

Deployed `18d552a0` (merge of `feat/khatri-tb-stock-pnl-20261021`: `e973e2a4`, `a6722dae`, `872f864c`) at 11:42 UTC.
Plan and impact: `docs/plans/khatri-tb-heads-and-stock-to-pnl-2026-10-21.md`.

| | |
|---|---|
| Implemented | TB-SECTION-HEADS-1; KHATRI-STOCK-TO-PNL-1 (command + old-form guard + `expensed_to_cost` movement) |
| Tested | see gates |
| Merged | `18d552a0` on `feat/14d-2-plan-upgrade-requests`, pushed, origin = HEAD verified |
| Deployed | yes, 11:42 UTC |
| Activated | TB grouping is the default view for every tenant (display only). The command has **not** been run with `--yes` anywhere; only a dry run on khatribiryani (writes nothing) |

## Gates

| Gate | Evidence |
|---|---|
| Full MySQL suite on the final commit `872f864c` | 2,335 tests OK (second run, 10:50–11:40 UTC). The first run on the fresh `_khs` master DB had 7 reds in `CategoryBranchScopeMySqlTest` ("route … no module claims it": the master modules were not seeded yet when it ran); that file alone: 7/7 OK; the second full run: 0 failures |
| Default suite (Unit + Feature) | 230 tests, 3 failures — the same 3 known on production (catering sidebar, ESC/POS report payload, rider reassignment) |
| Sabotage | TB: 4 breaks (Cost of Sales rule, head sums, direct row, tree guard) → red. Command: 8 breaks (wrong date, no stock out, no unbilled / mismatch / cost-head refusal, no old-form guard, no flag, dry run applying) → red |
| Feature-OFF / old readers | `group=0` = the flat list and the old 7-column CSV (existing TB tests run that path); the command writes nothing without `--yes` |
| Production MySQL / locks | MySQL 8.0.46; the migration appends one ENUM value to `stock_ledgers.movement_type` (metadata only); largest table 373 rows |
| Backups just before | `tenants:backup --no-storage` as www-data, 11:41 UTC: master + 12 tenants OK → `storage/app/private/backups/20261021_114115` (khatribiryani 222.1 MB, kashiffood 321.4 MB; retaildemo / inventorydemo have no DB) |
| Deploy | `bash deploy.sh`: HEAD `18d552a0`; migration `2026_10_21_000010` recorded and the ENUM value present on all 11 tenant DBs |
| Smoke | tb_diff 0.00, neg_on_disallowed 0, department_negative 0 on every tenant; no log line after the deploy |
| Permissions | before / after: no role lost a permission, no user lost a role |
| Reconciliation (11:41 vs 11:43 UTC) | identical on 11 tenants; khatribiryani +3 sales orders (2 paid, Rs 2,980; cash +2,980; journals +2), kashiffood +1 order — live sales in between. Books (1400, 2100), stock and suppliers unchanged everywhere |
| TB on production | khatribiryani 1–21 Oct through the controller: grouped totals == flat totals, difference 0, 27 accounts in both; page and PDF render |

## Khatri dry run (production, 11:44 UTC — nothing written: reclass journals 0 → 0, stock rows 290 → 290)

1400 Inventory today: 56,32,483.71. If applied: 11 products made purchase-only, 1400 → 25,49,809.92.

| Cost head | Aug | Sep | Oct |
|---|---|---|---|
| 5100-3 Finish Goods | — | — | 29,23,653.00 |
| 5100-2 Packing | 30,300.00 | 10,342.16 | 97,078.64 |
| 5100-1 Beverages | — | 4,980.00 | 16,320.00 |

Skipped (8), each for its reason:
- no cost head (D1, on screen): BEEF CHUNGAIZI PULAOW, BIRYANI MASALA CHICKEN AALOO, CHICKEN, SINGAPORIAN RICE;
- received but not billed: 7 UP DIET (CRT) and BIG APPLE (CRT) on GRN-20261013083826-106; Container 750 ML on GRN-20261013083425-477;
- books vs stock: Spoon — bills 20,819.10 into 1400, receipts 21,219.10 into stock (400 apart; the old "Spoon, qty 1,
  rate 400" cartage lines are the likely cause) — for the accountant.

`--yes` only after the owner approves this output.

## Run on khatribiryani — 21 Oct (owner's go through the business developer: "packaging ko cost me daldain; stock abhi kisi ka track nh krna ha")

| Step | Evidence |
|---|---|
| Backup | `tenants:backup --tenant=khatribiryani` 14:13 UTC → `backups/20261021_141304` (222.7 MB); again 14:23 → `backups/20261021_142330` |
| Fresh dry run 14:13 | the same plan as 11:44 (11 ready, 8 skipped); nothing written |
| `--all-purchased --yes` 14:13:34 | 11 items made purchase-only; 120 reclass journals on their bill dates; 11 stock-out rows; 1400 56,32,483.71 → 25,49,809.92; tb_diff 0 |
| Cost by month | 5100-3 Oct +29,23,653 · 5100-2 Aug +30,300 · Sep +10,342.16 · Oct +97,078.64 · 5100-1 Sep +4,980 · Oct +16,320; net profit Aug −30,300 · Sep −15,322.16 · Oct −30,37,051.64 — exactly the cost moved |
| Found after the run | #94 KHATRI BIRYANI BEEF had "Track Stock" unticked at 07:34 UTC (old form, before the guard went live) with 599.5 / Rs 7,02,450 left in stock and 1400; the command skipped it as already purchase-only |
| Fix `f860c0cf`, deployed `958c36ad` 14:23 | the command also picks an item switched off with stock left; test + 2 sabotages red; roles unchanged; smoke tb_diff 0 everywhere; no log error |
| `--products=94 --yes` 14:23:45 | 15 journals, 1 stock-out row; 1400 → 18,47,359.92; 5100-3 Oct +7,02,450; tb_diff 0 |
| Reconciliation | every other tenant identical before/after both runs; khatribiryani changed only in 1400, stock and the new journal / stock rows |

**Left in 1400 (18,47,359.92), each for a reason the client must clear first:** BEEF CHUNGAIZI PULAOW, BIRYANI MASALA
CHICKEN AALOO, SINGAPORIAN RICE, CHICKEN — no category with a cost head; 7 UP DIET (CRT) and BIG APPLE (CRT) on
GRN-20261013083826-106 and Container 750 ML on GRN-20261013083425-477 — received but not billed; Spoon — its bills put
20,819.10 into 1400 but its receipts brought 21,219.10 into stock (400 apart: the old "Spoon, qty 1, rate 400" cartage
lines). Once cleared, `--all-purchased --yes` takes them; nothing else changes.
