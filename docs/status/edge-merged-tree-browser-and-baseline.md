# Merged tree — browser gates on BOTH runtimes + the new Online pixel/geometry baseline (Team FB, 20 Oct 2026)

Worktree `D:\laragon2\www\pos-saas-edge`, branch `feat/edge-config-refresh-v1`, HEAD `768ac71c` (canonical `ccc67fa1` fully merged +
D1/D2/D3/D4/D6/D7). Team FB edited ONLY `tools/edge-browser-proof/shared-pos-workflows.mjs` (harness: opt-in W18 reminder-pause workflow,
merged-control facts in W01 — §9) and wrote this document. Nothing under `app/ resources/ routes/ tests/` was touched; nothing was
committed. The LAB appliance (`:8443`), the LAB Cloud (`:9701`), the LAB databases, the dev Clouds `:9702/:9703` and production were never
touched — `pos_lab_tenant_edge.sales_orders` = **5** before, during and after every step. Secrets were read from
`C:\Users\Dell\BingooEdgeLab\secrets\*.pass` into the environment and never printed.

Instances: ONLINE clone `http://edgehomelab.localhost:9704` (`pos_devonline_master_edge` → `pos_devonline_tenant_edge`, migrated to the merged
schema, 212 migrations); PAIRED dev Edge `http://127.0.0.1:8096` (`bingoo_edge_devclone_local`, device `c03a54be-…`, epoch 2, schema-upgraded
through the D7 manifest); DEV Edge `http://127.0.0.1:8095` (`bingoo_edge_devtest_local`, re-seeded on the merged schema, manual Local Mode).

```
ONLINE_BROWSER                   = 17/17 PASS (+ W03M PASS)   clone :9704, merged Online POS, 0 fenced 409s, 4 expected 4xx (§1)
EDGE_BROWSER                     = 17/17 PASS (+ W03M PASS; W15 = EXPECTED-DIFFERENCE on the never-paired 8095: nothing advertised → disabled + hint + 422 CLOUD_CAPABILITY_NOT_ADVERTISED)   dev Edge :8095 fresh merged-schema seed, 0 unexpected 4xx (§1b); the PAIRED appliance 8096 additionally proved W01/W02/W18/W15/W16 as the branch WRITER (§3) and W01/W15/W18 in STANDBY (§2)
REMINDER_PAUSE (D2)              = PASS on BOTH runtimes through the SAME control in the browser — Online W18: click → Swal → POST /api/pos/reminder-pause 200 {reminder.paused:true} → "Reminder OFF" → resume 200 {paused:false} → "Reminder ON"; Edge 8096 in LOCAL MODE: identical sequence against POST /edge/local/pos/reminder-pause (D2 twin) on the LOCAL open shift; Edge STANDBY: 422 {code:STANDBY} negative, control rendered ENABLED by the server (§4)
W15 CUSTOMER_CREATE_NEW          = PASS (till: "+92 300 5550101" → local 1000000003, phone_normalized 03005550101, customer_uuid 01M5C6C3P3NHT1RAX5YEW423YD + address 01M5C6CBCS…; Cloud: exactly ONE customer (id 14) under the SAME uuid, address once by address_uuid, sale 53 → customer 14)
W15 CUSTOMER_PHONE_MATCH         = PASS (till: "0092 300 1110001" reused Lab Customer One, reused:true, v1 envelope kind customer; Cloud: the Cloud-only phone "+92 300 4040770" (customer 13, created Online AFTER the appliance's last refresh) matched by phone_normalized — still 1 customer with that key, name kept)
W15 CUSTOMER_ALIAS               = PASS (customer_uuid_aliases: 01M5C6FRHSW74JPQZAS7RCJHGC → customer 13 / 01M5C5ZSMW76KN3QJFTMJ0EQSN, source phone_match, device c03a54be; sale 55 → customer 13)
W15 CUSTOMER_REPLAY_IDEMPOTENT   = PASS (both v3 envelopes re-POSTed byte-identically → 200 already_applied with the FIRST application's ingestion_uuid / official_sale_no; clone counts unchanged 14/7/2/55/12)
W15 CUSTOMER_REFRESH_RECONCILIATION = PASS (standby refresh 21→23 after the handback: local 1000000004 merged into Cloud 13, sale 55 repointed, 1000000003 keeps its local id under the Cloud uuid (edge_origin cloud), 15 customers, 0 duplicate phone keys, 0 deletions)
W15 REFRESH (config)             = PASS (worker start on the migrated clone: revision 20→21 applied — category_printer_mappings inserted 1, users updated 2, customers inserted 1; then 21→23 after the handback)
ONLINE_PIXEL_BASELINE_RECAPTURED = YES — C:\Users\Dell\BingooEdgeLab\evidence\phase5\online-baseline-merged\{1366x768,1024x768}\ (12 states each, 07-recent-prints skipped on both runtimes as in every previous run) = the new authority
EDGE_VS_ONLINE_PIXELS            = 1366: 9/12 states 0.000 % identical, 08 0.279 % / 11 0.084 % STATE (capability + slot), 06-recent-orders 7.09 % DATA; 1024: 8/12 identical, 08 0.374 % / 11 0.112 % / 13 0.164 % STATE, 06 7.90 % DATA — zero UNINTENTIONAL drift (§6)
EDGE_VS_ONLINE_GEOMETRY          = NONE — 998 boxes compared (12 states × 2 viewports), 0 geometry / 0 DATA / 0 presence differences, max deviation 0 px (the Phase 3 run still had 27 DATA-bucket differences — gone) (§7)
LAB sales_orders                 = 5 (unchanged)
```

