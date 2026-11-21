# Edge Phase 3 — SAME-DATASET Edge-vs-Online comparison (Team Q, 4 Oct 2026)

**Verdict: `EDGE_VS_ONLINE_GEOMETRY=NONE` — 998 boxes compared across 24 states × 2 viewports, max geometry deviation 0 px.**
The earlier paired run (`evidence/phase3/geometry/compare-run1-dialog-origin.json`, `DIFF(89)`, max 96.81 px) compared the
dev-seeded menu (`bingoo_edge_devtest_local`) with the LAB menu on the Online clone; every one of those 89 differences was
dataset-driven. With both runtimes rendering the identical dataset the shared cashier view has zero geometry difference.

Everything here is disposable and loopback-only: the dev Cloud clone (`:9704`, `pos_devonline_master_edge` → `pos_devonline_tenant_edge`)
and a NEW dev Edge instance (`:8096`, `bingoo_edge_devclone_local`). The LAB Cloud (`:9701`), the LAB databases, the installed LAB
appliance, the dev Clouds `:9702`/`:9703` and the first dev instance (`:8095`) were not touched. No `app/ resources/ routes/ tests/`
file was modified; the two product gaps found are reported in §6, not patched. Nothing committed.

## 1. How the second dev Edge instance ("edgeclone") was built and paired — the PRODUCT flow

New files: `tools/edge-dev-instance/serve-clone.sh` (+ README section), `C:\Users\Dell\BingooEdgeLab\scripts\devclone-cloud.php`
(clone-Cloud authority helper modelled on `lab-cloud.php`; refuses any database whose name lacks `devonline`),
`C:\Users\Dell\BingooEdgeLab\scripts\Invoke-DevCloneCloudArtisan.ps1` (clone env block for artisan, modelled on
`Invoke-LabCloudArtisan.ps1`), `.env.edgeclone` (git-ignored — one line added to `.gitignore` next to `.env.edgedev`, because the file
carries the paired device secret and the machine-local app key), secrets `devclone-cashier.pass` / `devclone-approver.pass`.
No LAB script was altered.

| step | where | command (no secrets on any command line) | result |
|---|---|---|---|
| 1 | worktree | `tools/edge-dev-instance/serve-clone.sh init` | `.env.edgeclone` from `.env.edgedev.example`: fresh `EDGE_LOCAL_APP_KEY`, `APP_URL=http://127.0.0.1:8096`, `EDGE_DB_DATABASE=bingoo_edge_devclone_local`, `CACHE_STORE=array` (the file cache is shared with `:8095` through `storage/`), `EDGE_CLOUD_ALLOW_HTTP=true`, `EDGE_ENROLLMENT_PUBLIC_KEY` from `secrets\enroll.public` |
| 2 | worktree | `APP_ENV=edgeclone php artisan edge:local:db-init` | real tenant (172) + edge (23) migrations on a fresh `bingoo_edge_devclone_local` (~2 min) |
| 3 | clone Cloud | `php devclone-cloud.php revoke-device 210c73f8-…` | the cloned tenant carried the LAB appliance's device row (`ready`, active slot) → `generateCode` would answer `DEVICE_CONFLICT`; the product `EdgePairingService::revokeDevice` revoked that **copy** on the clone only (saga: branch `pending → inactive`, recovery key rotated on the clone master) |
| 4 | clone Cloud | `php devclone-cloud.php pair-code <scratch file>` | one-time 6-digit code, 15-min TTL, written to a file; branch `inactive → pending` |
| 5 | worktree | `edge:local:pair --cloud-url=http://localhost:9704 --code-file=<file> --env-file=.env.edgeclone --device-name=team-q-devclone-8096` | device `c03a54be-e8bc-4061-97eb-e77dd6a23207`, tenant `edgehomelab`, branch 1, `pending_bootstrap`; identity + the `EDGE_SYNC_*` / `EDGE_STANDBY_*` / `EDGE_AUTHORITY_*` URL family appended to `.env.edgeclone`; the code file was consumed (`/api/edge/*` lives on the clone's **central** domain `localhost`, hence `http://localhost:9704`, not the tenant host) |
| 6 | worktree | `edge:local:bootstrap-pull` | snapshot `4ad40e50-…`, schema `edge-bootstrap-v8`, 38 sections, config revision 7, **activation epoch 2** (a new generation was allocated for the replacement device), acknowledged |
| 7 | clone Cloud | `php devclone-cloud.php branch-mode cloud` | the pairing saga had set `branches.sales_operating_mode = local_edge` (pending); reset to `cloud` so the clone keeps answering Online sales (same step the G2 team took) |
| 8 | clone Cloud → worktree | `php devclone-cloud.php assertion <file> 1` then `edge:local:enroll <file> --credential-file=<tmp copy of secrets\devclone-cashier.pass>`; same for user 2 / `devclone-approver.pass` | `LAB2C5D` (cashier) and `LABMF2DE` (approver) enrolled, credential version 1, epoch 2; assertion + credential files consumed |
| 9 | worktree | `serve-clone.sh start` | `http://127.0.0.1:8096/edge/local/health` = 200, `runtime_mode branch_server`, `edge-bootstrap-v8` |
| 10 | worktree | `edge:local:authority-heartbeat` once, then `serve-clone.sh worker start` | first beat `409 STALE_HEARTBEAT` (the clone's lease row still carried the LAB device's sequence 22126) → appliance resynced its sequence (product behaviour), next tick `ack online`, standby freshness `config 7→8`, stock baseline, returnable cache (22 sales), supplier finance, purchase returns all refreshed; health: bound, identity matches, 2 local users, authority `standby`, connection `online`, all gates green except `AUTHORITY_TAKEOVER_SAFE` (correct — the Cloud is alive) |

Cashier credential: the bootstrap carries the clone tenant's users and their permission graph; Edge credentials are set the product way
(`edge:local:enroll` consuming a Cloud-signed assertion bound to tenant/branch/device/epoch) — the LAB used the same path
(`lab-cloud.php assertion` + `--credential-file`). The dev credentials exist only in `C:\Users\Dell\BingooEdgeLab\secrets\devclone-*.pass`
and reach the tools through `POS_SHOT_PASS` read from the file.

The instance is a **STANDBY** appliance (the status slot reads `STANDBY · CLOUD AUTHORITY`; mutations are refused). No takeover was performed.

## 2. Lease handling and final clone Cloud state

- During the comparisons the appliance authority worker (`serve-clone.sh worker start`) renewed the clone's branch lease every 20 s
  (`edge_branch_authority_leases.device_public_uuid` switched from the LAB copy to the new device on the first accepted beat; holder `cloud`).
- End state (coordinator, 6 Oct 2026 02:21): Team Q's session ended before its `worker stop` + release; the worker process was found dead
  with the lease expired but not yet fenced (`last_heartbeat_at 2026-10-05 21:21:02`, `fenced_at NULL`). The coordinator released the row on
  the DISPOSABLE clone (`released_at = NOW()`, reason "Team Q dev clone appliance worker ended") so the clone Cloud answers sales normally
  again; the LAB tenant's lease row is untouched (`released_at NULL`, LAB sales_orders still 5). The 8096 instance keeps serving (STANDBY,
  render-only) without a worker. LESSON (second time): a paired dev appliance MUST release its lease when its worker stops, or the clone
  Cloud fences itself.

