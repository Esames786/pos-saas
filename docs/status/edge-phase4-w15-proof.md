# Edge Phase 4 — W15 browser proof + Cloud ingestion + refresh (Team W, 18 Oct 2026)

Worktree `D:\laragon2\www\pos-saas-edge`, branch `feat/edge-config-refresh-v1`. First pass on HEAD `f6ed8ba` (found blocker F1, §3);
completed on HEAD `7999a50` (F1 fix) + `50d5bff` (Gap C). Disposable pair only: dev Cloud CLONE `http://edgehomelab.localhost:9704`
(`pos_devonline_master_edge` → `pos_devonline_tenant_edge`, central API `http://localhost:9704/api/edge/…`) and the paired dev Edge
`http://127.0.0.1:8096` (`bingoo_edge_devclone_local`, device `c03a54be-…`, epoch 2); the never-paired dev instance `:8095` for the old-Cloud
case. Nothing under `app/ resources/ routes/ tests/` edited by Team W; nothing committed. LAB appliance, LAB Cloud `:9701`, LAB databases,
dev Clouds `:9702/:9703`, production untouched — `pos_lab_tenant_edge.sales_orders` = **5** before, during and after. No raw SQL write was run
by Team W on any database; every state change went through product commands, the clone helper, or the browser.

```
W15_EDGE_BROWSER                 = PASS  (real browser on 8096 as the WRITER: quick-add with "+92 300 7654321" → customer_uuid + phone_normalized "03007654321", attached, address through the same modal with address_uuid, CASH sale 39 with that customer; plus the STANDBY 422 negative and the nothing-advertised 8095 negative)
CUSTOMER_CREATE_NEW              = PASS  (Cloud: exactly one customer, id 11, canonical uuid = the offline uuid 01M586J4CKVSREJVA4SZV5RARP; address once by address_uuid 01M586J9X7…; sale SO-20261018190923-912 linked to 11)
CUSTOMER_PHONE_MATCH             = PASS  (till: "0092 300 1110001" reused Lab Customer One locally, reused:true, envelope kind customer; Cloud: "+92 300 2349813" (a customer only the Cloud knew) matched customer 10 by phone_normalized, no duplicate — 1 customer with that key)
CUSTOMER_ALIAS                   = PASS  (customer_uuid_aliases: 01M5868P2NYD5338D0FYFR3ZC8 → customer 10 / 01M5860CXSPB3PA5WQXWRTDAYN, source phone_match, device c03a54be; sale 38 linked to 10)
CUSTOMER_REPLAY_IDEMPOTENT       = PASS  (both v3 envelopes re-POSTed byte-identically → 200 status already_applied, same ingestion_uuid/official_sale_no; clone counts unchanged 11/1/4/39/9)
CUSTOMER_REFRESH_RECONCILIATION  = PASS  (8096 refresh 18→20: local ids kept under the Cloud uuids, alias merge 1000000001→10 with sale 38 repointed, local-only customer untouched, 12/12 active, 0 duplicate keys; F1 fix verified: the skewed appliance healed on its first refresh with zero deletions)
OLD_CLOUD_COMPATIBILITY          = PASS  (8095: control disabled with the hint, routes null, direct POST 422 CLOUD_CAPABILITY_NOT_ADVERTISED; the two owner-cited MySQL tests green)
```

## 1. Pair preparation (both sides were pre-Phase-4) — every command

| # | where | command | result |
|---|---|---|---|
| 1 | clone Cloud | `php <scratchpad>\migrate-devclone-tenant.php` (pretend) then `--apply` — copy of the LAB `Migrate-LabTenant.php` with the devclone env block (refuses any DB whose name lacks `devonline`), `migrate --database=tenant --path=database/migrations/tenant` | `2026_10_06_000001_edge_phase4_offline_customer_identity` DONE; migrations 172 → 173 |
| 2 | 8096 | `serve-clone.sh artisan edge:local:schema-upgrade --dry-run` / apply | tenant `…offline_customer_identity` + edge `…offline_customer_origin` applied |
| 3 | 8095 | `APP_ENV=edgedev php artisan edge:local:schema-upgrade` | same two migrations |
| 4 | clone Cloud (second pass, coordinator-approved) | same helper `--apply-master` | master `2026_09_27_000001_widen_edge_devices_version_columns` DONE (27 → 28 rows; `edge_devices.build_reported_hash` present) |

