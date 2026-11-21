# Merged tree 087278e9 (canonical 025728d8 = CANONICAL_RELEASE_FREEZE) — browser gates on BOTH runtimes + the Online pixel/geometry baseline (Team FB2, 22 Oct 2026)

Worktree `D:\laragon2\www\pos-saas-edge`, branch `feat/edge-config-refresh-v1`, HEAD `087278e9` (canonical `025728d8` integrated in 5 chunks:
REFUND-SHIFT-1, RETURN-RESTOCK-1 + RX-BRANCH-SETTING-1, VOID-ON-ORIGINAL-DATE-1, MEDICAL-COUNTER-RX-POPUP-1 / PACK-QTY-REPORTS-1 /
MASTER-NEW-SCREENS-1 / GRN-VOID-OWN-BATCH-1 / STARTER-PLAN-COUNTER-1, RETURN-NO-BILL-1 + schema). Team FB2 repeated Team FB's §1–§7
procedure of `edge-merged-tree-browser-and-baseline.md` (768ac71) on this tree. Nothing under `app/ resources/ routes/ tests/` was touched,
the harness (`tools/edge-browser-proof/*.mjs`) was NOT edited, nothing was committed. The LAB appliance (`:8443`), the LAB Cloud (`:9701`),
the LAB databases, the dev Cloud `:9702` and production were never touched — `pos_lab_tenant_edge.sales_orders` = **5** before, during and
after every step. Secrets were read from `C:\Users\Dell\BingooEdgeLab\secrets\*.pass` into the environment and never printed.

Instances: ONLINE clone `http://edgehomelab.localhost:9704` (`pos_devonline_master_edge` → `pos_devonline_tenant_edge`, tenant migrations
**212 → 215** on this tree); PAIRED dev Edge `http://127.0.0.1:8096` (`bingoo_edge_devclone_local`, device `c03a54be-…`, epoch 2,
schema-upgraded through the D7 plan of this tree); DEV Edge `http://127.0.0.1:8095` (`bingoo_edge_devtest_local`, re-seeded on this tree,
manual Local Mode).

```
ONLINE_BROWSER                   = 17/17 PASS (+ W03M PASS)   clone :9704 on 087278e9, 0 fenced 409s, 4 expected 4xx; capabilities now carry returnWithoutBill:true (§1)
EDGE_BROWSER                     = 17/17 PASS (+ W03M PASS + W18 PASS; W15 = EXPECTED-DIFFERENCE on the never-paired 8095: 422 CLOUD_CAPABILITY_NOT_ADVERTISED)   dev Edge :8095 fresh 087278e9 seed, 0 unexpected 4xx, returnWithoutBill:false (§1b); the PAIRED 8096 additionally proved W01/W02/W18/W15/W16 as the branch WRITER (§3) and W01/W18/W15 in STANDBY (§2)
W15                              = PASS ×5 on the pair — CREATE_NEW (local 1000000004 / uuid 01M5GANDG2KQZ7PKHE6XPSESQA → Cloud customer 17 under the SAME uuid, address 01M5GANJEC… once, sale 69 → 17) · PHONE_MATCH (0092 300 1110001 → reused id 1; Cloud-only +92 300 4040771 = customer 16 matched by phone_normalized, name kept) · ALIAS (01M5GAPYVC7R30244RDZ9DR6K2 → customer 16 / 01M5GAE9T0…, source phone_match, device c03a54be; sale 71 → 16) · REPLAY_IDEMPOTENT (rows 8 + 10 re-POSTed byte-identically → 200 already_applied with the first ingestion_uuid / official_sale_no; counts 17/10/3/71/15 unchanged) · REFRESH_RECONCILIATION (25→27: local 1000000005 merged into 16, sale 71 repointed, 1000000004 keeps its local id under the Cloud uuid, 18 customers, 0 duplicate phone keys, 0 deletions) (§3)
W18                              = PASS on BOTH runtimes through the SAME control — Online: POST /api/pos/reminder-pause 200 paused:true → "Reminder OFF" → 200 paused:false → "Reminder ON"; Edge 8096 LOCAL MODE: identical sequence against POST /edge/local/pos/reminder-pause on the LOCAL open shift; Edge STANDBY: 422 STANDBY negative with the control rendered ENABLED by the server; Edge 8095: DATA-PRECONDITION probe 200 (no Reminder rule in the seed) (§4)
ONLINE_PIXEL_REFERENCE           = RECAPTURED on 087278e9 — C:\Users\Dell\BingooEdgeLab\evidence\phase5\online-baseline-087278e9\{1366x768,1024x768}\ (12 states each, 07-recent-prints skipped on both runtimes as always) = the new authority; vs the previous reference (online-baseline-merged, 768ac71): 11/12 states ≤ 0.03 % (tile "Stock NN" digits = DATA), 06-recent-orders 0.63 % / 0.85 % (list content = DATA) — NO intentional canonical UI change is visible in the 12 POS states between 768ac71 and 087278e9 (§5)
EDGE_VS_ONLINE_PIXELS            = 1366: 9/12 states 0.000 % identical, 08 0.279 % / 11 0.084 % STATE (capability), 06-recent-orders 7.07 % DATA; 1024: 8/12 identical, 08 0.374 % / 11 0.112 % / 13 0.164 % STATE, 06 7.86 % DATA — zero UNINTENTIONAL drift, same pattern as 768ac71 to the third decimal (§6)
EDGE_VS_ONLINE_GEOMETRY          = NONE — 998 boxes compared (12 states × 2 viewports), 0 geometry / 0 DATA / 0 presence differences, max deviation 0 px (§7)
PLAN_HASH_8096                   = sha256:fbfdfe4fcbd00f988277cb4b4c4f54e4f43e87dd1e75c258d187869b0568f0b1 (edge-tenant-migration-manifest@2026-10-22.1; RUN = exactly 2026_10_20_000005 / 000006 / 000007, SKIP_CLOUD_ONLY 24, blockers 0; applied on 8096, migrations 213 → 216)
LAB sales_orders                 = 5 (unchanged)
```

## 0. Preparation of the three disposable instances on the merged schema