## 3. Shift state

Both sides: **no open shift** (clone `shifts`: the only row is closed; the fresh appliance has none). Both pages show the `No Open Shift`
badge in the same place — nothing had to be changed. (On the STANDBY appliance a shift cannot be opened anyway.)

## 4. Geometry — `tools/edge-browser-proof/geometry-compare.mjs` (settle 6000 ms, tolerance 1 px)

Evidence: `C:\Users\Dell\BingooEdgeLab\evidence\phase3\geometry-samedata\{edge,online}\report.json` (+ per-state PNGs) and `compare.json`.
Login OK on both (Edge landed `/edge/local/pos`, Online `/dashboard`); `POS_RUNTIME.mode` edge/cloud; shared-view marker present on both;
fonts `loaded` (5) on both; 1024 width stacks the shell on both; 0 page errors on either side.

| state | 1366×768 boxes / geometry / data | 1024×768 boxes / geometry / data |
|---|---|---|
| 01-main | 34 / 0 / 0 | 34 / 0 / 0 |
| 02-category | 34 / 0 / 0 | 34 / 0 / **21 DATA** |
| 03-customer-modal | 41 / 0 / **3 DATA** | 41 / 0 / **3 DATA** |
| 04-context-modal | 44 / 0 / 0 | 44 / 0 / 0 |
| 05-held-orders | 41 / 0 / 0 | 41 / 0 / 0 |
| 06-recent-orders | 41 / 0 / 0 | 41 / 0 / 0 |
| 07-recent-prints | SKIPPED on both (`#lastPrintModal.show` never appeared within 25 s — identical to the previous run and to Online alone; not a parity fact) | same |
| 08-quick-report | 49 / 0 / 0 | 49 / 0 / 0 |
| 09-table-workspace | 42 / 0 / 0 | 42 / 0 / 0 |
| 10-cart-two-lines | 34 / 0 / 0 | 34 / 0 / 0 |
| 11-review-pay | 50 / 0 / 0 | 50 / 0 / 0 |
| 12-qty-entry | 44 / 0 / 0 | 44 / 0 / 0 |
| 13-modifier-entry | 45 / 0 / 0 | 45 / 0 / 0 |