## 1. ONLINE W01–W17 on the merged clone (`…\evidence\phase5\proof-online\`)

`shared-pos-workflows.mjs --mode online --base-url http://edgehomelab.localhost:9704 --user lab.cashier@edgehomelab.test` (full suite; no
`--clear-all-open-work` needed — W16 leftovers `{held:[], sessions:[]}`).

| W | verdict | what the merged Online POS did |
|---|---|---|
| W01 | PASS | slot `ONLINE · CLOUD`, terminal `Lab Counter 1`, 15 tiles; `POS_RUNTIME.capabilities` now carries `reminderPause:true, websiteOrders:true, partnerShare:true` (D2/D4/D6 keys present on the Cloud) |
| W02 | PASS | separate shift-open page → POST 302 → `Shift open` |
| W03 / W03M | PASS / PASS | `POST /pos` 200, receipt + KOT |
| W04 | PASS | customer search → sale |
| W05 | PASS | hold → list → recall → settle |
| W06 | PASS | open table → KOT round 1 + 2 → bill preview → bill requested → move → merge → settle after merge |
| W07 | PASS | void KOT-sent line (PIN approval) → re-hold |
| W08 | PASS | 10 % + fixed discount with approval; hold-with-unconsumed-discount 422 (shared rule, expected) |
| W09 | PASS | return via the POS iframe + returns list |
| W10 | PASS | reprint receipt |
| W11 | PASS | print panel; retry probe on a queued job 422 `Only failed or cancelled jobs can be retried.` (expected) |
| W12 | PASS | view / network 200 / **Send to owner** (`#qr-email`, the renamed e-mail control) enabled → 422 `No owner email is configured` (dataset, expected) |
| W13 | PASS | reserve → details → unreserve |
| W14 | PASS | split bill |
| W15 | PASS | quick-store 200 → customer 12 `01M5C53JNYG5F6S3ZCY58QY4H4`, phone_normalized `03003115939`, address `01M5C53SDVKRAWPXKM6SPKTV2A`, sale `SO-20261020075904-857` |
| W16 | PASS | this run's open work settled through the UI, close 302, `No open shift` |
| W17 | PASS | logout → login |

HTTP ≥ 400 in the whole run (all expected): `GET /dashboard 403` (cashier landing, as always), W08 `POST /held-sales 422` (shared rule), W11 retry
422, W12 e-mail 422. Console: 606 × asset `404 Not Found` (product images, as in every run), 1 × 403, 3 × 422 — no page error.

Merged controls as rendered (facts from the `W01,W02,W18,W16` re-run, `…\proof-online-w18\report.json → facts.merged_controls`):
`#pos-reminder-toggle` present, no `disabled` attribute in the markup, `Reminder ON`, wrap visible (after the §4 precondition);
`#web-orders-btn` **absent** on this clone (the plan has no Online Ordering module — the bell slot renders only `@if OnlineOrdering::enabledForTenant()`);
`#qr-email` "Send to owner" enabled; **15/15 tiles carry the PRODUCT-TILE-SKU-1 line** (`LABFAMILY`, `LABTEA`, `LAB-36A73F`, …); no medical-counter chrome.

## 1b. EDGE W01–W17 on the dev Branch Server 8095 (`…\evidence\phase5\proof-edge\`)

Run LAST, after the coordinator's MySQL gate had ended (no `phpunit` process left; `canonical-suites-768ac71.log` ended with `Tests: 406…`),
`bash tools/edge-dev-instance/seed.sh` re-seeded `bingoo_edge_devtest_local` on the merged schema (OK, 1 test / 5 assertions, 2:34; DEVCASH1
holds `tenant.pos.pause-reminders`, 0 sales, 0 shifts), then
`shared-pos-workflows.mjs --mode edge --base-url http://127.0.0.1:8095 --user DEVCASH1 --manager-user DEVMGR1 --only W01,W02,W18,W03,…,W17`
(the full list + the opt-in W18; 16 min).

| W | verdict | Edge (manual Local Mode, slot `LOCAL MODE · MANUAL SWITCH`) |
|---|---|---|
| W01 | PASS | 16 tiles, **16/16 with the SKU line** (`FAMILY`, `TEATIME`, `SKU-BEEFSEEK-375`); `#web-orders-btn` present + disabled + D4 hint; `#qr-email` "Send to owner" disabled + E1 hint; `#pos-reminder-toggle` present, no `disabled` attr (wrap hidden — no Reminder rule in the seed); no medical chrome; capabilities `reminderPause true`, `customerCreate false`, `websiteOrders false` |
| W02 | PASS | separate page → POST 302 → `Shift open` |
| W18 | PASS (DATA-PRECONDITION) | switch hidden by the shared rule (`statusFor available=false`: the dev seed ships KOT rules only); direct `POST /edge/local/pos/reminder-pause {terminal_id, paused:true}` → **200 `{reminder:{available:false, paused:false, paused_by:null, paused_at:null, can_toggle:true}}`** (Online's shape; `paused` is not reportable without a rule — by the service's own contract), resume → 200 same shape; no 403 (D3 permission held) |
| W03 / W03M | PASS / PASS | `POST /edge/local/pos/sales` 201, receipt + KOT to the FakePrinter |
| W04 | PASS | book customer (seeded `customer_uuid`) → sale |
| W05 | PASS | hold → list → recall → settle |
| W06 | PASS | open → KOT round 1 + 2 → preview → bill requested → move → merge → settle after merge |
| W07 | PASS | void KOT-sent line with DEVMGR1 code + credential → re-hold (`void_print_jobs`) |
| W08 | PASS | discounts with approval; hold-with-discount 422 (shared text, identical to Online) |
| W09 | PASS | return via the iframe (cash) + returns list |
| W10 | PASS | reprint |
| W11 | PASS | retry probe on a queued job → 422 **`Only failed or cancelled jobs can be retried.`** — the Edge twin now answers the SAME text as Online (Phase 3 recorded a different Edge wording) |
| W12 | PASS | view / network 200 / `Send to owner` disabled with the E1 hint |
| W13 | PASS | reserve → details → unreserve |
| W14 | PASS | split bill |
| W15 | EXPECTED-DIFFERENCE | `customerCreate false` (never paired, nothing advertised): `#qa-save` disabled with the hint; direct POST → 422 `{ok:false, capabilityOff:true, code:CLOUD_CAPABILITY_NOT_ADVERTISED}` (fail closed) |
| W16 | PASS | this run's open work cleared, close 302, `No open shift` |
| W17 | PASS | logout → login |