| step | result |
|---|---|
| all three answer HTTP before anything else | `edgehomelab.localhost:9704/login` 200, `127.0.0.1:8095/edge/local/health` 200, `127.0.0.1:8096/edge/local/health` 200 (the PHP servers started by Team FB on 20 Oct were still alive; no restart needed) |
| clone Cloud 9704 — `migrate-devclone-tenant.php` (pretend) | master `pos_devonline_master_edge`: Nothing to migrate (49 rows); tenant pending = exactly `2026_10_20_000005_add_restocked_to_sales_return_lines`, `…000006_add_ask_prescription_to_branches`, `…000007_return_without_bill_schema` |
| clone Cloud 9704 — `--apply` | all three DONE (120 ms / 1 s / 318 ms); **tenant migrations 212 → 215**; `sales_return_lines` has `restocked / unit_cost / inventory_batch_id`, `sales_returns.sales_order_id` nullable; `customers.phone_normalized`, `customer_addresses.address_uuid`, `customer_uuid_aliases` still present |
| clone lease before | `holder cloud / edge_state standby / expires_at NULL / fenced_at NULL / released_at 2026-10-20 21:58:03` ("Team O1 repro attempt 1 complete") — as Team O1 left it (revision 24) |
| 8096 — `edge:local:schema-upgrade --dry-run` | plan `edge-migration-plan-v1`, manifest `edge-tenant-migration-manifest@2026-10-22.1`: **RUN = exactly the 3 new migrations** (EDGE_REQUIRED), SKIP_CLOUD_ONLY 24, blockers 0, verdict OK, **plan_hash `sha256:fbfdfe4fcbd00f988277cb4b4c4f54e4f43e87dd1e75c258d187869b0568f0b1`** (= the D7 plan for this tree; JSON copy in the scratchpad `fb2-8096-plan.json`) |
| 8096 — apply | "Edge-local schema upgraded." applied the 3 (102 ms / 1 s / 383 ms), 24 CLOUD_ONLY recorded never executed, same plan_hash; `migrations` 213 → 216; the 3 columns + nullability present; version `edge-local-schema@2026_10_19_000001_add_migration_plan_to_edge_local_updates` |
| 8096 — `authority-status` before | `standby`, connection `online`, all 9 gates true (incl. `AUTHORITY_TAKEOVER_SAFE` — the clone lease is released), applied revision 24 = advertised 24, stock watermark current; outbox 7 acknowledged; 15 customers, 55 local sales_orders, 2 aliases, 0 open shifts; worker NOT running |
| 8095 — `bash tools/edge-dev-instance/seed.sh` | OK (1 test / 5 assertions, 1:44) — `bingoo_edge_devtest_local` re-created on this tree: 240 migrations, products 14, terminals 1/2, tables 10, DEVCASH1 / DEVMGR1; 0 sales, 0 shifts, 0 returns; `restocked / unit_cost / inventory_batch_id` + `branches.ask_prescription` present |
| W18 preconditions on the clone (from Team FB, unchanged) | `lab.cashier` (id 1) `can_pause_reminders true`; Reminder rule `category_printer_mappings` id 4 active → printer 1 "LAB FakePrinter" (`supports_reminder`); open shifts 0 |
| clone tenant features | `tenants.features` **NULL** → `return_no_bill` OFF on the clone as found (§D proves both states) |
| environment | another worktree's MySQL phpunit run (`D:\laragon2\www\pos-saas`, pid 87956, started 02:09 local) and a `seera` test run shared the MySQL server during this work — load only, isolated DB names; not touched |

## 1. ONLINE W01–W17 on the merged clone (`…\evidence\phase5\proof-online-087278e9\`)

`shared-pos-workflows.mjs --mode online --base-url http://edgehomelab.localhost:9704 --user lab.cashier@edgehomelab.test` (full suite,
22:15 → 22:28 UTC, 13 min; no `--clear-all-open-work` — W16 leftovers `{held:[], sessions:[]}`). **17/17 PASS + W03M PASS, 0 fenced 409s.**

| W | verdict | what the merged Online POS did on 087278e9 |
|---|---|---|
| W01 | PASS | slot `ONLINE · CLOUD`, terminal `Lab Counter 1` (id 1), 15 tiles, **15/15 with the SKU line** (`LABFAMILY`, `LABTEA`, `LAB-36A73F`); `POS_RUNTIME.capabilities` carries `reminderPause:true, websiteOrders:true, partnerShare:true` AND the new **`returnWithoutBill:true`** (Cloud ON); `#pos-reminder-toggle` present, no `disabled` attr in the markup, `Reminder ON`, wrap visible; `#web-orders-btn` absent (plan without Online Ordering, as on 768ac71); `#qr-email` "Send to owner" enabled; no medical-counter chrome |
| W02 | PASS | `No open shift` → `/shifts/open` 200 → POST 302 → **shift 5** open, business date 2026-10-22 |
| W03 / W03M | PASS / PASS | `POST /pos` 200 → `SO-20261021221646-154` (sale 56), receipt + KOT |
| W04 | PASS | customer search → sale |
| W05 | PASS | hold → list → recall → settle |
| W06 | PASS | open table → KOT round 1 + 2 → preview → bill requested → move → merge → settle after merge |
| W07 | PASS | void KOT-sent line (PIN approval) → re-hold |
| W08 | PASS | 10 % + fixed discount with approval; hold-with-unconsumed-discount 422 (shared rule, expected) |
| W09 | PASS | Return button URL `/sales-returns/create?embed=1`; picker finds the W03 sale; after the bill pick the iframe is at **`/sales-returns/create?embed=1&terminal_id=1&sales_order_id=56`** (REFUND-SHIFT-1: `terminal_id` carried through the bill pick); refund methods cash/bank_transfer/card/other all enabled (Cloud); POST 302 → `/sales-returns/4`; returns list shows **`SR-20261021222340-695`**. Clone DB (ids only): `sales_returns` 4 = sale 56, **`shift_id 5` (the W02 shift), `terminal_id 1`**, cash; its line `restocked 1`, `unit_cost NULL`, `inventory_batch_id NULL` |
| W10 | PASS | reprint receipt |
| W11 | PASS | print panel; retry probe on queued job 138 → 422 `Only failed or cancelled jobs can be retried.` (expected); re-queue 200 "Reminder Duplicate 1 queued" |
| W12 | PASS | view / network 200 (LAB FakePrinter) / **Send to owner** enabled → 422 `No owner email is configured` (dataset, expected) |
| W13 | PASS | reserve → details → unreserve |
| W14 | PASS | split bill |
| W15 | PASS | quick-store 200 → **customer 15 `01M5G943RYWARKPMPVNMN8WY97`**, typed `+92 300 1545516` → stored `923001545516`, `phone_normalized 03001545516`; address 8 `01M5G94950MAYK1M4C5D9Q26ED` (POST 200); sale `SO-20261021222601-683` (id 68) → customer 15 |
| W16 | PASS | this run's 4 open items settled/cancelled through the UI (approval `MA-20261021222702-bxLBGX`), close 302 → `/shifts/5`, `No open shift` |
| W17 | PASS | logout → login |