Total 998 boxes, **0 geometry differences, max deviation 0 px, 0 presence differences**, 27 DATA-bucket differences — all 27 traced to two
behaviour gaps (same database content on both sides, see §6):

- `03-customer-modal` (both viewports) `content y/h`, `body h`: Edge body 142 px vs Online 336 px — Online lists the first 20 customers
  as soon as the modal opens; the Edge modal shows "Start typing a phone number or name…" (gap B). **DATA.**
- `1024×768/02-category` (21 boxes, all +26.37 px below the tile grid): the second pill is **"Deals"** on Edge (2 combo tiles, taller)
  but **"Lab Menu"** on Online (3 product tiles) because the combos lose their `category_id` in the bootstrap (gap A). **DATA.**

## 5. Pixels — `pos-reference-shots.mjs` + `pixel-diff.php --threshold=0.5 --tolerance=24`

Evidence: `C:\Users\Dell\BingooEdgeLab\evidence\phase3\pixel-samedata\{edge,online}\1366x768` (+ `-settle3000` re-captures and the
1024×768 pair), diffs in `pixel-samedata\diff-1366`, `diff-1366-settle3000`, `diff-1024-settle3000` (red = changed, blue = status-slot mask
1090,10,275,42 — a 1366-only box). Same dataset, no open shift on either side, STANDBY appliance vs Online.

| state (1366×768) | changed pixels | classification |
|---|---|---|
| 01-main | 0.413 % | STATE — status-slot text differs outside the mask edge + "STANDBY" tone; no box moved |
| 02-category | 1.487 % | DATA — gap A (combos arrive without `category_id` → different pill set / second-category tiles) |
| 03-customer-modal | 16.4–18.8 % | DATA — gap B (Edge lookup lists nothing on an empty query; Online lists 20 customers) |
| 04-context-modal | 0.384 % | STATE (slot text) |
| 05-held-orders | 0.384 % (1.741 % in the settle-3000 pass) | DATA/TIMING — list content + a re-capture caught the list mid-refresh |
| 06-recent-orders | 1.744 % | DATA — gap C (standby mirror has no cancelled sales and no table label) |
| 08-quick-report | 0.286 % | STATE |
| 09-table-workspace | 0.000 % | identical |
| 10-cart-two-lines | 0.413 % | STATE |
| 11-review-pay | 0.084 % | identical but slot |
| 12-qty-entry | 0.241 % | STATE |
| 13-modifier-entry | 0.178 % | STATE |

1024×768 (`diff-1024-settle3000`): the status slot sits elsewhere at this width, so the 1366 mask does not cover it and every state
carries ≈0.5 % of slot text (01-main 0.553 %, 04 0.515 %, 10 0.553 %); 02 2.6 % / 03 21.8 % / 05 1.7 % / 06 50.2 % are gaps A/B/C
(the recent-orders list fills the stacked 1024 layout); 09/11/12/13 ≤ 0.29 %. No geometric difference anywhere — consistent with §4
(998 boxes, 0 geometry differences). After gaps A and B are fixed the only data-driven pair left is 06-recent-orders (gap C, owner call).

## 6. Product gaps found (shared view, dataset-independent; same DB content on both sides) — REPORTED, not patched