HTTP ≥ 400 (all expected): W08 422 (shared rule), W11 422 (queued-job retry), W15 422 CLOUD_CAPABILITY_NOT_ADVERTISED. Console: 643 × asset 404,
3 × 422; no page error. Disabled-with-hint census: `#branch_id`, `#web-orders-btn`, `#qr-email`, `#qa-save`, `#new-addr-save` (+ `#bill-preview-btn`
tooltip). End state: `bingoo_edge_devtest_local.sales_orders` 12 (0 held), 1 sales_return, 0 open shifts.

## 2. PAIR — refresh of the migrated clone into the appliance (8096, STANDBY)

| step | result |
|---|---|
| `serve-clone.sh worker start` (08:07 UTC) | tick 1 `config: "refreshed:20->21"`, stock `refreshed:cutover`, returnable `refreshed:48-sales`; tick 2 `current` |
| `laravel.log` `[edge-config-refresh] applied {revision 21}` | `branch updated 1`, `products 13`, `product_barcodes 5`, `printers 1`, **`category_printer_mappings {updated 3, inserted 1}`** (the §4 Reminder rule), **`users {updated 2}`** (the §4 permission), `customers {updated 11, inserted 1, kept_local 1}` (Online W15's customer 12), `customer_addresses {inserted 1}`, `terminal_printer_settings 1`, `void_reasons 3`. The refresh has NO `product_pack_units` / medical section (not an edge-config-v1 section — consistent with the MEDICAL-COUNTER handover: medicalCounter=false on every appliance); `kot_heading_stars` / report channels / website fields ride the `branch` row (`updated 1`) |
| `edge:local:authority-status` | `standby`, connection `online`, every gate true except `AUTHORITY_TAKEOVER_SAFE` (false while the Cloud lease is alive — correct), `STANDBY_FRESH_ENOUGH true`, applied 21 = advertised 21 |
| `edge_local_meta` | `last_applied_config_revision 21`, `cloud_capabilities_seen {"customer_create":true}` |
| 8096 DB after | reminder mapping id 4 present, `LAB2C5D` holds `tenant.pos.pause-reminders`, 13 customers, 0 open shifts |

Render check on 8096 in STANDBY (`--mode edge --only W01,W18,W15`, `…\evidence\phase5\w15\edge-standby\`): slot **`STANDBY · CLOUD AUTHORITY`**;
capabilities `reminderPause:true` (route `/edge/local/pos/reminder-pause`), `customerCreate:true` (advertised), `websiteOrders:false`,
`partnerShare:false`; routes null `websiteOrders/websiteOrderHold/websiteOrderVerify`, `quickReportEmail`, …; disabled-with-hint census:
`#web-orders-btn` "Website orders arrive on the Online POS — a website order needs the Cloud, so this Branch Server does not take them.",
`#qr-email` (e-mail hint), `#branch_id` (bound-branch hint), plus the two ordinary tooltips. `#pos-reminder-toggle` **rendered ENABLED by the
server** (no `disabled` attribute, no title) and disabled only by the view's own shift-status JS because no shift is open (same JS rule on Online).
W15 → 422 `{ok:false, code:"STANDBY", …}` with the control enabled (EXPECTED-DIFFERENCE); W18 → the same STANDBY 422 from the D2 twin
(EXPECTED-DIFFERENCE). Both STANDBY negatives are the lease gate answering BEFORE any write — nothing created.

## 3. W15 end to end on the pair (exact `edge-phase4-w15-proof.md` sequence)

| # | when (UTC) | command / action | result |
|---|---|---|---|
| 1 | 08:13:05 | `serve-clone.sh worker stop` | graceful; lease `holder cloud, standby, last_heartbeat 08:12:56, expires_at 08:14:56` |
| 2 | 08:13–08:14 | Online `--only W01,W15` (`…\w15\online-cloud-only-customer\`) — a customer only the Cloud knows, created AFTER the appliance's last refresh | quick-store 200 → **customer 13 "Zed Proof 34397", phone `+92 300 4040770` → `03004040770`, uuid `01M5C5ZSMW76KN3QJFTMJ0EQSN`** (the run's later cash sale 422 "No open shift" is outside this proof, as in Team W's run) |
| 3 | 08:15:28 | lapse: `UTC_TIMESTAMP() > expires_at + 30 s` | lease still `holder cloud / standby`, `fenced_at NULL`, `released_at NULL` |
| 4 | 08:15:30 | `edge:local:authority-takeover --confirm --by="Team FB merged-tree W15"` | **`LOCAL MODE ACTIVATED.`** — `local_active`, connection `reconciling`, all 9 gates true incl. `AUTHORITY_TAKEOVER_SAFE`, `STANDBY_FRESH_ENOUGH` (`--accept-stale` never used) |
| 5 | 08:16–08:26 | Edge WRITER run `--mode edge --base-url http://127.0.0.1:8096 --user LAB2C5D --manager-user LABMF2DE --only W01,W02,W18,W15,W16 --qa-phone "+92 300 5550101" --qa-existing-phone "0092 300 1110001" --qa-cloud-only-phone "+92 300 4040770"` (`…\w15\edge-writer\`) | **W01 PASS, W02 PASS, W18 PASS, W15 PASS, W16 PASS — 0 HTTP errors**; slot `SYNCHRONIZING · LOCAL MODE` (the worker is stopped, so the connection state stays `reconciling`) |
| 6 | 08:27:31 | `serve-clone.sh worker start` (while LOCAL_ACTIVE) | tick 1 `ack reconciling {local_sync, drained 3, acknowledged 3, reconciled, clean, in_sync 7}`; clone lease → `holder edge, local_active, fenced_at 08:27:33` |
| 7 | 08:28 | `edge:local:sync-status` / `sync-send` ×2 | `Outbox: 0 pending, 0 leased, 7 acknowledged, 0 failed`; both sends `0 acknowledged, 0 retryable, 0 terminal, 0 rejected` (acknowledged rows are never resent) |
| 8 | 08:28 | replay (Team W's `replay-outbox.php 5 7`, byte-identical bytes through the product transport, device headers from config) | row 5 → 200 `already_applied`, `ingestion_uuid 01M5C6RM99BVVVGRJQAC30N0GP`, `SO-20261020082736-224`; row 7 → 200 `already_applied`, `01M5C6RQAYBQ6N9FKBYEBAXGYZ`, `SO-20261020082739-979` — `ack_matches_row true` on both |
| 9 | 08:29:21 | `authority-handback --assess` → `authority-handback --by="Team FB merged-tree W15"` | assess `ready` (0 pending/failed/divergent, `reconcile_clean_at 08:28:49`, `cloud_holder_seen edge`); **"Authority handed back to the Cloud — the appliance is a warm standby again."**; 8096 `standby / online`, reason "handed back to Cloud by Team FB merged-tree W15" |
| 10 | 08:29–08:30 | worker (STANDBY) refresh adoption | tick 5 `config: "refreshed:21->23"` (observation O1 below on the stock field), tick 6 `current` |
| 11 | 08:30:32 | `serve-clone.sh worker stop` → `devclone-cloud.php release-lease "Team FB merged-tree W15 complete: worker stopped"` | `released: true, fenced: false`; lease `holder cloud, standby, released_at 08:30:49` |

### 3.1 The WRITER run — W15 facts (all HTTP 200 / 201)

| quick-add | typed | quick-store | attached | sale |
|---|---|---|---|---|
| new | `+92 300 5550101` / "Zed Proof 37629" | 200 `reused:false` → **local id 1000000003**, phone `923005550101`, **`phone_normalized 03005550101`**, **`customer_uuid 01M5C6C3P3NHT1RAX5YEW423YD`**; lookup lists it at once; address book (delivery) → `POST …/customers/1000000003/addresses` 200 `{ok, address{id 1000000002, address_uuid 01M5C6CBCS4BJ6NSM05JHDCYRY, "W15 Street 6999, Proof Town", is_default}}` | chip + hidden `customer_id` | **53** `SO-1-1-01M5C6CQCD91CBQ6BDP9CKNZZK` 201 |
| existing phone | `0092 300 1110001` / "Zed Twin 67597" | 200 **`reused:true`** → id 1 "Lab Customer One" (name kept), uuid `01M430P442XHM8SJW11G74JBXH`, both addresses with `address_uuid`; toast "Existing customer matched on this phone number" | chip Lab Customer One | **54** `SO-1-1-01M5C6EKPGNMJNZWK9CNQWPGX2` 201 |
| Cloud-only phone | `+92 300 4040770` / "Zed Alias 29397" | 200 `reused:false` — the till does not know this phone → **local 1000000004**, `phone_normalized 03004040770`, **uuid `01M5C6FRHSW74JPQZAS7RCJHGC`** | chip | **55** `SO-1-1-01M5C6FYSSCCHPE9NPSYJHY4H4` 201 |

W02 opened shift 3 on terminal 1 through the separate page; W16 closed it (open work `{held:[], sessions:[]}`, close 302, `No open shift`).

### 3.2 Envelopes (`edge_sync_outbox` on 8096, immutable bytes)

| row | sale | schema | customer.kind | content |
|---|---|---|---|---|
| 5 | 53 | **`edge-sale-envelope-v3`** | **`new_customer`** | `customer_uuid 01M5C6C3P3…`, `new_customer.phone_normalized 03005550101`, `addresses` ×1 (`01M5C6CBCS…`) |
| 6 | 54 | `edge-sale-envelope-v1` | `customer` | `customer_uuid 01M430P442…` (a synced customer travels in the unchanged v1 shape) |
| 7 | 55 | **`edge-sale-envelope-v3`** | **`new_customer`** | `customer_uuid 01M5C6FRHS…`, `phone_normalized 03004040770`, `addresses` ×0 |

Every `shift` block carries identity only: `shift_uuid, business_date, opened_at, terminal_id, opened_by_user_id` — **no `reminders_paused_*`
field** (D2 reconciliation rule; the W18 pause/resume in the same shift left nothing in the envelope). All three rows `acknowledged`, `attempts 1`.

### 3.3 Cloud ingestion on the clone (SQL, ids only)

`edge_inbound_sale_ingestions` 10–12: `01M5C6CQCD… v3 applied → SO-20261020082736-224`, `01M5C6EKPG… v1 applied → SO-…-311`,
`01M5C6FYSS… v3 applied → SO-…-979`; `failure_code NULL`; ingestions 9 → 12 (no row for a replay).

- **A — new phone:** `customers` **14** "Zed Proof 37629", `phone 923005550101`, `phone_normalized 03005550101`, **`customer_uuid 01M5C6C3P3NHT1RAX5YEW423YD`** (= the offline uuid, canonical); exactly 1 customer with that key; `customer_addresses` 7 → customer 14, **`address_uuid 01M5C6CBCS4BJ6NSM05JHDCYRY`** (= the till's), Home, default — once; `sales_orders` 53 `customer_id 14`.
- **B — existing normalized phone:** the Cloud-only customer **13** (`01M5C5ZSMW76KN3QJFTMJ0EQSN`, Online-created at 08:14) MATCHED: still 1 customer with `phone_normalized 03004040770` (name "Zed Proof 34397" kept, not renamed to "Zed Alias"); **`customer_uuid_aliases` row: `alias_uuid 01M5C6FRHSW74JPQZAS7RCJHGC → customer_id 13 / customer_uuid 01M5C5ZSMW…`, `source phone_match`, `source_device_uuid c03a54be-…`** (the book now holds 2 rows: Team W's `01M5868P…→10` and this one); `sales_orders` 55 `customer_id 13`. Sale 54 → customer 1 (kind `customer`, no alias needed).
- **C — replay:** after both byte-identical replays: customers **14**, addresses **7**, aliases **2**, sales_orders **55**, ingestions **12** — unchanged; the ACKs carried the first application's `ingestion_uuid` / `official_sale_no`.
- **D — refresh:** the standby worker applied **21 → 23** (`customers {updated 13, inserted 1, kept_local 2}`, `customer_addresses {updated 6, inserted 1, kept_local 1}`, `customer_uuid_aliases {updated 1, inserted 1, merged 1}`, `users {updated 2}`, `category_printer_mappings {updated 4}`); log `local customer merged into canonical {alias_uuid 01M5C6FRHS…, customer_uuid 01M5C5ZSMW…, from_id 1000000004, to_id 13}`. 8096 after: customer **13** inserted (`cloud`), **1000000004 merged away and sale 55 repointed to 13**, **1000000003 keeps its LOCAL id under the Cloud uuid `01M5C6C3P3…` (`edge_origin → cloud`; Cloud id 14 ≠ local id — harmless, every cross-system reference is by uuid)**, Team W's local-only 1000000000 untouched (`local`); **15 customers, 0 duplicate `phone_normalized` keys**, sales 53→1000000003, 54→1, 55→13.

## 4. REMINDER_PAUSE — D2 browser proof on both runtimes (new opt-in harness workflow W18, §9)

**Preconditions (data, applied on the DISPOSABLE clone only — the P3 precedent):** `ReminderPauseService::statusFor` hides the switch
(`available=false`) wherever no active `category_printer_mappings` row with `print_role=reminder` routes the terminal to an active
`supports_reminder` printer, and the pause endpoints deny without `tenant.pos.pause-reminders` (D3: existing tenants are NOT granted). The
clone (a LAB copy) had **no Reminder rule and neither user held the permission** — so, through the product models (never raw SQL), via the
scratchpad helper `fb-clone-precondition.php` (guards: DB name must contain `devonline`, must not contain `lab_`):
`grant-pause 1` (Spatie `givePermissionTo` on `lab.cashier`, guard `tenant`) and `reminder-rule` (`CategoryPrinterMapping` id 4: branch 1,
all terminals, all categories, printer 1 "LAB FakePrinter", `order_type all`, active → `terminalHasReminderRule(terminal 1) = true`). Both rows
reached 8096 through the ordinary config refresh (§2: `category_printer_mappings inserted 1`, `users updated 2`) — the pair stayed the SAME
dataset for §5/§6. The 8095 dev seed grants DEVCASH1 the permission (D3) but ships no Reminder rule (switch hidden there — probe path).

| runtime / state | verdict | exchange (browser) |
|---|---|---|
| **Online** (clone, shift 4 open; `…\proof-online-w18\`) | **PASS** | contract `capabilities.reminderPause true`, route `/api/pos/reminder-pause`; server render ENABLED (no `disabled` attr, no title); shift-status `reminder {available true, paused false, can_toggle true}`; click → Swal "Pause Reminder slips on this terminal?" → Pause → **`POST /api/pos/reminder-pause` 200 `{reminder:{available:true, paused:true, paused_by:"Lab Cashier", paused_at:"13:05", can_toggle:true}}`**, button `Reminder OFF` (`data-paused=1`), title "Reminder slips paused by Lab Cashier at 13:05 until this shift closes. KOT and receipts still print.", toast "Reminder paused until this shift closes"; shift-status `paused true`; click → **200 `{paused:false}`**, `Reminder ON`, toast "Reminder is ON"; shift-status `paused false`. Shots `W18-…-01-before/02-paused/03-resumed.png` |
| **Edge 8096 LOCAL MODE** (shift 3 open; `…\w15\edge-writer\`) | **PASS** | SAME control, SAME steps, SAME shapes against **`POST /edge/local/pos/reminder-pause`** (the D2 twin): 200 `{reminder:{available:true, paused:true, paused_by:"Lab Cashier", paused_at:"13:19", can_toggle:true}}` → `Reminder OFF`; 200 `{paused:false}` → `Reminder ON`; shift-status (Edge `shift.status`) reflects both; toasts identical. The closed shift 3 row ends with `reminders_paused_at NULL` (resumed before the close) |
| **Edge 8096 STANDBY** (`…\w15\edge-standby\`) | EXPECTED-DIFFERENCE | control rendered ENABLED by the server (D2), shift-status `reminder {available true, can_toggle true}`; the JS disables it (no open shift); direct POST → **422 `{ok:false, code:"STANDBY", message:"Local Mode is not active — the Cloud POS is the active writer for this branch (standby)."}`** — the lease gate answers before the shift lookup, nothing stamped |
| **Edge 8095** (dev seed, manual Local Mode, shift open; `…\proof-edge\`) | PASS (DATA-PRECONDITION) | permission held (D3 via the seed) but NO Reminder rule in the dev seed → switch hidden (`available=false`, the same shared rule); direct POST → 200 with Online's `reminder` block both ways (`paused` stays false because the service reports it only where a Reminder rule fires); no 403, no STANDBY (manual Local Mode has no lease gate) |

## 5. D5 — new Online baseline + the paired appliance (same dataset, both in STANDBY/Cloud, NO open shift on either side)

Captured after step 11 of §3 (worker stopped, lease released, clone `open_shifts 0`, 8096 `open_shifts 0`, both at config revision 23):

```
pos-reference-shots.mjs --mode cloud --base-url http://edgehomelab.localhost:9704 --email lab.cashier@edgehomelab.test --settle 8000 --out C:/Users/Dell/BingooEdgeLab/evidence/phase5/online-baseline-merged     → 24 shots, 07-recent-prints skipped ×2
pos-reference-shots.mjs --mode edge  --base-url http://127.0.0.1:8096 --path /edge/local/pos --user LAB2C5D --settle 8000 --out …/phase5/edge-samedata-merged                                                → 24 shots, 07-recent-prints skipped ×2
php pixel-diff.php …/online-baseline-merged/1366x768 …/edge-samedata-merged/1366x768 …/phase5/diff-merged-1366 --threshold=0.5 --mask=1090,10,275,42
php pixel-diff.php …/online-baseline-merged/1024x768 …/edge-samedata-merged/1024x768 …/phase5/diff-merged-1024 --threshold=0.5 --mask=745,10,275,42
geometry-compare.mjs --mode edge  --base-url http://127.0.0.1:8096 --path /edge/local/pos --user LAB2C5D --settle 6000 --out …/phase5/geometry-merged/edge
geometry-compare.mjs --mode cloud --base-url http://edgehomelab.localhost:9704 --user lab.cashier@edgehomelab.test --settle 6000 --out …/phase5/geometry-merged/online
geometry-compare.mjs --compare --a …/geometry-merged/edge/report.json --b …/geometry-merged/online/report.json --out …/geometry-merged/compare.json
```

`07-recent-prints` (`#lastPrintModal.show` never appears within the wait) is skipped identically on both runtimes in every run since Phase 3 —
not a parity fact. The new Online baseline **replaces** `…\evidence\phase3\pixel-samedata\online\` as the authority: the merged shared view is
visibly different from the Phase 3 authority (SKU line under every tile, `Send to owner`, the Reminder switch next to the shift badge, the
website-orders slot) — intentional canonical UI changes.

## 6. Pixels — `diff-merged-1366` / `diff-merged-1024` (red = changed, blue = status-slot mask)

| state | 1366×768 | 1024×768 | classification |
|---|---|---|---|
| 01-main | **0.000 %** | **0.000 %** | identical (the Reminder switch, SKU lines, hidden website-orders slot render pixel-identically; the slot text sits inside the mask on both widths) |
| 02-category | **0.000 %** | **0.000 %** | identical — Phase 3 gap A (combos without `category_id`) is gone on the merged tree |
| 03-customer-modal | **0.000 %** | **0.000 %** | identical — Phase 3 gap B (empty lookup) is gone: both list the book |
| 04-context-modal | **0.000 %** | **0.000 %** | identical |
| 05-held-orders | **0.000 %** | **0.000 %** | identical |
| 06-recent-orders | 7.092 % | 7.897 % | **DATA** — the list CONTENT: the three sales sold on this appliance show their LOCAL numbers `SO-1-1-01M5C6…` on 8096 vs the Cloud's official `SO-20261020082736-224…` on Online, and on 8096 they carry "Printing needs attention" + the `Resume` control (the appliance's own print jobs to the FakePrinter are undelivered — no print worker runs on 8096), while the Online rows show delivered jobs. Same columns, same row height per row, same controls set; no box moved (geometry §7). Successor of Phase 3 gap C (recent-orders mirror), owner call |
| 08-quick-report | 0.279 % | 0.374 % | **STATE (capability)** — `#qr-email` "Send to owner" is `btn-primary` enabled on Online and disabled-with-hint on Edge (`quickReportEmail` off, E1); same box |
| 09-table-workspace | **0.000 %** | **0.000 %** | identical |
| 10-cart-two-lines | **0.000 %** | **0.000 %** | identical |
| 11-review-pay | 0.084 % | 0.112 % | **STATE** — non-cash tender options disabled on Edge (E-capability `nonCashTender`), slot edge |
| 12-qty-entry | **0.000 %** | **0.000 %** | identical |
| 13-modifier-entry | **0.000 %** | 0.164 % | **STATE/TIMING** (1024 only) — the stacked layout's slot text runs past the 1024 mask box by a few px; 0 at 1366 |

`pixel-diff.php` exits 1 on both viewports solely because 06-recent-orders exceeds the 0.5 % threshold (DATA). **Unintentional drift: none.**
Compared with Phase 3 (§5 of `edge-phase3-samedata-comparison.md`: 01-main 0.413 %, 02 1.487 %, 03 16–19 %, 06 1.7 %) the merged tree is
pixel-identical in 9/12 states at 1366 — gaps A and B are closed by the canonical merge / Phase 4 data work.

## 7. Geometry — `geometry-merged\compare.json` (tolerance 1 px)

Login OK on both (Edge → `/edge/local/pos`, Online → `/dashboard`), `POS_RUNTIME.mode` edge / cloud, shared-view marker on both, 0 page errors.
`compare.json`: **`verdict EDGE_VS_ONLINE_GEOMETRY=NONE`, `boxes_compared 998`, `max_geometry_deviation_px 0`, `geometry 0`, `data 0`, `presence 0`,
`skipped 2`** (07-recent-prints on both sides, as always).

| state | 1366×768 boxes / geometry / data / presence | 1024×768 boxes / geometry / data / presence |
|---|---|---|
| 01-main | 34 / 0 / 0 / 0 | 34 / 0 / 0 / 0 |
| 02-category | 34 / 0 / 0 / 0 | 34 / 0 / 0 / 0 (Phase 3: 21 DATA — gone) |
| 03-customer-modal | 41 / 0 / 0 / 0 (Phase 3: 3 DATA — gone) | 41 / 0 / 0 / 0 |
| 04-context-modal | 44 / 0 / 0 / 0 | 44 / 0 / 0 / 0 |
| 05-held-orders | 41 / 0 / 0 / 0 | 41 / 0 / 0 / 0 |
| 06-recent-orders | 41 / 0 / 0 / 0 (the §6 pixel difference is list CONTENT only — every measured box identical) | 41 / 0 / 0 / 0 |
| 07-recent-prints | SKIPPED on both | SKIPPED on both |
| 08-quick-report | 49 / 0 / 0 / 0 | 49 / 0 / 0 / 0 |
| 09-table-workspace | 42 / 0 / 0 / 0 | 42 / 0 / 0 / 0 |
| 10-cart-two-lines | 34 / 0 / 0 / 0 | 34 / 0 / 0 / 0 |
| 11-review-pay | 50 / 0 / 0 / 0 | 50 / 0 / 0 / 0 |
| 12-qty-entry | 44 / 0 / 0 / 0 | 44 / 0 / 0 / 0 |
| 13-modifier-entry | 45 / 0 / 0 / 0 | 45 / 0 / 0 / 0 |

Classification of every remaining difference between the two runtimes on the merged tree: GEOMETRY **none**; DATA 06-recent-orders (list
content); STATE 08 (capability `quickReportEmail`), 11 (capability `nonCashTender`), 13@1024 (status-slot text above the 1024 mask box);
TIMING none observed (every state captured once, settle 8000 ms; no mid-refresh frame). Intentional canonical UI changes (SKU line, Send to
owner, Reminder switch, website-orders slot) are identical on both sides and are the new authority.

## 8. Findings, observations, preconditions

- **F-FB1 (finding, DATA/permission precondition of D2/D3, not a code defect):** a tenant upgraded to the merged tree has the Reminder switch
  only where a `print_role=reminder` mapping exists AND the cashier holds `tenant.pos.pause-reminders`. The LAB tenant copy (and therefore the
  LAB appliance after its update) has neither: the control is hidden on both runtimes and `lab.cashier` would get 403 "You are not allowed to
  pause Reminder slips." from both the Cloud and the twin. D3 says existing tenants are not granted — the LAB update checklist needs the grant
  (and a Reminder rule if the LAB is to show the switch). Request/response evidence: before the precondition `statusFor` → `{available:false,
  can_toggle:false}` (clone status helper: `can_pause_reminders false` for users 1 and 2, `reminder_rules []`).
- **O1 (observation):** on the first STANDBY tick after the handback the worker reported
  `stock: "error:CUTOVER_ALREADY_DONE: a different baseline is already accepted at the current revision; no cutover is pending."` alongside
  `config: "refreshed:21->23"`; the next tick was `stock: "current"` and `STOCK_AUTHORITY_READY` stayed true. Self-healing, but the error string
  on a routine handback deserves a look (`edgeclone-authority-worker.log` tick 5 at 08:29).
- **O2 (observation, not a difference):** the website-orders bell slot renders `hidden` on both runtimes until the counter is routed for website
  orders — on Edge it is present + disabled + hint (D4), on this clone (plan without Online Ordering) absent; pixel/geometry identical.
- **O3 (observation):** during the §3 writer run the slot read `SYNCHRONIZING · LOCAL MODE` the whole time because the worker was stopped
  (connection `reconciling` after the takeover) — the expected label while nothing is acknowledging; with the worker up it reads LOCAL MODE.
- **O4 (environment):** at ~13:50 local all three PHP dev servers (9704, 8096, 8095) were found dead (connection refused) — the first Online
  geometry pass aborted at login; Team FB restarted them (`serve-clone.sh start`, `serve.sh start`, `Start-DevCloud.ps1 -Port 9704 -MasterDb
  pos_devonline_master_edge`) and re-ran the Online geometry. No database was affected (counts unchanged).
- **W15 F2 (Team W) re-check:** `POST /pos/customers/{id}/addresses` from the Online modal answered **200** for the clone cashier this time
  (`…\proof-online\` W15 address `01M5C53SDVKRAWPXKM6SPKTV2A`) — the clone approver/permission rows the coordinator added cover it.

## 9. Harness changes (`tools/edge-browser-proof/shared-pos-workflows.mjs` only)

- **W18 `reminder pause on the open shift (D2)` — opt-in** (runs only when listed in `--only`, so the 17-workflow counts are unchanged). Verdict
  = contract (`capabilities.reminderPause` + `routes.reminderPause`; off → must be disabled-with-hint → EXPECTED-DIFFERENCE) + the SERVER render
  of `#pos-reminder-toggle` read from the page HTML as served + the shift-status `reminder` block shape + the UI round trip (click → Swal →
  POST 200 `paused:true` + `Reminder OFF` → click → 200 `paused:false` + `Reminder ON`, each confirmed by shift-status). When the switch is
  hidden (`available=false`, no Reminder rule) or disabled, the endpoint is probed from the page session and classified DATA-PRECONDITION /
  PERMISSION/DATA; 422 STANDBY on Edge = expected negative. Nothing touches the database.
- **W01 facts `merged_controls`**: `#pos-reminder-toggle` (markup `disabled` attr, title, text, wrap visibility), `#web-orders-btn` (+ modal),
  `#qr-email` "Send to owner", tiles with the SKU line (+ 3 samples), medical-counter chrome — facts only, no verdict change.

## 10. Every command (chronological, Git Bash, `MSYS_NO_PATHCONV=1`, secrets via env from the files)

```
node shared-pos-workflows.mjs --mode online --base-url http://edgehomelab.localhost:9704 --user lab.cashier@edgehomelab.test --out …\phase5\proof-online
php <scratchpad>\fb-clone-precondition.php status | grant-pause 1 | reminder-rule          # clone only (guarded)
node shared-pos-workflows.mjs --mode online … --only W01,W02,W18,W16 --out …\phase5\proof-online-w18
tools/edge-dev-instance/serve-clone.sh worker start                                          # refresh 20→21
tools/edge-dev-instance/serve-clone.sh artisan edge:local:authority-status
node shared-pos-workflows.mjs --mode edge --base-url http://127.0.0.1:8096 --user LAB2C5D --manager-user LABMF2DE --only W01,W18,W15 --out …\phase5\w15\edge-standby
tools/edge-dev-instance/serve-clone.sh worker stop
node shared-pos-workflows.mjs --mode online … --only W01,W15 --out …\phase5\w15\online-cloud-only-customer
tools/edge-dev-instance/serve-clone.sh artisan edge:local:authority-takeover --confirm --by="Team FB merged-tree W15"
node shared-pos-workflows.mjs --mode edge --base-url http://127.0.0.1:8096 --user LAB2C5D --manager-user LABMF2DE --only W01,W02,W18,W15,W16 --qa-phone "+92 300 5550101" --qa-existing-phone "0092 300 1110001" --qa-cloud-only-phone "+92 300 4040770" --out …\phase5\w15\edge-writer
tools/edge-dev-instance/serve-clone.sh worker start                                          # drain + fence
tools/edge-dev-instance/serve-clone.sh artisan edge:local:sync-status ; edge:local:sync-send ; edge:local:sync-send
APP_ENV=edgeclone php <scratchpad>\replay-outbox.php 5 7
tools/edge-dev-instance/serve-clone.sh artisan edge:local:authority-handback --assess ; edge:local:authority-handback --by="Team FB merged-tree W15"
tools/edge-dev-instance/serve-clone.sh worker stop ; php C:\Users\Dell\BingooEdgeLab\scripts\devclone-cloud.php release-lease "Team FB merged-tree W15 complete: worker stopped"
<D5 commands of §5>
bash tools/edge-dev-instance/seed.sh                                                          # after the coordinator's gate ended (no phpunit running)
node shared-pos-workflows.mjs --mode edge --base-url http://127.0.0.1:8095 --user DEVCASH1 --manager-user DEVMGR1 --only W01,W02,W18,W03,W03M,W04,…,W17 --out …\phase5\proof-edge
```
SQL: read-only SELECTs on `pos_devonline_tenant_edge`, `bingoo_edge_devclone_local`, `bingoo_edge_devtest_local`, `pos_lab_tenant_edge.sales_orders` (count only).

## 11. Final pair state and row counts

Clone lease: `holder cloud, edge_state standby, fenced_at NULL, released_at 2026-10-20 08:30:49` ("Team FB merged-tree W15 complete: worker
stopped"), branch `sales_operating_mode cloud / local_edge_status pending` (as in Team W's run — the lease row carried the authority), open
shifts 0 — **the Online clone answers sales** (the §5 Online capture and the §1/§4 runs prove it). 8096: `authority_state standby`, `connection
online`, `last_applied_config_revision 23`, `cloud_capabilities_seen {"customer_create":true}`, outbox `7 acknowledged / 0 unacked`, open shifts 0,
serving (health 200), **worker NOT running**. 8095: re-seeded, serving (health 200), manual Local Mode, 0 open shifts after W16, no print worker left running.

**Counts at the end:** `pos_lab_tenant_edge.sales_orders` **5** · clone customers 14 / addresses 7 / aliases 2 / sales_orders 55 / ingestions 12 ·
8096 customers 15 / local sales_orders 55 (3 sold here this run) / aliases 2 · `bingoo_edge_devtest_local.sales_orders` **12** (fresh seed + this run; 0 held) + 1 sales_return.

Evidence root: `C:\Users\Dell\BingooEdgeLab\evidence\phase5\` — `proof-online\`, `proof-online-w18\`, `proof-edge\`, `w15\{edge-standby,
online-cloud-only-customer, edge-writer}\`, `online-baseline-merged\`, `edge-samedata-merged\`, `diff-merged-1366\`, `diff-merged-1024\`,
`geometry-merged\{edge,online,compare.json}` (each with `report.json` + PNGs).