Baseline before the proof: LAB sales_orders 5 · clone customers 9 / addresses 3 / aliases 0 / sales_orders 35 / ingestions 5 · 8096 customers 9
(all `cloud`-origin) / addresses 3 / aliases 0 / local sales 34 / outbox 0 · `cloud_capabilities_seen NULL` · LAB2C5D holds
`tenant.pos.customers.quick-store` on both sides · clone lease expired since 5 Oct, unreleased (`lease_blocks_cloud true`).

## 2. Step 1 — STANDBY: capability negotiated, control enabled, mutation refused (negative, PASS)

`serve-clone.sh worker start` → accepted beats at once; `edge_local_meta.cloud_capabilities_seen = {"customer_create":true}`.
Browser (`--mode edge --base-url http://127.0.0.1:8096 --user LAB2C5D --manager-user LABMF2DE --only W01,W15`, evidence
`C:\Users\Dell\BingooEdgeLab\evidence\phase4\w15\edge-standby\`): `POS_RUNTIME.capabilities.customerCreate true`, `customerAddressCreate true`,
`routes.customerQuickStore=/edge/local/pos/customers/quick-store`, slot `STANDBY · CLOUD AUTHORITY`, `#qa-save` ENABLED; typed
`+92 300 4170163` → `POST …/quick-store` → **422 `{ok:false, code:"STANDBY", message:"Local Mode is not active — the Cloud POS is the active writer
for this branch (standby)."}`**, same text inline; nothing created. Screenshot `W15-customer-quick-add-03-quick-add-standby-refused.png`.

## 3. First pass (HEAD f6ed8ba): takeover REFUSED — finding F1 (kept as history; fixed in 7999a50)

With the worker up: every tick ended `config: "error:REFRESH_CONFLICT: address id 1 is held locally by a different address identity."`;
`authority-status`: `STANDBY_FRESH_ENOUGH false — config revision 17 applied, Cloud advertised 18`, `can_take_over false`.
`edge:local:authority-takeover --confirm --by="Team W dev clone W15 proof"` → **`Local Mode cannot start — failing gates: AUTHORITY_TAKEOVER_SAFE,
STANDBY_FRESH_ENOUGH [config revision 17 applied, Cloud advertised 18]`** (`--accept-stale` never used).

**F1.** The Phase 4 tenant migration backfilled `customer_addresses.address_uuid` with a fresh ULID per row wherever it ran — on the Cloud tenant
DB AND (via `schema-upgrade`) on the appliance's bootstrapped copy of the same rows — so the same three addresses carried clone uuids
`01M55X91A…` and appliance uuids `01M55X92E…`; `EdgeLocalConfigRefreshApplier::upsertCustomerAddresses` refused the refresh on `address id 1`,
the appliance could never become fresh, and a supervised takeover was impossible. Any appliance bootstrapped before Phase 4 (the LAB) would
have hit it at the 0.8 update. Reported; fixed by the coordinator in `7999a50` (the appliance never mints for pre-existing rows; the refresh
adopts the Cloud uuid for Cloud-origin rows; `REFRESH_CONFLICT` only for a genuine local-origin clash).

**F1 fix verified (second pass, HEAD 7999a50):** `serve-clone.sh worker start` → tick 1 `config: "refreshed:17->18"`, tick 2 `current`;
`laravel.log`: three `[edge-config-refresh] cloud-owned address adopted the Cloud identity {id:1|2|3, from: 01M55X92E…, to: 01M55X91A…}`;
`authority-status`: `STANDBY_FRESH_ENOUGH true`, applied 18 = advertised 18; 8096 rows: all 3 addresses now carry the clone's `address_uuid`
(same id/customer/text, `edge_origin cloud`), 9/9 customers active, 3 addresses, nothing deleted or deactivated, 9/9 customer uuids equal.

## 4. Step 2 — supervised takeover (second pass)

`serve-clone.sh worker stop` (18:56:35; lease `expires_at 18:58:19`). Online harness on the clone to create a customer only the Cloud knows
(`--mode online --base-url http://edgehomelab.localhost:9704 --user lab.cashier@edgehomelab.test --only W01,W15`, evidence
`…\w15\online-cloud-only-customer\`): Online quick-store 200 → customer **id 10, uuid `01M5860CXSPB3PA5WQXWRTDAYN`, phone `03002349813`**
(created after the appliance's last refresh, so the till cannot see it). (The Online run's later steps failed for reasons outside this proof:
address save 403 — finding F2 below; cash sale 422 "No open shift" because W02 was not in the Online run.)
Lease lapsed (`NOW() > expires_at + 30 s`), then `edge:local:authority-takeover --confirm --by="Team W dev clone W15 proof"` → **`LOCAL MODE
ACTIVATED.`** — `authority_state local_active`, reason "Cloud lease lapsed; local readiness verified; confirmed by Team W dev clone W15 proof";
gates `AUTHORITY_TAKEOVER_SAFE true`, `STANDBY_FRESH_ENOUGH true`. (The clone learns on the next heartbeat — §6.)

## 5. Step 3 — the browser on the WRITER (8096) — `…\evidence\phase4\w15\edge-writer\` and `edge-writer-2\`

Run 1 (`--only W01,W02,W15,W16 --qa-phone "+92 300 1234567" --qa-existing-phone "0092 300 1110001" --qa-cloud-only-phone "+92 300 2349813"`):
W01 PASS, W02 PASS (shift opened — the Edge sale locks the open shift), W16 PASS (open work settled, shift closed). W15 facts (all HTTP 200/201):

| quick-add | typed | response | attached | sale |
|---|---|---|---|---|
| new | `+92 300 1234567` / "Zed Proof 71394" | 200 `reused:false`, id **1000000000** (local id floor), `phone 923001234567`, **`phone_normalized 03001234567`**, **`customer_uuid 01M5867D0ZPA9KKF5DD76DKZ3F`** | chip + toast; lookup lists it at once; address book opened in delivery mode; `POST …/customers/1000000000/addresses` → 200 `{ok, address{id 1000000000, address_uuid 01M5867HWM8QRM72TQSTJVXM1S, "W15 Street 4913, Proof Town", is_default}}` | sale 36 `SO-1-1-01M5867P…` 201 — paid as a **walk-in** (harness defect, below), not the customer |
| existing phone | `0092 300 1110001` / "Zed Twin 84818" | 200 **`reused:true`**, id 1 "Lab Customer One" (name kept), uuid `01M430P442XHM8SJW11G74JBXH`, both addresses with their `address_uuid`; toast "Existing customer matched on this phone number" | chip Lab Customer One, hidden `customer_id 1` | sale 37 `SO-1-1-01M58688…` 201, customer_id 1 |
| Cloud-only phone | `+92 300 2349813` / "Zed Alias 02959" | 200 `reused:false` — the till does not know this phone → local **1000000001**, `phone_normalized 03002349813`, uuid **`01M5868P2NYD5338D0FYFR3ZC8`** | chip, hidden `customer_id 1000000001` | sale 38 `SO-1-1-01M5868T…` 201, customer_id 1000000001 |

Two harness defects surfaced by this run (fixed in the W15 code, documented in-line): (a) the Edge sale store answers **201**, the harness demanded
200 — the three real sales were recorded FAIL on the status alone; (b) after the delivery-mode address step the mode switch to takeaway clears the
order's customer by design, and the harness decided "still attached" from the hidden chip's TEXT → sale 36 went out as a walk-in. Fix: verdict on
200|201 + `sale_no`; attachment decided from the VISIBLE chip + hidden `#customer_id`, re-attaching from the lookup otherwise.

Run 2 — the clean case A (`--only W01,W02,W15,W16 --qa-phone "+92 300 7654321"`): **W01/W02/W15/W16 all PASS, 0 HTTP errors.** Quick-add 200
`reused:false` → id **1000000002**, `phone 923007654321`, **`phone_normalized 03007654321`**, **`customer_uuid 01M586J4CKVSREJVA4SZV5RARP`**;
attached (chip); lookup lists it at once; address book; `POST …/customers/1000000002/addresses` → 200 `{ok, address{id 1000000001,
address_uuid 01M586J9X7TV3Q5RHTQSE829EA, "W15 Street 6573, Proof Town", is_default true}}`; listed + selected; attached; takeaway; customer on the
order (chip visible, hidden `customer_id 1000000002`); CASH sale **39 `SO-1-1-01M586JJ26JP9CMRP97RRRSY37` 201**. Screenshots
`W15-customer-quick-add-01…07-sale-new-customer-after.png`.

## 6. Step 4 — envelopes, transport, handback, replay

`edge_sync_outbox` on 8096 (immutable stored bytes):

| row | sale_uuid | schema | customer.kind | content |
|---|---|---|---|---|
| 1 | `01M5867PC6…` (sale 36) | `edge-sale-envelope-v1` | `walk_in` | (harness-defect sale) |
| 2 | `01M586886W…` (sale 37) | `edge-sale-envelope-v1` | `customer` | `customer_uuid 01M430P442…` (Lab Customer One — a synced customer with no local edits travels in the unchanged v1 shape) |
| 3 | `01M5868TBD…` (sale 38) | **`edge-sale-envelope-v3`** | **`new_customer`** | `{kind, name "Zed Alias 02959", phone 923002349813, customer_uuid 01M5868P2N…, new_customer{name, email null, phone, phone_normalized 03002349813, addresses []}}` |
| 4 | `01M586JJ26…` (sale 39) | **`edge-sale-envelope-v3`** | **`new_customer`** | `new_customer{name "Zed Proof 20579", phone 923007654321, phone_normalized 03007654321, addresses[{address_uuid 01M586J9X7…, label Home, address "W15 Street 6573, Proof Town", is_default true}]}`, `customer_uuid 01M586J4CK…` |

`serve-clone.sh worker start` while LOCAL_ACTIVE → tick 1 `ack reconciling {kind: local_sync, drained: 4, outcomes: {acknowledged: 4}, reconciled: true,
clean: true, findings: {in_sync: 4}}` — the worker's local-sync drained the outbox (all 4 `acknowledged`, attempts 1, `ack_ingestion_uuid` set);
clone lease flipped to **holder `edge`, `edge_state local_active`, `fenced_at` set** (branch `sales_operating_mode` stayed `cloud`, `local_edge_status`
`pending` — the fence is the mechanism on this clone). `edge:local:sync-send` again → `0 acknowledged, 0 retryable, 0 terminal, 0 rejected`
(acknowledged rows are never resent — by design); `sync-status`: `Outbox: 0 pending, 0 leased, 4 acknowledged, 0 failed`.
`authority-handback --assess` → `ready: true, blockers: []` (earlier, before the worker ran: `CLOUD_NOT_FENCED`, `RECONCILIATION_NOT_CLEAN` — the
designed order is worker → fence → reconcile → handback). **Replay** (step 5C, before the handback, while the device is the holder):
`APP_ENV=edgeclone php <scratchpad>\replay-outbox.php 3 4` re-POSTs each row's stored envelope bytes to `config('edge.sync.url')` with the
product's own headers (device id + bearer from config, never printed; loopback-only, DB-name-guarded):
- row 3 → HTTP 200 **`status "already_applied"`**, `sale_uuid`/`content_hash` match, `ingestion_uuid 01M586PAK5W21X1MBE9F48DZKR`, `official_sale_no SO-20261018190922-574` (the SAME as the first application);
- row 4 → HTTP 200 **`already_applied`**, `ingestion_uuid 01M586PB3JRVAK4HQYQV219Z0M`, `SO-20261018190923-912` (same).

`edge:local:authority-handback --by="Team W dev clone W15 proof"` → **"Authority handed back to the Cloud — the appliance is a warm standby again."**;
clone lease holder `cloud`, `standby`, `fenced_at NULL`; 8096 `standby`, `online`, reason "handed back to Cloud by Team W dev clone W15 proof".

## 7. Step 5 — Cloud ingestion on the clone (SQL evidence, ids only)

`edge_inbound_sale_ingestions` (ids 6–9): 36 v1 applied → `SO-20261018190921-353`; 37 v1 applied → `SO-…-130` customer 1; **38 v3 applied →
`SO-…-574` customer 10; 39 v3 applied → `SO-…-912` customer 11**; `failure_code NULL` on all; ingestions 5 → 9 (no row for a replay).

- **A — new phone:** `customers` id **11**, `customer_uuid 01M586J4CKVSREJVA4SZV5RARP` (= the offline uuid, canonical), `phone 923007654321`,
  `phone_normalized 03007654321`, active; exactly 1 customer with that key; `customer_addresses` id 4, customer 11, **`address_uuid
  01M586J9X7TV3Q5RHTQSE829EA`** (= the till's), Home, default — once; `sales_orders` 39 `customer_id 11`, snapshot name/phone from the envelope.
- **B — existing normalized phone:** the Cloud-only customer 10 (`01M5860CXSPB3PA5WQXWRTDAYN`, Online-created) was MATCHED: still 1 customer with
  `phone_normalized 03002349813` (no duplicate, name not renamed), **`customer_uuid_aliases` = 1 row: `alias_uuid 01M5868P2NYD5338D0FYFR3ZC8
  → customer_id 10 / customer_uuid 01M5860CXS…`, `source phone_match`, `source_device_uuid c03a54be-…`**; `sales_orders` 38 `customer_id 10`
  (snapshot name "Zed Alias 02959"). Sale 37 (kind `customer`, Lab Customer One) linked to 1 — no alias (none needed).
- **C — replay:** after the two byte-identical replays: customers 11, aliases 1, addresses 4, sales_orders 39, ingestions 9 — unchanged; the
  ACKs carried the first application's `ingestion_uuid`/`official_sale_no`.
- **D — refresh:** the worker (STANDBY again after the handback) applied **revision 18 → 20** (`revision_gap {18→20}`, log 19:11:57):
  `customers {updated 10, inserted 1, kept_local 2}`, `customer_addresses {updated 4, kept_local 1}`, `customer_uuid_aliases {inserted 1, merged 1}`,
  `[edge-config-refresh] local customer merged into canonical {alias_uuid 01M5868P2N…, customer_uuid 01M5860CXS…, from_id 1000000001, to_id 10}`.
  8096 after: customer **1000000002 keeps its LOCAL id** under the Cloud's uuid `01M586J4CK…` (`edge_origin → cloud`; Cloud id 11 ≠ local id —
  harmless, every cross-system reference is by uuid); its address keeps local id 1000000001 under `address_uuid 01M586J9X7…` (`cloud`); the Cloud
  customer 10 inserted locally (id 10, `cloud`); the aliased local row 1000000001 merged away and **sale 38 repointed to customer 10**; the alias
  book row present locally; the local-only customer 1000000000 ("Zed Proof 71394", never sold — its sale went walk-in) **untouched: active,
  `local`, with its local address**; totals 12 customers, **12 active, 0 duplicate `phone_normalized` keys**, 0 addresses deleted.

## 8. Step 6 — old Cloud / nothing advertised (PASS)

Never-paired `:8095` (`--mode edge --base-url http://127.0.0.1:8095 --user DEVCASH1 --manager-user DEVMGR1 --only W01,W15`, evidence
`…\w15\edge-8095-nothing-advertised\`): `authority.state local_manual`, `lease_mode false` → `capabilities.customerCreate false`,
`customerAddressCreate false`, routes `customerQuickStore`/`customerAddressStore` **null**; quick-add panel with `#qa-save` DISABLED and the hint
*"No exact match in the synced customer book. Adding a new customer here needs the Online service to confirm offline customer support; until then
add the customer on the Online POS."*; direct `POST /edge/local/pos/customers/quick-store` → **422 `{ok:false, capabilityOff:true,
code:"CLOUD_CAPABILITY_NOT_ADVERTISED"}`**. MySQL on `_t16` (`DB_DATABASE=pos_test_master_edgewt_t16 EDGE_TEST_TENANT_DB=pos_test_tenant_edgewt_t16
EDGE_TEST_LOCAL_DB=pos_test_edge_local_edgewt_t16 php vendor/bin/phpunit -c phpunit.mysql.xml --filter …`):
`EdgeCustomerQuickStoreHttpMySqlTest::test_capability_withdrawn_a_sale_with_a_local_customer_fails_closed_with_no_sale_and_no_envelope` and
`EdgeCustomerIngestionMySqlTest::test_an_old_cloud_refuses_v3_schema_unsupported_the_sender_parks_the_row_and_the_same_row_applies_after_the_upgrade`
→ **OK (2 tests, 22 assertions), 1:17.6**.

## 9. Step 7 — pair left sane; final state

`serve-clone.sh worker stop` (19:12:52, graceful) → `php devclone-cloud.php release-lease "Team W dev clone W15 proof complete: worker stopped"`
(19:12:56) → `released: true, fenced: false`; clone `lease_blocks_cloud false`, `sales_operating_mode cloud`, open shifts 0 — the Online clone answers
sales. 8096: `authority_state standby`, `connection online`, `cloud_capabilities_seen {"customer_create":true}`, `last_applied_config_revision 20`,
serving (health 200), worker NOT running, outbox 4 acknowledged / 0 unacked, no open shift (W16 closed it). Lease row: holder `cloud`,
`edge_state standby`, `heartbeat_seq 26702`, `released_at 2026-10-18 19:12:57`.
**Counts at the end:** LAB `sales_orders` **5** · clone customers 11 / addresses 4 / aliases 1 / sales_orders 39 / ingestions 9 ·
8096 customers 12 / local sales_orders 39 (4 sold here + mirror) / aliases 1.

## 10. Findings for the coordinator

- **F1** (fixed, 7999a50) — §3; the fix is verified on the skewed appliance (healed on first refresh, zero deletions).
- **F2 — Online: address save from the POS modal answers 403 "Permission denied." for the cashier.** `EnsureRoutePermission` gates every
  non-allow-listed route by its NAME; `tenant.pos.customers.addresses.store` is not allow-listed and **no permission of that name exists** in the
  clone tenant's `permissions` table (only `tenant.pos.customers.quick-store`), so `lab.cashier@edgehomelab.test` got 403 on
  `POST /pos/customers/10/addresses` (Online run, `…\online-cloud-only-customer\report.json`). The Edge twin is gated by `quick-store` (Phase 4 §1
  claims Online's add-address route "has none of its own" — it has an implicit, ungrantable one). Parity gap on the Online side; not patched.
- Observation: on this clone the branch row stayed `sales_operating_mode cloud` / `local_edge_status pending` throughout the takeover; the lease
  row's `holder edge` + `fenced_at` carried the authority (the Phase 3 §7 note on `branch-mode` applies).
- Observation (expected, Gap C 50d5bff): the first returnable package after a worker start refreshed the recent-orders mirror (8096 local
  sales 34 → 35 before any sale).

Files: this doc; `tools/edge-browser-proof/shared-pos-workflows.mjs` (W15 only: non-canonical phone + `phone_normalized` assertion, STANDBY negative,
address through the modal, cash sale per customer with 200|201 verdict and a visible-chip re-attach, `--qa-existing-phone`, `--qa-cloud-only-phone`,
nothing-advertised POST probe); scratchpad helpers `migrate-devclone-tenant.php` (clone-only migrations, `--apply` / `--apply-master`) and
`replay-outbox.php` (byte-identical replay through the product transport); evidence `C:\Users\Dell\BingooEdgeLab\evidence\phase4\w15\{edge-standby,
edge-8095-nothing-advertised, online-cloud-only-customer, edge-writer, edge-writer-2}\` (report.json + PNGs).