HTTP ≥ 400 in the whole run (all expected): `GET /dashboard 403` (cashier landing), W08 `POST /held-sales 422` (shared rule), W11 retry 422, W12
e-mail 422. Console: 621 × asset `404 Not Found` (product images, as in every run), 1 × 403, 3 × 422 — no page error. Disabled census: only
`#pos-reminder-toggle` (disabled by the view's JS while no shift is open) and the `#bill-preview-btn` tooltip. Clone after: customers 15 /
addresses 8 / aliases 2 / sales_orders 68 / ingestions 12 / sales_returns 4, open shifts 0.

**W18 opt-in on Online** (`--only W01,W02,W18,W16`, `…\proof-online-w18-087278e9\`, 22:28 → 22:30 UTC): W01/W02/W18/W16 all PASS, only the
usual `GET /dashboard 403`. Shift 6: contract `capabilities.reminderPause true`, route `/api/pos/reminder-pause`; server render ENABLED (no
`disabled` attr, no title); shift-status `reminder {available true, paused false, can_toggle true}`; click → Swal "Pause Reminder slips on this
terminal?" → **`POST /api/pos/reminder-pause` 200 `{reminder:{available:true, paused:true, paused_by:"Lab Cashier", paused_at:"03:29",
can_toggle:true}}`** → `Reminder OFF` (`data-paused=1`, title "Reminder slips paused by Lab Cashier at 03:29 until this shift closes. KOT and
receipts still print.", toast "Reminder paused until this shift closes") → shift-status `paused true` → click → **200 `{paused:false}`** →
`Reminder ON`, toast "Reminder is ON" → shift-status `paused false`. Shots `W18-…-01-before/02-paused/03-resumed.png`.

## 1b. EDGE W01–W17 (+W18) on the dev Branch Server 8095 (`…\evidence\phase5\proof-edge-087278e9\`)

After the re-seed of §0, `shared-pos-workflows.mjs --mode edge --base-url http://127.0.0.1:8095 --user DEVCASH1 --manager-user DEVMGR1
--only W01,W02,W18,W03,W03M,…,W17` (22:31 → 22:45 UTC, 14 min). **17/17 PASS + W03M PASS + W18 PASS (DATA-PRECONDITION); W15 =
EXPECTED-DIFFERENCE** on the never-paired 8095 exactly as on 768ac71.

| W | verdict | Edge (manual Local Mode, slot `LOCAL MODE · MANUAL SWITCH`) on 087278e9 |
|---|---|---|
| W01 | PASS | 16 tiles, **16/16 with the SKU line** (`FAMILY`, `TEATIME`, `SKU-BEEFSEEK-642`); `#web-orders-btn` present + disabled + D4 hint; `#qr-email` "Send to owner" disabled + E1 hint; `#pos-reminder-toggle` present, no `disabled` attr (wrap hidden — no Reminder rule in the seed); no medical chrome; capabilities `reminderPause true`, `customerCreate false`, `websiteOrders false`, `partnerShare false`, **`returnWithoutBill false`** (the new server-only key, OFF on Edge — label "A return without a bill is recorded on the Online POS — it needs the Cloud (today's price, the batch it goes back to); this Branch Server returns against a bill only.") |
| W02 | PASS | separate page → POST 302 → `Shift open` (shift 1) |
| W18 | PASS (DATA-PRECONDITION) | switch hidden by the shared rule (`statusFor available=false`: the dev seed ships KOT rules only); direct `POST /edge/local/pos/reminder-pause {terminal_id, paused:true}` → 200 `{reminder:{available:false, paused:false, paused_by:null, paused_at:null, can_toggle:true}}` (Online's shape), resume → 200 same; no 403 (D3 permission held by DEVCASH1); shift 1 closes with `reminders_paused_at NULL` |
| W03 / W03M | PASS / PASS | `POST /edge/local/pos/sales` 201 → `SO-1-1-01M5G9J5G8WXHT3YYWQ26EMV6E`, receipt + KOT to the FakePrinter |
| W04 | PASS | book customer (seeded `customer_uuid`) → sale |
| W05 | PASS | hold → list → recall → settle |
| W06 | PASS | open → KOT round 1 + 2 → preview → bill requested → move → merge → settle after merge |
| W07 | PASS | void KOT-sent line with DEVMGR1 code + credential → re-hold |
| W08 | PASS | discounts with approval; hold-with-discount 422 (shared text, identical to Online) |
| W09 | PASS | Return button URL `/edge/local/pos/sales-returns/create?embed=1`; after the bill pick the iframe is at **`/edge/local/pos/sales-returns/create?embed=1&terminal_id=1&sales_order_id=1`** (the Edge twin accepts and keeps `terminal_id` — REFUND-SHIFT-1 URL contract honoured); refund methods: cash enabled, bank_transfer/card/other disabled with the Edge hint; POST 302 → `/edge/local/pos/shared/sales-returns/1`, **`SR-1-1-01M5G9YSYG9BS1KP2KN61PKATJ`** shown in the frame and in the returns list. 8095 DB (ids only): `sales_returns` 1 = sale 1, cash, line `restocked 1` (column default), `unit_cost NULL`, `inventory_batch_id NULL`, **`shift_id NULL`, `terminal_id NULL`** (finding F-FB2-1, §8) |
| W10 | PASS | reprint |
| W11 | PASS | retry probe on queued job 20 → 422 `Only failed or cancelled jobs can be retried.` (same text as Online); re-queue 201 "KOT re-queued" |
| W12 | PASS | view / network 200 (Counter receipt FakePrinter) / `Send to owner` disabled with the E1 hint |
| W13 | PASS | reserve → details → unreserve |
| W14 | PASS | split bill |
| W15 | EXPECTED-DIFFERENCE | `customerCreate false` (never paired, nothing advertised): `#qa-save` disabled with the hint; direct POST → 422 `{ok:false, capabilityOff:true, code:CLOUD_CAPABILITY_NOT_ADVERTISED}` (fail closed) |
| W16 | PASS | this run's 4 open items settled/cancelled (approval `MA-20261021224406-y7yXuO`, employee_code+credential), close 302, `No open shift` |
| W17 | PASS | logout → login |

HTTP ≥ 400 (all expected): W08 422 (shared rule), W11 422 (queued-job retry), W15 422 CLOUD_CAPABILITY_NOT_ADVERTISED. Console: 646 × asset
404, 3 × 422; no page error. Disabled-with-hint census: `#branch_id`, `#web-orders-btn`, `#qr-email`, `#qa-save`, `#new-addr-save` (+ the
`#bill-preview-btn` tooltip) — identical to 768ac71. End state: `bingoo_edge_devtest_local.sales_orders` 12 (0 held), 1 sales_return, 0 open shifts.

## 2. PAIR — refresh of the migrated clone into the appliance (8096, STANDBY)

| step | result |
|---|---|
| `serve-clone.sh worker start` (22:31 UTC, after the schema upgrade of §0) | tick 1 `config: "refreshed:24->25"`, stock `refreshed:cutover`, returnable `refreshed:61-sales`, supplier_finance `refreshed:0-suppliers`; tick 2 all `current` |
| `laravel.log` `[edge-config-refresh] applied {revision 25, from 24, snapshot refresh-25-f30e5756…}` | `branch updated 1` (the row now carries `ask_prescription` — column present on 8096 since §0), products 13, product_barcodes 5, printers 1, `category_printer_mappings updated 4` (the Reminder rule among them), `users updated 2`, **`customers {updated 14, inserted 1, kept_local 1}`** (Online W15's customer 15 of §1), `customer_addresses {updated 7, inserted 1, kept_local 1}`, `customer_uuid_aliases updated 2`, void_reasons 3. No medical / pack section (not an edge-config-v1 section, as on 768ac71) |
| `edge:local:authority-status` | `standby`, connection `online`, every gate true except `AUTHORITY_TAKEOVER_SAFE` (false while the Cloud lease is alive — correct), `STANDBY_FRESH_ENOUGH true`, applied 25 = advertised 25, `last_ack_at 22:32:22` |
| 8096 DB after (ids only) | customer **15 `01M5G943RYWARKPMPVNMN8WY97` present, `edge_origin cloud`**; 16 customers / 9 addresses / 2 aliases; local `sales_orders` 68 (= the clone's 68, the returnable mirror); 0 open shifts; `edge_local_meta.last_applied_config_revision 25`, `cloud_capabilities_seen {"customer_create":true}` |
| clone lease while the worker runs | `holder cloud / standby`, heartbeat renewed every 20 s (`expires_at` = heartbeat + 120 s), `fenced_at NULL`, `released_at NULL` |

Render check on 8096 in STANDBY (`--mode edge --only W01,W18,W15`, `…\evidence\phase5\w15-087278e9\edge-standby\`, 22:46 → 22:47 UTC): slot
**`STANDBY · CLOUD AUTHORITY`**, 15 tiles 15/15 with the SKU line (same samples as Online); capabilities `reminderPause:true` (route
`/edge/local/pos/reminder-pause`), `customerCreate:true` + `customerAddressCreate:true` (advertised), `websiteOrders:false`, `partnerShare:false`,
**`returnWithoutBill:false`**; disabled-with-hint census `#web-orders-btn`, `#qr-email`, `#branch_id` (+ the two ordinary tooltips);
`#pos-reminder-toggle` rendered ENABLED by the server (no `disabled` attr, no title), disabled only by the view's JS (no open shift).
**W18 → 422 `{ok:false, code:"STANDBY", message:"Local Mode is not active — the Cloud POS is the active writer for this branch (standby)."}`**
(EXPECTED-DIFFERENCE) and **W15 → the same STANDBY 422** with `#qa-save` enabled (EXPECTED-DIFFERENCE) — both from the lease gate BEFORE any
write; nothing created (customers still 16 on 8096).

## 3. W15 end to end on the pair (exact `edge-phase4-w15-proof.md` sequence)

| # | when (UTC) | command / action | result |
|---|---|---|---|
| 1 | 22:48:16 | `serve-clone.sh worker stop` | graceful after 47 ticks; lease `holder cloud, standby, last_heartbeat 22:48:05, expires_at 22:50:05` |
| 2 | 22:48:26–22:49:04 | Online `--only W01,W15 --qa-phone "+92 300 4040771"` (`…\w15-087278e9\online-cloud-only-customer\`) — a customer only the Cloud knows, created AFTER the appliance's last refresh (revision 25, tick 47) | quick-store 200 → **customer 16 "Zed Proof 24527", phone `+92 300 4040771` → `03004040771`, uuid `01M5GAE9T011H69QM00CCYH1VH`** (created 22:48:49); the run's later cash sale 422 "No open shift" is outside this proof, as in Team FB's / Team W's runs |
| 3 | 22:50:39 | lapse: `UTC_TIMESTAMP() > expires_at + 30 s` | lease still `holder cloud / standby`, `fenced_at NULL`, `released_at NULL` |
| 4 | 22:50:40 | `edge:local:authority-takeover --confirm --by="Team FB2 087278e9"` | **`LOCAL MODE ACTIVATED.`** — `local_active`, connection `reconciling` ("outbox drained — reconciling with the Cloud"), all gates true incl. `AUTHORITY_TAKEOVER_SAFE`, `STANDBY_FRESH_ENOUGH` (`--accept-stale` never used), applied revision 25 |

| 5 | 22:50:56–22:55:11 | Edge WRITER run `--mode edge --base-url http://127.0.0.1:8096 --user LAB2C5D --manager-user LABMF2DE --only W01,W02,W18,W15,W16 --qa-phone "+92 300 5550102" --qa-existing-phone "0092 300 1110001" --qa-cloud-only-phone "+92 300 4040771"` (`…\w15-087278e9\edge-writer\`) | **W01 PASS, W02 PASS, W18 PASS, W15 PASS, W16 PASS — 0 HTTP errors, 0 fenced 409s**; slot `SYNCHRONIZING · LOCAL MODE` (worker stopped → connection stays `reconciling`, O3 of Team FB) |
| 6 | 22:55:51 | `serve-clone.sh worker start` (while LOCAL_ACTIVE) | tick 1 `ack reconciling {local_sync, drained 3, acknowledged 3, reconciled true, clean true, in_sync 10}`; tick 2 idle/clean; clone lease → `holder edge, local_active, fenced_at 22:55:53` |
| 7 | 22:56 | `edge:local:sync-status` / `sync-send` ×2 | `Local selling: SAFE (baseline selling)`; `Outbox: 0 pending, 0 leased, 10 acknowledged, 0 failed`; both sends `0 acknowledged, 0 retryable, 0 terminal, 0 rejected` (acknowledged rows are never resent) |
| 8 | 22:57:55 | replay (Team W's `replay-outbox.php 8 10`, byte-identical bytes through the product transport, device headers from config) | row 8 → 200 **`already_applied`**, `ingestion_uuid 01M5GAV8Q77A81WE22F8EC27G8`, `SO-20261021225554-657`; row 10 → 200 **`already_applied`**, `01M5GAVA2M45F4VW6XYSW73QA0`, `SO-20261021225556-913` — `ack_matches_row true` on both (content hashes `61aab1cf…`, `a2eba2d7…`) |
| 9 | 22:58:30–22:58:37 | `authority-handback --assess` → `authority-handback --by="Team FB2 087278e9"` | assess **`ready true`** (0 pending / 0 failed / 0 open tables / 0 held / 0 open shifts / 0 divergent, `consecutive_acks 121`, `reconcile_clean_at 22:58:28`, `cloud_holder_seen edge`); **"Authority handed back to the Cloud — the appliance is a warm standby again."** with `freshness {config:"refreshed:25->27", stock:"refreshed:cutover", returnable:"refreshed:61-sales"}`; 8096 `standby / online`, reason "handed back to Cloud by Team FB2 087278e9" |
| 10 | 22:58:33–22:58:53 | refresh adoption (inside the handback itself this time) + worker ticks | `laravel.log [edge-config-refresh] applied {revision 27, from 25}`; worker tick 9 `standby_freshness` all `current`; **no `CUTOVER_ALREADY_DONE` stock error on this run** (Team FB's O1 did not reproduce — the refresh ran inside the handback command, before the worker's next tick) |
| 11 | 22:58:53–22:59:10 | `serve-clone.sh worker stop` → `devclone-cloud.php release-lease "Team FB2 087278e9 W15 complete: worker stopped"` | worker "stopped gracefully"; `released: true, fenced: false`; lease `holder cloud, standby, released_at 22:59:10` |

### 3.1 The WRITER run — W15 facts (all HTTP 200 / 201)

| quick-add | typed | quick-store | attached | sale |
|---|---|---|---|---|
| new | `+92 300 5550102` / "Zed Proof 59712" | 200 `reused:false` → **local id 1000000004**, phone `923005550102`, **`phone_normalized 03005550102`**, **`customer_uuid 01M5GANDG2KQZ7PKHE6XPSESQA`**; lookup lists it at once; address book → `POST …/customers/1000000004/addresses` 200 `{address{id 1000000003, address_uuid 01M5GANJECS0Q1KZB93CJ6FNZR, "W15 Street 3948, Proof Town", is_default}}` | chip + hidden `customer_id` | **69** `SO-1-1-01M5GANTBKYCYWN0HCC3B45F8Z` 201 |
| existing phone | `0092 300 1110001` / "Zed Twin 77420" | 200 **`reused:true`** → id 1 "Lab Customer One" (name kept), uuid `01M430P442XHM8SJW11G74JBXH`, both addresses with `address_uuid`; toasts "Existing customer matched on this phone number" + "Customer attached: Lab Customer One" | chip Lab Customer One | **70** `SO-1-1-01M5GAPFE4NNVNZC7JDFA72RFK` 201 |
| Cloud-only phone | `+92 300 4040771` / "Zed Alias 98902" | 200 `reused:false` — the till does not know this phone (created Online after its last refresh) → **local 1000000005**, `phone_normalized 03004040771`, **uuid `01M5GAPYVC7R30244RDZ9DR6K2`** | chip | **71** `SO-1-1-01M5GAQ37KEMVV359K3TYA7B70` 201 |

W02 opened shift 4 on terminal 1 through the separate page; W16 closed it (open work `{held:[], sessions:[]}`, close 302, `No open shift`).

### 3.2 Envelopes (`edge_sync_outbox` on 8096, immutable bytes)

| row | sale | schema | customer.kind | content |
|---|---|---|---|---|
| 8 | 69 | **`edge-sale-envelope-v3`** | **`new_customer`** | `customer_uuid 01M5GANDG2…`, `new_customer.phone_normalized 03005550102`, `addresses` ×1 (`01M5GANJEC…`) |
| 9 | 70 | `edge-sale-envelope-v1` | `customer` | `customer_uuid 01M430P442…` (a synced customer travels in the unchanged v1 shape) |
| 10 | 71 | **`edge-sale-envelope-v3`** | **`new_customer`** | `customer_uuid 01M5GAPYVC…`, `phone_normalized 03004040771`, `addresses` ×0 |

Every `shift` block carries identity only: `opened_at, shift_uuid, terminal_id, business_date, opened_by_user_id` — **no `reminders_paused_*` field**
(D2 reconciliation rule; the W18 pause/resume in the same shift left nothing in the envelope; closed shift 4 ends with `reminders_paused_at NULL`).
All three rows `acknowledged`, `attempts 1`.

### 3.3 Cloud ingestion on the clone (SQL, ids only)

`edge_inbound_sale_ingestions` 13–15: `01M5GANTBK… v3 applied → SO-20261021225554-657`, `01M5GAPFE4… v1 applied → SO-…-661`,
`01M5GAQ37K… v3 applied → SO-…-913`; `failure_code NULL`; ingestions 12 → 15 (no row for a replay).

- **A — new phone (CUSTOMER_CREATE_NEW):** `customers` **17** "Zed Proof 59712", `phone_normalized 03005550102`, **`customer_uuid
  01M5GANDG2KQZ7PKHE6XPSESQA`** (= the offline uuid, canonical); exactly 1 customer with that key; `customer_addresses` 10 → customer 17,
  **`address_uuid 01M5GANJECS0Q1KZB93CJ6FNZR`** (= the till's), default — once; `sales_orders` 69 `customer_id 17`.
- **B — existing normalized phone (CUSTOMER_PHONE_MATCH + CUSTOMER_ALIAS):** the Cloud-only customer **16** (`01M5GAE9T011H69QM00CCYH1VH`,
  Online-created at 22:48:49) MATCHED: still 1 customer with `phone_normalized 03004040771` (name "Zed Proof 24527" kept, not renamed to
  "Zed Alias"); **`customer_uuid_aliases` row: `alias_uuid 01M5GAPYVC7R30244RDZ9DR6K2 → customer_id 16 / customer_uuid 01M5GAE9T0…`,
  `source phone_match`, `source_device_uuid c03a54be-…`** (the book now holds 3 rows: Team W's `01M5868P…→10`, Team FB's `01M5C6FRHS…→13`,
  this one); `sales_orders` 71 `customer_id 16`. Sale 70 → customer 1 (kind `customer`, no alias needed).
- **C — replay (CUSTOMER_REPLAY_IDEMPOTENT):** after both byte-identical replays: customers **17**, addresses **10**, aliases **3**,
  sales_orders **71**, ingestions **15** — unchanged; the ACKs carried the first application's `ingestion_uuid` / `official_sale_no`.
- **D — refresh (CUSTOMER_REFRESH_RECONCILIATION):** the handback applied **25 → 27** (`customers {updated 16, inserted 1, kept_local 2}`,
  `customer_addresses {updated 9, inserted 1, kept_local 1}`, `customer_uuid_aliases {updated 2, inserted 1, merged 1}`, `users {updated 2}`,
  `category_printer_mappings {updated 4}`, `branch {updated 1}`); log `local customer merged into canonical {alias_uuid 01M5GAPYVC…,
  customer_uuid 01M5GAE9T0…, from_id 1000000005, to_id 16}`. 8096 after: customer **16** inserted (`cloud`), **1000000005 merged away and
  sale 71 repointed to 16**, **1000000004 keeps its LOCAL id under the Cloud uuid `01M5GANDG2…` (`edge_origin → cloud`; Cloud id 17 ≠ local
  id — harmless, every cross-system reference is by uuid)**, Team W's local-only 1000000000 untouched (`local`), Team FB's 1000000003 still
  `cloud`; **18 customers, 0 duplicate `phone_normalized` keys**, sales 69→1000000004, 70→1, 71→16; `cloud_capabilities_seen {"customer_create":true}`.

## 4. REMINDER_PAUSE — D2 browser proof on both runtimes (opt-in harness workflow W18, unchanged from Team FB)

Preconditions as on 768ac71 (clone only, through the product models): `lab.cashier` holds `tenant.pos.pause-reminders`, Reminder rule id 4
active → printer 1 `supports_reminder`. Both rode into 8096 again with the 24→25 refresh (`category_printer_mappings updated 4`, `users
updated 2`). The 8095 dev seed grants DEVCASH1 the permission (D3) but ships no Reminder rule (switch hidden there — probe path).

| runtime / state | verdict | exchange (browser) |
|---|---|---|
| **Online** (clone, shift 6; `…\proof-online-w18-087278e9\`) | **PASS** | contract `reminderPause true`, route `/api/pos/reminder-pause`; server render ENABLED; shift-status `reminder {available true, paused false, can_toggle true}`; click → Swal → **`POST /api/pos/reminder-pause` 200 `{reminder:{available:true, paused:true, paused_by:"Lab Cashier", paused_at:"03:29", can_toggle:true}}`** → `Reminder OFF` → shift-status `paused true` → click → **200 `{paused:false}`** → `Reminder ON` → shift-status `paused false`; toasts "Reminder paused until this shift closes" / "Reminder is ON" |
| **Edge 8096 LOCAL MODE** (shift 4; `…\w15-087278e9\edge-writer\`) | **PASS** | SAME control, SAME steps, SAME shapes against **`POST /edge/local/pos/reminder-pause`** (the D2 twin): 200 `{reminder:{available:true, paused:true, paused_by:"Lab Cashier", paused_at:"03:52", can_toggle:true}}` → `Reminder OFF` (title "Reminder slips paused by Lab Cashier at 03:52 until this shift closes. KOT and receipts still print."); 200 `{paused:false}` → `Reminder ON`; Edge shift-status reflects both; toasts identical. Closed shift 4 row ends with `reminders_paused_at NULL` |
| **Edge 8096 STANDBY** (`…\w15-087278e9\edge-standby\`) | EXPECTED-DIFFERENCE | control rendered ENABLED by the server (D2), shift-status `reminder {available true, can_toggle true}`; direct POST → **422 `{ok:false, code:"STANDBY", message:"Local Mode is not active — the Cloud POS is the active writer for this branch (standby)."}`** — the lease gate answers before the shift lookup, nothing stamped |
| **Edge 8095** (dev seed, manual Local Mode, shift 1; `…\proof-edge-087278e9\`) | PASS (DATA-PRECONDITION) | permission held, no Reminder rule → switch hidden (`available=false`, shared rule); direct POST → 200 with Online's `reminder` block both ways (`paused` stays false where no rule fires); no 403, no STANDBY |

## 4b. The RETURN screen after RETURN-NO-BILL-1 / REFUND-SHIFT-1 / RETURN-RESTOCK-1 (both runtimes)

Read-only facts from the W09 runs (§1, §1b) plus the scratchpad script `fb2-return-screen-facts.mjs` (login → POS → Return button → modal →
iframe → pick a paid sale → read the DOM; then GET the create page with `mode=no_bill` forced on the URL; nothing submitted; shots + `report.json`
under `…\evidence\phase5\return-screen-087278e9\{edge-8096-local, online-feature-off, online-feature-on}\`):

| fact | Edge 8096 (LOCAL MODE, `returnWithoutBill:false`) | Online clone, `return_no_bill` OFF (as found, `tenants.features NULL`) | Online clone, `return_no_bill` ON (flipped on the disposable clone through `Tenant::features`, then restored to NULL) |
|---|---|---|---|
| `#pos-return-btn[data-return-url]` | `/edge/local/pos/sales-returns/create?embed=1` | `/sales-returns/create?embed=1` | same |
| iframe `src` the modal loads (= `#pos-return-newtab` href) | **`…/create?embed=1&terminal_id=1`** | **`…/create?embed=1&terminal_id=1`** | same |
| after the bill pick | `…/create?embed=1&terminal_id=1&sales_order_id=69`, hidden `terminal_id` = `1` in `#return-form` | `…?embed=1&terminal_id=1&sales_order_id=68`, hidden `terminal_id` = `1` | same |
| "With bill / Without bill" tabs | **absent** (`nav[aria-label="How the item comes back"]` not rendered, body text never says "Without bill") | **absent** | **present**: `With bill` (`…?embed=1&terminal_id=1`, active) / `Without bill` (`…?embed=1&terminal_id=1&mode=no_bill`) — the kept params ride on both tabs |
| no-bill partial (`#nb-form`, `#nb-search`, `#nb-table`, `#nb-branch`) | **never** — `?mode=no_bill` forced on the URL → 200, the ordinary with-bill screen (`#sale-picker`), lead "Search a paid sale…" | **never** — `?mode=no_bill` forced → **404** (the Cloud controller refuses the no-bill way while the feature is off) | rendered only at `mode=no_bill`: `#nb-form` + ids present, `#sale-picker` absent, lead "The customer has no bill: add the items coming back, at today's price at most…" |
| restock choice (RETURN-RESTOCK-1) | `lines[0][restock]` select, "Back on shelf" = `1` (the Edge twin ignores it: row written with the column default 1) | same select, same default | same |
| `submission_key` hidden field | not in the markup on either runtime (the create view has no such input; UB-06 is not a field of this form) | — | — |
| returns list | `/edge/local/pos/shared/sales-returns` 200, 0 "No bill" badges | `/sales-returns` 200, 0 "No bill" badges | same |

So: the Edge return page shows NO "Without bill" tab and no no-bill partial (capability OFF — the tab is gated by `TenantFeatures::on(RETURN_NO_BILL)
AND $posRuntime->can('returnWithoutBill')`), the Cloud shows it only with the tenant feature on, and the POS Return button URL carries `terminal_id`
on both runtimes (the modal JS appends the page's `#terminal_id`; the bill pick and the tabs keep it).

## 5. D5 — new Online reference + the paired appliance (same dataset, both in STANDBY/Cloud, NO open shift on either side)

Captured after step 11 of §3 and after the §4b feature flip was restored (worker stopped, lease released, clone `open_shifts 0`, 8096
`open_shifts 0`, **both at config revision 27** — 8096 `config_revision_applied 27 = advertised 27`, `tenants.features NULL` again):

```
pos-reference-shots.mjs --mode cloud --base-url http://edgehomelab.localhost:9704 --email lab.cashier@edgehomelab.test --settle 8000 --out …/phase5/online-baseline-087278e9   → 24 shots, 07-recent-prints skipped ×2 (23:01–23:05 UTC)
pos-reference-shots.mjs --mode edge  --base-url http://127.0.0.1:8096 --path /edge/local/pos --user LAB2C5D --settle 8000 --out …/phase5/edge-samedata-087278e9               → 24 shots, 07-recent-prints skipped ×2 (23:05–23:10 UTC)
php pixel-diff.php …/online-baseline-087278e9/1366x768 …/edge-samedata-087278e9/1366x768 …/phase5/diff-087278e9-1366 --threshold=0.5 --mask=1090,10,275,42
php pixel-diff.php …/online-baseline-087278e9/1024x768 …/edge-samedata-087278e9/1024x768 …/phase5/diff-087278e9-1024 --threshold=0.5 --mask=745,10,275,42
php pixel-diff.php …/online-baseline-merged/1366x768   …/online-baseline-087278e9/1366x768 …/phase5/diff-online-087278e9-vs-merged-1366 --threshold=0.5 --mask=1090,10,275,42
php pixel-diff.php …/online-baseline-merged/1024x768   …/online-baseline-087278e9/1024x768 …/phase5/diff-online-087278e9-vs-merged-1024 --threshold=0.5 --mask=745,10,275,42
geometry-compare.mjs --mode edge  --base-url http://127.0.0.1:8096 --path /edge/local/pos --user LAB2C5D --settle 6000 --out …/phase5/geometry-087278e9/edge      (23:10–23:15 UTC)
geometry-compare.mjs --mode cloud --base-url http://edgehomelab.localhost:9704 --user lab.cashier@edgehomelab.test --settle 6000 --out …/phase5/geometry-087278e9/online (23:15–23:20 UTC)
geometry-compare.mjs --compare --a …/geometry-087278e9/edge/report.json --b …/geometry-087278e9/online/report.json --out …/geometry-087278e9/compare.json
```

`07-recent-prints` (`#lastPrintModal.show` never appears within the wait) is skipped identically on both runtimes in every run since Phase 3 —
not a parity fact. The new Online reference **replaces** `…\evidence\phase5\online-baseline-merged\` (768ac71) as the authority.

### 5.1 NEW Online reference (087278e9) vs the previous one (768ac71, `online-baseline-merged`) — `diff-online-087278e9-vs-merged-{1366,1024}`

| state | 1366×768 | 1024×768 | what changed | classification |
|---|---|---|---|---|
| 01-main | 0.022 % | 0.030 % | the `Stock NN` digits on four tiles (Lab Burger, Chicken Karahi, Cola, Cold Drink — sold by this session's runs) | **DATA** |
| 02-category / 10-cart-two-lines | 0.013 % / 0.022 % | 0.018 % / 0.030 % | the same stock digits | DATA |
| 03/04/05/08/09/12/13 | 0.000–0.017 % | 0.000–0.011 % | the stock digits visible behind the modal | DATA |
| 06-recent-orders | 0.633 % | 0.847 % | the list CONTENT — order numbers, customer names (Zed Alias / Zed Proof …), dates of this session's sales | **DATA** |
| 11-review-pay | 0.000 % | 0.000 % | — | identical |

**No state changed for a UI reason**: the canonical commits merged between 768ac71 and 087278e9 touch the return screen (tabs, restock,
`terminal_id`), the medical-counter blocks (off for a restaurant tenant) and reports — none of the 12 POS reference states renders differently.
The 768ac71 reference's intentional changes (SKU line, Send to owner, Reminder switch, website-orders slot) are unchanged.

## 6. Pixels — `diff-087278e9-1366` / `diff-087278e9-1024` (Edge 8096 vs Online 9704; red = changed, blue = status-slot mask)

| state | 1366×768 | 1024×768 | classification |
|---|---|---|---|
| 01-main | **0.000 %** | **0.000 %** | identical |
| 02-category | **0.000 %** | **0.000 %** | identical |
| 03-customer-modal | **0.000 %** | **0.000 %** | identical — both list the same 17-entry book |
| 04-context-modal | **0.000 %** | **0.000 %** | identical |
| 05-held-orders | **0.000 %** | **0.000 %** | identical |
| 06-recent-orders | 7.068 % | 7.858 % | **DATA** — list CONTENT: the three sales sold on this appliance show their LOCAL numbers `SO-1-1-01M5GA…` on 8096 vs the Cloud's official `SO-20261021225554-657…` on Online, and 8096 carries "Printing needs attention" + `Resume` for its own undelivered FakePrinter jobs (no print worker on 8096). Same columns, same rows, same controls; no box moved (§7). Successor of Phase 3 gap C, owner call (unchanged from 768ac71: 7.092 % / 7.897 %) |
| 08-quick-report | 0.279 % | 0.374 % | **STATE (capability)** — `#qr-email` "Send to owner" enabled on Online, disabled-with-hint on Edge (`quickReportEmail` off, E1); same box (identical to 768ac71) |
| 09-table-workspace | **0.000 %** | **0.000 %** | identical |
| 10-cart-two-lines | **0.000 %** | **0.000 %** | identical |
| 11-review-pay | 0.084 % | 0.112 % | **STATE** — non-cash tender options disabled on Edge (`nonCashTender`); identical to 768ac71 |
| 12-qty-entry | **0.000 %** | **0.000 %** | identical |
| 13-modifier-entry | **0.000 %** | 0.164 % | **STATE/TIMING** (1024 only) — the stacked layout's slot text runs past the 1024 mask box by a few px; 0 at 1366 (identical to 768ac71) |

`pixel-diff.php` exits 1 on both viewports solely because 06-recent-orders exceeds the 0.5 % threshold (DATA). **Unintentional drift: none.**
Every non-zero value equals the 768ac71 figure to the third decimal except 06 (list content differs by the session's sales), so the canonical
025728d8 merge moved nothing on the shared cashier view.

## 7. Geometry — `geometry-087278e9\compare.json` (tolerance 1 px)

Login OK on both (Edge → `/edge/local/pos`, Online → `/dashboard`), 0 page errors. `compare.json`: **`verdict EDGE_VS_ONLINE_GEOMETRY=NONE`,
`boxes_compared 998`, `max_geometry_deviation_px 0`, geometry 0, data 0, presence 0, `skipped 2`** (07-recent-prints on both sides).

| state | 1366×768 boxes / geometry / data / presence | 1024×768 boxes / geometry / data / presence |
|---|---|---|
| 01-main · 02-category · 10-cart-two-lines | 34 / 0 / 0 / 0 | 34 / 0 / 0 / 0 |
| 03-customer-modal · 05-held-orders · 06-recent-orders | 41 / 0 / 0 / 0 | 41 / 0 / 0 / 0 |
| 04-context-modal · 12-qty-entry | 44 / 0 / 0 / 0 | 44 / 0 / 0 / 0 |
| 07-recent-prints | SKIPPED on both | SKIPPED on both |
| 08-quick-report | 49 / 0 / 0 / 0 | 49 / 0 / 0 / 0 |
| 09-table-workspace | 42 / 0 / 0 / 0 | 42 / 0 / 0 / 0 |
| 11-review-pay | 50 / 0 / 0 / 0 | 50 / 0 / 0 / 0 |
| 13-modifier-entry | 45 / 0 / 0 / 0 | 45 / 0 / 0 / 0 |

Classification of every remaining difference between the two runtimes on 087278e9: GEOMETRY **none**; DATA 06-recent-orders (list content);
STATE 08 (`quickReportEmail`), 11 (`nonCashTender`), 13@1024 (slot text above the 1024 mask box); TIMING none observed (every state captured
once, settle 8000 ms). UNINTENTIONAL **none**.

## 8. Findings, observations

- **F-FB2-1 (finding — handover item, not a regression; owner/Edge item from the integration matrix):** REFUND-SHIFT-1 books an Online
  return on the counter's shift (clone `sales_returns` 4: `shift_id 5`, `terminal_id 1`, §1 W09), and the POS Return URL now carries
  `terminal_id` on both runtimes (§4b) — but the Edge twin (`EdgeLocalReturnService`) still resolves the till shift only for the cash-refund
  rule and writes the local `sales_returns` row with **`shift_id NULL` / `terminal_id NULL`** (8095 return 1, §1b; the shift/terminal travel
  only in the return envelope's actor block), and `EdgeInboundReturnIngestionService` calls `processReturn` without a `refundShiftId`. So an
  Edge-made return never lands on a Cloud shift's refund total. The matrix recorded this as "the till's refund-shift mapping onto a Cloud
  shift is NOT invented here (owner item)"; this is the browser-level evidence. Also `restocked` on Edge is always the column default 1 (the
  form's "Back on shelf" select is rendered but ignored by the twin — documented in the matrix).
- **F-FB2-2 (fact):** with `return_no_bill` OFF the Cloud answers **404** to a forced `?mode=no_bill` (fail closed), the Edge twin answers 200
  with the ordinary with-bill screen (it has no `mode`); with the feature ON the tabs and the `#nb-form` partial render on the Cloud only.
  No `submission_key` input exists in the shared create view on either runtime (the matrix expected one — nothing to tolerate on the Edge
  store route).
- **O1 re-check:** Team FB's `CUTOVER_ALREADY_DONE` stock error on the first STANDBY tick after a handback did **not** reproduce — on this tree
  the handback command itself ran the refresh (`freshness {config:"refreshed:25->27", stock:"refreshed:cutover"}`) and the worker's next tick
  was already `current`.
- **O2 (observation, unchanged):** `#web-orders-btn` absent on the clone (plan without Online Ordering), present + disabled + hint on Edge;
  pixel/geometry identical because the slot is hidden on both.
- **O3 (environment):** the harness needs `D:\laragon2\bin\nodejs\node-v20.20.1-win-x64` on PATH (node is not on the Git Bash PATH — the
  first Online launch exited 127 before Playwright started; nothing had run); `pixel-diff.php` must receive `C:/…` paths (with
  `MSYS_NO_PATHCONV=1` the `/c/…` form reaches php.exe unconverted and the tool exits 0 without writing anything — re-run by hand, §5).
  Another worktree's MySQL phpunit run (`D:\laragon2\www\pos-saas`, pid 87956) shared the server throughout; no run was slowed beyond the
  harness timeouts (W01 warm-up ≤ 0.7 s on every instance).
- **W15 F2 (Team W) re-check:** `POST /pos/customers/{id}/addresses` from the Online modal answered 200 for the clone cashier (address 8, §1).

## 9. Harness changes

None. `tools/edge-browser-proof/*.mjs` were not edited. Two read-only scratchpad helpers were used and are not part of the tree:
`fb2-return-screen-facts.mjs` (§4b; Playwright, loopback-only, submits nothing) and `fb2-clone-feature.php` (flips one `TenantFeatures` key on
the DISPOSABLE clone master through `Tenant::features`, guarded to `devonline` DB names; used `on return_no_bill` → `clear return_no_bill`, the
clone is back to `features NULL`). Team W's `replay-outbox.php` and `migrate-devclone-tenant.php` were reused unchanged.

## 10. Every command (chronological, Git Bash, node v20 + PHP 8.3 on PATH, secrets via env from the files)

```
php <scratchpad>\migrate-devclone-tenant.php            # pretend: master nothing, tenant 3 pending
php <scratchpad>\migrate-devclone-tenant.php --apply    # clone tenant 212 → 215
tools/edge-dev-instance/serve-clone.sh artisan edge:local:schema-upgrade --dry-run [--json]   # plan hash fbfdfe4f…
tools/edge-dev-instance/serve-clone.sh artisan edge:local:schema-upgrade                      # applied 3, skipped 24
bash tools/edge-dev-instance/seed.sh                                                           # 8095 re-seed (1:44)
node shared-pos-workflows.mjs --mode online --base-url http://edgehomelab.localhost:9704 --user lab.cashier@edgehomelab.test --out …\phase5\proof-online-087278e9
node shared-pos-workflows.mjs --mode online … --only W01,W02,W18,W16 --out …\phase5\proof-online-w18-087278e9
node shared-pos-workflows.mjs --mode edge --base-url http://127.0.0.1:8095 --user DEVCASH1 --manager-user DEVMGR1 --only W01,W02,W18,W03,W03M,…,W17 --out …\phase5\proof-edge-087278e9
tools/edge-dev-instance/serve-clone.sh worker start                                            # refresh 24→25
tools/edge-dev-instance/serve-clone.sh artisan edge:local:authority-status
node shared-pos-workflows.mjs --mode edge --base-url http://127.0.0.1:8096 --user LAB2C5D --manager-user LABMF2DE --only W01,W18,W15 --out …\phase5\w15-087278e9\edge-standby
tools/edge-dev-instance/serve-clone.sh worker stop
node shared-pos-workflows.mjs --mode online … --only W01,W15 --qa-phone "+92 300 4040771" --out …\phase5\w15-087278e9\online-cloud-only-customer
tools/edge-dev-instance/serve-clone.sh artisan edge:local:authority-takeover --confirm --by="Team FB2 087278e9"
node shared-pos-workflows.mjs --mode edge --base-url http://127.0.0.1:8096 --user LAB2C5D --manager-user LABMF2DE --only W01,W02,W18,W15,W16 --qa-phone "+92 300 5550102" --qa-existing-phone "0092 300 1110001" --qa-cloud-only-phone "+92 300 4040771" --out …\phase5\w15-087278e9\edge-writer
tools/edge-dev-instance/serve-clone.sh worker start                                            # drain + fence
tools/edge-dev-instance/serve-clone.sh artisan edge:local:sync-status ; edge:local:sync-send ; edge:local:sync-send
node <scratchpad>\fb2-return-screen-facts.mjs --mode edge --base-url http://127.0.0.1:8096 --user LAB2C5D --sale SO-1-1-01M5GANTBKYCYWN0HCC3B45F8Z --out …\phase5\return-screen-087278e9\edge-8096-local
APP_ENV=edgeclone php <scratchpad>\replay-outbox.php 8 10
tools/edge-dev-instance/serve-clone.sh artisan edge:local:authority-handback --assess ; edge:local:authority-handback --by="Team FB2 087278e9"
tools/edge-dev-instance/serve-clone.sh worker stop ; php C:\Users\Dell\BingooEdgeLab\scripts\devclone-cloud.php release-lease "Team FB2 087278e9 W15 complete: worker stopped"
node <scratchpad>\fb2-return-screen-facts.mjs --mode online … --sale SO-20261021222601-683 --out …\return-screen-087278e9\online-feature-off
php <scratchpad>\fb2-clone-feature.php on return_no_bill ; node fb2-return-screen-facts.mjs --mode online … --out …\online-feature-on ; php fb2-clone-feature.php clear return_no_bill
<D5 commands of §5>
```
SQL: read-only SELECTs on `pos_devonline_tenant_edge`, `pos_devonline_master_edge.tenants` (features), `bingoo_edge_devclone_local`,
`bingoo_edge_devtest_local`, `pos_lab_tenant_edge.sales_orders` (count only).

## 11. Final pair state and row counts

Clone lease: `holder cloud, edge_state standby, fenced_at NULL, released_at 2026-10-21 22:59:10` ("Team FB2 087278e9 W15 complete: worker
stopped"), branch `sales_operating_mode cloud / local_edge_status pending` (as in every previous run — the lease row carries the authority),
open shifts 0, `tenants.features NULL` — **the Online clone answers sales** (the §5 Online capture proves it). 8096: `authority_state standby`,
connection `online`, reason "handed back to Cloud by Team FB2 087278e9", `last_applied_config_revision 27` (= advertised), all 9 gates true,
`cloud_capabilities_seen {"customer_create":true}`, outbox `10 acknowledged / 0 unacked`, open shifts 0, serving (health 200), **worker NOT
running**. 8095: re-seeded on 087278e9, serving (health 200), manual Local Mode, 0 open shifts after W16. 9704: serving (login 200).

**Counts at the end:** `pos_lab_tenant_edge.sales_orders` **5** · clone customers 17 / addresses 10 / aliases 3 / sales_orders 71 / ingestions 15 /
sales_returns 4 · 8096 customers 18 / local sales_orders 71 (3 sold here this run) / aliases 3 · `bingoo_edge_devtest_local.sales_orders` **12**
(fresh seed + this run; 0 held) + 1 sales_return.

Evidence root: `C:\Users\Dell\BingooEdgeLab\evidence\phase5\` — `proof-online-087278e9\`, `proof-online-w18-087278e9\`, `proof-edge-087278e9\`,
`w15-087278e9\{edge-standby, online-cloud-only-customer, edge-writer}\`, `return-screen-087278e9\{edge-8096-local, online-feature-off,
online-feature-on}\`, `online-baseline-087278e9\`, `edge-samedata-087278e9\`, `diff-087278e9-1366\`, `diff-087278e9-1024\`,
`diff-online-087278e9-vs-merged-{1366,1024}\`, `geometry-087278e9\{edge,online,compare.json}` (each with `report.json` + PNGs).