**A. Bootstrap `combos` section drops `category_id`.** `app/Services/Edge/EdgeBootstrapService.php` (sellable sections, `'combos' =>`
column list `['id','branch_id','code','name','price','sort_order','status','description']`) omits `category_id`, so every combo arrives
on the appliance uncategorised (`bingoo_edge_devclone_local.combos.category_id = NULL` while the clone has `8`). The shared view then
takes the legacy branch (`PosPageData.hasUncategorizedCombos = true`): the flat **"Deals"** pill is rendered first and the real
**"Lab Deals"** category (id 8) has no content on Edge, so its pill is hidden (`pillCategoryIds`). Visible effect: a different pill set
and order (Edge `All · Deals · Lab Menu · …`, Online `All · Lab Menu · … · Lab Deals`), and the "second category" state shows different
tiles. Fix location: add `category_id` to that column list (and to the config-refresh section built from the same list, line ~599
`$add('combos','branch_id')` watermark + the refresh applier's `combos` upsert) and bump the bootstrap/config contract.

**B. Edge customer lookup requires ≥ 2 characters; Online lists on an empty query.** `EdgeLocalPosController::customers()`
returns `{"customers":[]}` for `mb_strlen($q) < 2` (and no `id`), whereas Online `Ajax\CustomerLookupController` with `q=''` returns the
first 20 active customers ordered by name. The shared modal therefore opens populated on Online and empty ("Start typing…") on Edge.
Fix location: drop the 2-character floor in `customers()` (keep the 20-row limit + name ordering) so the W-B canonical contract matches.

**C. Recent Orders on a STANDBY appliance = the returnable-sale mirror, which carries no table and no cancelled sales.** The Online
list (`POSController::recentSales`: every non-held sale, newest 50, `table => restaurantTable.table_no`) showed 23 rows incl. the cancelled
`HS-20261003230229-433` and a `Table G6 ·` label per dine-in row. The Edge list (`EdgeLocalOrderLifecycleService::recentSales` over the
local `sales_orders`) showed the 22 `cloud_mirror` rows written by `EdgeReturnableSaleCacheService::insertShadowSale()` — the Cloud
projection `EdgeReturnableSaleProjectionService` sends only `RETURNABLE_STATUSES` (no cancelled sales) and `restaurant_waiter_id` but
**not `restaurant_table_id`**, so every mirrored row lacks its table (`22 of 22 mirrored sales have restaurant_table_id NULL`) and the
newest (cancelled) Online order is absent. Visible effect: pixel diff of `06-recent-orders` (1.7 %), rows offset by one and the table label
missing. Whether a standby's Recent Orders should mirror Online's history at all is an owner call (the mirror exists for returns); if it
should, the projection needs `restaurant_table_id` (+ the table row must exist locally — it does, tables are bootstrapped) and the
Online list's status rule.

None of A–C is a geometry fact: with equal data both sides place every box identically.

## 7. Pairing/bootstrap flow observations (not bugs, worth knowing)

- A cloned tenant carries the LAB device row AND the LAB lease row: pairing a new appliance needs the copy revoked first (`DEVICE_CONFLICT`
  otherwise — the product's one-device-per-branch rule), and the first heartbeat of the new device is refused `409 STALE_HEARTBEAT` because
  the lease row keeps the old sequence (22126); the appliance's sequence-resync (P5C fix) adopts it and the next beat is accepted.
- `generateCode` moves the branch `inactive → pending` and `BranchOperatingModeService::transition` sets `sales_operating_mode = local_edge`
  for every non-inactive status, including `pending` — on a Cloud that must keep selling Online (the clone) the mode has to be set back to
  `cloud` by hand after pairing (the G2 team hit the same thing). `cloudSaleMutationBlocked` only fences `active/closing/suspended`, so
  sales were not blocked, but other code paths read the flag (reservation refusal in the G2 report).
- `edge:authority:release` looks the tenant up with `Tenant::where('code', …)` while the column is `tenant_code` — @@RELEASE_CMD@@

## 8. Re-run commands

```
# instance (worktree root)
tools/edge-dev-instance/serve-clone.sh start            # :8096 (stop|status)
tools/edge-dev-instance/serve-clone.sh worker start     # lease renewal while comparing; `worker stop` + release-lease afterwards

# geometry (Git Bash; MSYS_NO_PATHCONV=1; node D:/laragon2/bin/nodejs/node-v20.20.1-win-x64/node.exe; cwd tools/edge-browser-proof)
POS_SHOT_PASS=<secrets\devclone-cashier.pass> node geometry-compare.mjs --mode edge  --base-url http://127.0.0.1:8096 --path /edge/local/pos/shared --user LAB2C5D --settle 6000 --out C:/Users/Dell/BingooEdgeLab/evidence/phase3/geometry-samedata/edge
POS_SHOT_PASS=<secrets\cashier.pass>          node geometry-compare.mjs --mode cloud --base-url http://edgehomelab.localhost:9704 --user lab.cashier@edgehomelab.test --settle 6000 --out C:/Users/Dell/BingooEdgeLab/evidence/phase3/geometry-samedata/online
node geometry-compare.mjs --compare --a .../geometry-samedata/edge/report.json --b .../geometry-samedata/online/report.json --out .../geometry-samedata/compare.json

# pixels
POS_SHOT_PASS=<devclone-cashier.pass> node pos-reference-shots.mjs --mode edge  --base-url http://127.0.0.1:8096 --path /edge/local/pos/shared --user LAB2C5D --settle 8000 --out C:/Users/Dell/BingooEdgeLab/evidence/phase3/pixel-samedata/edge
POS_SHOT_PASS=<cashier.pass>          node pos-reference-shots.mjs --mode cloud --base-url http://edgehomelab.localhost:9704 --email lab.cashier@edgehomelab.test --settle 8000 --out C:/Users/Dell/BingooEdgeLab/evidence/phase3/pixel-samedata/online
php pixel-diff.php <online>/1366x768 <edge>/1366x768 <out>/diff-1366 --threshold=0.5 --mask=1090,10,275,42
php pixel-diff.php <online>/1024x768 <edge>/1024x768 <out>/diff-1024 --threshold=0.5 --mask=745,10,275,42     # status slot x=747 w=260 at 1024

# clone Cloud side
php C:\Users\Dell\BingooEdgeLab\scripts\devclone-cloud.php status | release-lease "<reason>" | branch-mode cloud
```
