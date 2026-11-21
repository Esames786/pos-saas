# ac5b168d (H1 refund-shift rule) — W09 repeated on BOTH runtimes + the 8096 schema plan (Team FB3, 22 Oct 2026)

Worktree `D:\laragon2\www\pos-saas-edge`, branch `feat/edge-config-refresh-v1`, HEAD `ac5b168d` (= `c3093168` H1 + the categories.updated_by
exception). Owner instruction: "If the new refund-shift validation changes the return interaction, repeat W09 on Online + Edge." It does —
H1 makes a CASH refund require an open shift on the cashier's selected counter on BOTH runtimes, refused with the one shared text
`A cash refund leaves a drawer — open a shift on this counter before refunding in cash.`, and the Edge return event is now
`edge-return-envelope-v2` (processing-shift identity). Team FB3 repeated Team FB2's procedure (`edge-087278e9-browser-and-baseline.md`)
for W09 only, on the three disposable instances. Nothing under `app/ resources/ routes/ tests/ tools/` was touched, the harness was NOT
edited, nothing was committed, the release build running from this commit was not disturbed. The LAB appliance (`:8443`), the LAB Cloud
(`:9701`), the LAB databases, the dev Cloud `:9702` and production were never touched — `pos_lab_tenant_edge.sales_orders` = **5** before,
during and after every step. Secrets were read from `C:\Users\Dell\BingooEdgeLab\secrets\*.pass` into the environment and never printed.

Instances: ONLINE clone `http://edgehomelab.localhost:9704` (`pos_devonline_tenant_edge`, tenant migrations **215 → 217** on this tree);
PAIRED dev Edge `http://127.0.0.1:8096` (`bingoo_edge_devclone_local`, device `c03a54be-…`, epoch 2, schema-upgraded through this tree's plan,
**216 → 218**); DEV Edge `http://127.0.0.1:8095` (`bingoo_edge_devtest_local`, re-seeded on this tree, 242 migrations, manual Local Mode).

```
W09_ONLINE        = PASS   clone 9704, --only W01,W02,W03,W09,W16 all PASS, 0 fenced 409s, only the usual GET /dashboard 403; W02 shift 7 → W09 return 5
                           SR-20261022172821-154 on sale 72: shift_id 7 / terminal_id 1 / processing_shift_uuid 01M5JAEEKVGZRAG4819TDGFVAV (= shift 7's
                           shift_uuid) / business_date 2026-10-22 / cash 100.00; shift 7 closed by W16 with total_refunds 100 / total_cash_refunds 100 (§1)
W09_EDGE          = PASS   8095 fresh seed, manual Local Mode, same subset all PASS, 0 HTTP ≥ 400; shift 1 → return 1 SR-1-1-01M5JAYH59M45Z27VF1061KHN0 on
                           sale 1: shift_id 1 / terminal_id 1 / processing_shift_uuid 01M5JAW33NPSD73NSBH6FJZA35 (= shift 1's uuid) / business_date 2026-10-22
                           — Team FB2's F-FB2-1 (shift_id NULL / terminal_id NULL on the Edge row) is CLOSED; outbox row edge-return-envelope-v2 (§3)
W09_PAIR          = PASS   8096 as the branch WRITER (takeover --by="Team FB3 ac5b168d W09"): local sale 72 SO-1-1-01M5JBDGXS… → return 5
                           SR-1-1-01M5JBEBN5QPVBWRZVYKHTFMQH on processing shift 5 (shift_uuid 01M5JBC5NSAJ42AYN1CT98GPZF); worker drained 2 → clone
                           return 8 SR-20261022174803-681 on official sale 73: processing_shift_uuid 01M5JBC5NS… / terminal_id 1 / shift_id NULL (no Cloud
                           shift mirror: 0 shifts rows with that uuid) / business_date 2026-10-22 / cash 100.00; replay → 200 already_applied (§5, §6)
REFUSAL_ONLINE    = PASS   no open shift (0 in the branch): POST /sales-returns cash → 302 back, the create page renders .alert-danger === the shared
                           text; bank_transfer → 302 /sales-returns/7 (SR-20261022173245-535: shift_id NULL, terminal_id NULL, processing_shift_uuid NULL,
                           business_date 2026-10-22 = the branch's current day) (§2)
REFUSAL_EDGE      = PASS   no open shift: form POST /edge/local/pos/sales-returns cash → 302 back + .alert-danger === the shared text; JSON POST
                           /edge/local/pos/returns cash → 422 {message, errors.shift[0]} === the shared text; bank_transfer: the form's option is rendered
                           disabled (never posts), JSON → 422 errors.refund_method "A bank transfer refund needs the Online POS …" (offline policy) (§4)
V2_ENVELOPE       = PASS   8096 outbox 12: envelope_schema_version edge-return-envelope-v2, processing_shift {shift_uuid 01M5JBC5NS…, terminal_id 1,
                           business_date 2026-10-22, opened_at 2026-10-22T17:43:37+00:00, opened_by_user_id 1}, actor {user_id 1, employee_code LAB2C5D,
                           terminal_id 1} with NO shift_id key; content_hash 2c64fe2f…; clone edge_inbound_return_ingestions 1 applied (§6)
PLAN_HASH_8096    = sha256:1a7d51454b7590c9b0fd9477acb912d3f7455937b70c71fbfe3b9346cd98cde6
                           (edge-tenant-migration-manifest@2026-10-22.1; RUN = exactly 2026_10_21_000020_add_updated_by_to_categories +
                           2026_10_22_000001_add_processing_shift_uuid_to_sales_returns, SKIP_CLOUD_ONLY 24, blockers 0, verdict OK; applied on 8096,
                           migrations 216 → 218, same hash printed by the apply) (§0)
FINAL_PAIR_STATE  = clone lease holder cloud / standby / fenced_at NULL / released_at 2026-10-22 17:49:53 ("Team FB3 ac5b168d W09 complete: worker
                           stopped"); 8096 standby / connection online, config 27 = 27, outbox 12 acknowledged / 0 unacked, 0 open shifts, worker NOT
                           running, health 200; clone 0 open shifts, login 200; 8095 0 open shifts, health 200 (§7)
LAB sales_orders  = 5 (unchanged)
```

## 0. Preparation on this tree

| step | result |
|---|---|
| all three answer HTTP | first probe at 15 s timed out on 9704 and 8095 (the box was carrying the 0.8.0 release build + another worktree's phpunit); at 90 s all three 200 in < 0.7 s — the PHP servers of 20 Oct were still alive (pids 29448 / 39820 / 37032), no restart needed |
| clone 9704 — `migrate-devclone-tenant.php` (pretend) | master `pos_devonline_master_edge`: Nothing to migrate; tenant pending = exactly `2026_10_21_000020_add_updated_by_to_categories`, `2026_10_22_000001_add_processing_shift_uuid_to_sales_returns` |
| clone 9704 — `--apply` | both DONE (817 ms / 319 ms); **tenant migrations 215 → 217**; `sales_returns.processing_shift_uuid` present |
| 8095 — `bash tools/edge-dev-instance/seed.sh` | OK (1 test / 5 assertions, 2:16, 17:22 → 17:25 UTC) — `bingoo_edge_devtest_local` re-created: **242 migrations**, products 14, terminals 1/2, DEVCASH1 / DEVMGR1; 0 sales / 0 shifts / 0 returns; `sales_returns.processing_shift_uuid` present |
| 8096 — `edge:local:schema-upgrade --dry-run --json` | plan `edge-migration-plan-v1`, manifest `edge-tenant-migration-manifest@2026-10-22.1`: **RUN = exactly the 2 new migrations**, SKIP_CLOUD_ONLY **24**, blockers **0**, verdict OK, **plan_hash `sha256:1a7d51454b7590c9b0fd9477acb912d3f7455937b70c71fbfe3b9346cd98cde6`** (JSON copy: scratchpad `fb3-8096-plan.json`) |
| 8096 — apply | "Edge-local schema upgraded." applied the 2 (344 ms / 216 ms), 24 CLOUD_ONLY recorded never executed, same plan_hash; `migrations` **216 → 218**; `sales_returns.processing_shift_uuid` + `categories.updated_by` present; version `edge-local-schema@2026_10_19_000001_add_migration_plan_to_edge_local_updates` |
| 8096 — `authority-status` before | `standby`, connection `online`, all 9 gates true (incl. `AUTHORITY_TAKEOVER_SAFE`), `can_take_over true`, applied 27 = advertised 27; outbox 10 acknowledged; 71 local sales_orders; 0 open shifts; worker NOT running — exactly as Team FB2 left it |
| clone lease before | `holder cloud / standby / expires_at NULL / fenced_at NULL / released_at 2026-10-21 22:59:10` ("Team FB2 087278e9 W15 complete: worker stopped") |
| counts before | clone customers 17 / sales_orders 71 / ingestions 15 / sales_returns 4, open shifts 0; 8096 sales_orders 71 / sales_returns 4 (mirror) |

Subset note: the owner's `--only W01,W02,W09,W16` makes W09 skip itself on every runtime — the harness's W09 returns `lastSaleNo`, which only W03 sets
(`shared-pos-workflows.mjs:774`, "W03 produced no sale to return"). Every run below therefore used **`--only W01,W02,W03,W09,W16`** (W03 = the
two-line cash sale W09 returns one line of; the second line stays returnable for the negative probe). Nothing else was changed.

## 1. ONLINE W09 on the clone 9704 (`…\evidence\phase5\w09-ac5b168d\online\`)

`shared-pos-workflows.mjs --mode online --base-url http://edgehomelab.localhost:9704 --user lab.cashier@edgehomelab.test --only W01,W02,W03,W09,W16`
(17:25 → 17:29 UTC). **W01 / W02 / W03 / W09 / W16 all PASS, 0 fenced 409s**; HTTP ≥ 400 in the whole run: only `GET /dashboard 403` (cashier
landing, as always); console: the usual product-image asset 404s, no page error.

| W | verdict | what the Online POS did on ac5b168d |
|---|---|---|
| W01 | PASS | slot `ONLINE · CLOUD`, terminal `Lab Counter 1` (id 1); capabilities carry `returnWithoutBill:true, reminderPause:true, customerCreate:true …` (unchanged from 087278e9) |
| W02 | PASS | `/shifts/open` → **shift 7** open on terminal 1, business date 2026-10-22, `shift_uuid 01M5JAEEKVGZRAG4819TDGFVAV` |
| W03 | PASS | `POST /pos` → **`SO-20261022172751-547` (sale 72)**, two lines (169, 170), 130.00 |
| W09 | PASS | Return button URL `/sales-returns/create?embed=1`; picker finds the W03 sale ("SO-20261022172751-547 — Home Lab Branch — 130.00 (2026-10-22)"); after the bill pick the iframe is at `/sales-returns/create?embed=1&terminal_id=1&sales_order_id=72` (terminal carried through, as on 087278e9); cash selected; **POST 302 → `/sales-returns/5`**; returns list shows **`SR-20261022172821-154`** |
| W16 | PASS | open work `{held:[], sessions:[]}`, close 302, `No open shift` |

Clone DB after W09 (ids only): **`sales_returns` 5 = sale 72, `shift_id 7` (the W02 shift), `terminal_id 1`, `processing_shift_uuid
01M5JAEEKVGZRAG4819TDGFVAV` = `shifts.shift_uuid` of shift 7, `business_date 2026-10-22`, cash 100.00**; line 169 returned 1.000 of 1.000, line 170
untouched; sale 72 `partially_returned`. After W16 shift 7 is `closed` with **`total_refunds 100.00 / total_cash_refunds 100.00`** (the paying shift's
buckets moved, the H1 rule). This is the first Online row carrying `processing_shift_uuid` (Team FB2's return 4 of 087278e9 has it NULL — pre-H1 row).

## 2. ONLINE negative probe — cash refund with NO open shift (`…\w09-ac5b168d\probe-online\`)

Scratchpad script `fb3-refund-shift-probe.mjs` (Playwright, loopback-only, logs in as the clone cashier, opens the POS page, opens the shared create
page with `sales_order_id=72&terminal_id=1`, fills the REAL `#return-form` in the DOM — line 170 qty 1, `refund_amount` blank so the server computes it
— and submits it natively, the browser following the redirect). Branch open shifts = **0** (W16 closed 7; `refundShiftId()`'s three fallbacks — the
POS counter, the user's own counter, the branch's only / own open shift — all resolve nothing). The POS page itself read `No open shift — POS operations
are blocked on this terminal.`

| case | request | response | rendered |
|---|---|---|---|
| **cash** | `POST /sales-returns` (sales_order_id 72, lines[1] = line 170 × 1, refund_method cash, terminal_id 1) | **302** → `/sales-returns/create?embed=1&terminal_id=1&sales_order_id=72` (`back()->withErrors(['return' => …])`) | `.alert-danger` = **`A cash refund leaves a drawer — open a shift on this counter before refunding in cash.`** (byte-equal to `SalesReturnService::CASH_REFUND_NEEDS_SHIFT`), h1 "Create Sales Return", no return created |
| **bank_transfer** | same fields, refund_method bank_transfer | **302 → `/sales-returns/7`** | detail page h1 "Sales Return", **`SR-20261022173245-535`** |

Clone DB (ids only): **`sales_returns` 7 = sale 72, `refund_method bank_transfer`, 30.00, `shift_id NULL`, `terminal_id NULL`,
`processing_shift_uuid NULL`, `business_date 2026-10-22`** (the branch's current business day, never a past one); sale 72 now `returned`; open shifts
still 0; returns 4 → 6 (no id 6: the first bank attempt of the probe posted the form's untouched `refund_amount` and was refused by the earlier
"Refund amount must match the calculated refund of 30.00." guard — rolled back, auto-increment consumed; probe artefact, not H1).

So the Online form answers the H1 refusal as the shared text on the create page (a redirect-back with the error under `errors.return`, the way
every other refusal of this form arrives — not a JSON 422), and a non-cash refund posts with no shift.

## 3. EDGE W09 on the dev Branch Server 8095 (`…\w09-ac5b168d\edge-8095\`)

`shared-pos-workflows.mjs --mode edge --base-url http://127.0.0.1:8095 --user DEVCASH1 --manager-user DEVMGR1 --only W01,W02,W03,W09,W16` (17:33 →
17:38 UTC). **All five PASS, 0 HTTP ≥ 400**, slot `LOCAL MODE · MANUAL SWITCH`, capabilities `returnWithoutBill false`, `customerCreate false`.

| W | verdict | Edge (manual Local Mode) on ac5b168d |
|---|---|---|
| W02 | PASS | separate page → **shift 1** on terminal 1, business date 2026-10-22, `shift_uuid 01M5JAW33NPSD73NSBH6FJZA35` |
| W03 | PASS | `POST /edge/local/pos/sales` 201 → **`SO-1-1-01M5JAXJQB5D83EF8FXN2AMMQ1` (sale 1)**, two lines, 300.00 |
| W09 | PASS | Return button URL `/edge/local/pos/sales-returns/create?embed=1`; picker finds the W03 sale; refund methods: cash enabled, bank_transfer / card / other disabled with the Edge hint; **POST 302 → `/edge/local/pos/shared/sales-returns/1`**, **`SR-1-1-01M5JAYH59M45Z27VF1061KHN0`** in the frame and in the returns list |
| W16 | PASS | close 302, `No open shift` |

8095 DB (ids only): **`sales_returns` 1 = sale 1, cash 100.00, `shift_id 1`, `terminal_id 1`, `processing_shift_uuid 01M5JAW33NPSD73NSBH6FJZA35`
(= shift 1's uuid), `business_date 2026-10-22`** — Team FB2's **F-FB2-1 is closed** (087278e9 wrote `shift_id NULL / terminal_id NULL`). Shift 1
closed with `total_refunds 100 / total_cash_refunds 100`. `edge_sync_outbox`: 1 × `edge-sale-envelope-v1` + **1 × `edge-return-envelope-v2`**, both
`pending` (8095 is never paired — nothing is sent). Line 2 of sale 1 stays returnable.

## 4. EDGE negative probe on 8095 — NO open shift (`…\w09-ac5b168d\probe-edge-8095\`, `…\probe-edge-8095-jsonbank\`)

Same script, `--mode edge --sale-id 1`; the POS page's JS selected the pinned Counter 1 (session key `edge_pos_terminal_id`); 0 open shifts after W16.

| case | request | response |
|---|---|---|
| **cash, the form** | `POST /edge/local/pos/sales-returns` (`storeFromPage`, the Online form's twin) | **302** back to `…/sales-returns/create?embed=1&terminal_id=1&sales_order_id=1`; `.alert-danger` = **the shared text** (byte-equal); nothing posted |
| **cash, the JSON route** | `POST /edge/local/pos/returns` (`storeReturn`, Accept JSON, X-CSRF-TOKEN) | **422 `{message: <shared text>, errors: {shift: [<shared text>]}}`** — `errors.shift[0] === SalesReturnService::CASH_REFUND_NEEDS_SHIFT` |
| **bank_transfer, the form** | the `#refund_method` option `bank_transfer` is rendered **disabled** on Edge (D-hint) → a native submit sends no value → 302 back "The refund method field is required." (the form cannot post a non-cash refund at all) | — |
| **bank_transfer, the JSON route** | `POST /edge/local/pos/returns` refund_method bank_transfer | **422 `errors.refund_method`: "A bank transfer refund needs the Online POS (the provider/bank must authorise it) — offline the till can only refund cash."** (the offline refund-method policy, unchanged; the shift rule is never reached for a non-cash refund) |

8095 after the probes: still 1 sales_return, 1 sale, 0 open shifts.

## 5. PAIR (8096 ↔ 9704) end to end for the v2 envelope

| # | when (UTC) | command / action | result |
|---|---|---|---|
| 1 | 17:42:01 | `serve-clone.sh status` / `worker stop` | health 200, "no running authority worker — nothing to stop" (Team FB2 left it stopped at 22:59 the day before) |
| 2 | 17:42:03 | clone lease | `holder cloud / standby / expires_at NULL / fenced_at NULL / released_at 2026-10-21 22:59:10` — already lapsed AND released, so no lapse wait was needed (the `AUTHORITY_TAKEOVER_SAFE` gate was already true in §0) |
| 3 | 17:42:04 | `edge:local:authority-takeover --confirm --by="Team FB3 ac5b168d W09"` | **`LOCAL MODE ACTIVATED.`** — `local_active`, connection `reconciling` ("outbox drained — reconciling with the Cloud"), all 9 gates true, config 27/27, cashier `SYNCHRONIZING / local_active`; `--accept-stale` never used |
| 4 | 17:42:27 → 17:46:58 | Edge WRITER run `--mode edge --base-url http://127.0.0.1:8096 --user LAB2C5D --manager-user LABMF2DE --only W01,W02,W03,W09,W16` (`…\w09-ac5b168d\pair-writer-8096\`) | **W01 / W02 / W03 / W09 / W16 PASS — 0 HTTP ≥ 400, 0 fenced 409s**; slot `SYNCHRONIZING · LOCAL MODE`; W02 **shift 5** (`shift_uuid 01M5JBC5NSAJ42AYN1CT98GPZF`, opened 17:43:37, by user 1); W03 **local sale 72 `SO-1-1-01M5JBDGXSZRR3M3N0H9ZR26W0`**; W09 **return 5 `SR-1-1-01M5JBEBN5QPVBWRZVYKHTFMQH`** (302 → `/edge/local/pos/shared/sales-returns/5`, listed); W16 closed shift 5 (`total_refunds 100 / total_cash_refunds 100 / expected_cash 1030`) |
| 5 | 17:44:49 | 8096 row + outbox BEFORE sync (scratchpad `fb3-pair-facts-before-sync.json`) | local `sales_returns` 5: `shift_id 5 / terminal_id 1 / processing_shift_uuid 01M5JBC5NS… (= shift 5's uuid) / business_date 2026-10-22 / cash 100.00`; outbox **12** `edge-return-envelope-v2`, `pending`, attempts 0, `content_hash 2c64fe2f77d6238b4ae0ffb3feba6aee38d14cd18f870f73fd5402fa22819b86` (envelope facts in §6); outbox 11 = the W03 sale; 10 acknowledged + 2 pending |
| 6 | 17:47:53 → 17:48:06 | `serve-clone.sh worker start` | tick 1 **`ack reconciling {local_sync, drained 2, acknowledged 2, reconciled true, clean true, in_sync 12}`**; clone lease → `holder edge / local_active / fenced_at 17:47:57` |
| 7 | 17:48:10 | `edge:local:sync-status` | `Local selling: SAFE (baseline selling)`; **`Outbox: 0 pending, 0 leased, 12 acknowledged, 0 failed`** |
| 8 | 17:48:03 | clone ingestion (SQL, ids only; `fb3-pair-facts-after-sync.json`) | **`edge_inbound_return_ingestions` 1: `return_uuid 01M5JBEBN5QPVBWRZVYKHTFMQH`, `envelope_schema_version edge-return-envelope-v2`, device `c03a54be-…`, epoch 2, revision 27, `status applied`, `failure_code NULL`, `ingestion_uuid 01M5JBM951KKGAPNQC7RMC5QQN`, `sales_order_id 73`, `sales_return_id 8`, `official_return_no SR-20261022174803-681`**; outbox 12 `acknowledged`, attempts 1, `ack_ingestion_uuid 01M5JBM951…`, ack payload `status applied` |
| 9 | 17:48:40 | replay (scratchpad `fb3-replay-outbox.php 12` = Team W's helper + `EdgeSyncSender`'s URL-by-schema routing: a return row goes to `edge.sync.returns_url` = `/api/edge/sync/returns`; byte-identical stored envelope, device headers from config) | **200 `already_applied`**, `return_uuid 01M5JBEBN5…`, `ingestion_uuid 01M5JBM951…` (the first application's), `official_return_no SR-20261022174803-681`, `content_hash` = the row's; **counts unchanged** (clone sales 73 / returns 7 / sale ingestions 16; 8096 sales 72 / returns 5 / outbox 12 acknowledged) |
| 10 | 17:48:57 | `authority-handback --assess` | **`ready true`**, blockers [] (0 pending / 0 failed / 0 open tables / 0 held / 0 open shifts / 0 supplier-finance or purchase-return pending / `consecutive_acks 125` / `reconcile_clean_at 17:48:48` / `cloud_holder_seen edge`) |
| 11 | 17:49:06 | `authority-handback --by="Team FB3 ac5b168d W09"` | **"Authority handed back to the Cloud — the appliance is a warm standby again."** freshness `{config:"current", stock:"refreshed:standby", returnable:"refreshed:62-sales", supplier_finance:"refreshed:0-suppliers", purchase_return:"current"}` (no new config revision this time — the clone config did not change; no `CUTOVER_ALREADY_DONE`, no refresh error) |
| 12 | 17:49:31 → 17:49:53 | `serve-clone.sh worker stop` → `devclone-cloud.php release-lease "Team FB3 ac5b168d W09 complete: worker stopped"` | "authority worker stopped gracefully"; `released: true, fenced: false`, lease `holder cloud / standby`, seq 26790 |

### 5.1 The clone's return row for the Edge return (the owner's checklist)

`pos_devonline_tenant_edge.sales_returns` **8** = `SR-20261022174803-681`, `sales_order_id 73` (the Cloud's official row of local sale 72,
`SO-1-1-01M5JBDGXS…`), **`processing_shift_uuid 01M5JBC5NSAJ42AYN1CT98GPZF` = the 8096 processing shift 5's `shift_uuid`**, **`terminal_id 1`**,
**`shift_id NULL`** (the Cloud holds **0** `shifts` rows with that uuid — no Edge-shift mirror exists yet, exactly the documented H1 limitation; the
identity is kept on the row for the future mirror), **`business_date 2026-10-22`** = the processing shift's day, cash 100.00. It is the only Cloud
return with that `processing_shift_uuid`.

## 6. The v2 envelope (8096 `edge_sync_outbox` 12, immutable bytes)

```
envelope_schema_version : edge-return-envelope-v2
top-level keys          : activation_epoch, actor, approval, branch_id, business_date, config_revision, content_hash, created_at, device_public_uuid,
                          envelope_schema_version, event_type, freshness, lines, original, processing_shift, reason, refund_amount, refund_method,
                          return_date, return_no, return_uuid, tenant_code, tenant_id, totals
return_uuid             : 01M5JBEBN5QPVBWRZVYKHTFMQH
actor                   : {user_id 1, employee_code LAB2C5D, terminal_id 1}        ← NO shift_id key (v1 carried a numeric local id here)
processing_shift        : {shift_uuid 01M5JBC5NSAJ42AYN1CT98GPZF, terminal_id 1, business_date 2026-10-22, opened_at 2026-10-22T17:43:37+00:00, opened_by_user_id 1}
content_hash            : 2c64fe2f77d6238b4ae0ffb3feba6aee38d14cd18f870f73fd5402fa22819b86   (= the row's; = the ACK's; = the replay ACK's)
```
Outbox by schema after the run: `edge-sale-envelope-v1` 5, `edge-sale-envelope-v3` 6, **`edge-return-envelope-v2` 1** — all 12 `acknowledged`.

## 7. Final pair state and counts

Clone lease: **`holder cloud, edge_state standby, fenced_at NULL, released_at 2026-10-22 17:49:53`** ("Team FB3 ac5b168d W09 complete: worker
stopped"); clone open shifts **0**; `tenants.features` untouched (NULL). 8096: `edge_local_meta.authority_state standby`, `last_applied_config_revision
27` (= advertised 27), connection `online / heartbeats acknowledged`, `STANDBY_FRESH_ENOUGH true`, stock ok, outbox **12 acknowledged / 0 unacked**,
**0 open shifts**, health 200, **worker NOT running**. 8095: manual Local Mode, 0 open shifts, health 200. 9704: login 200. One gate observation: see
O-FB3-5.

**Counts at the end:** `pos_lab_tenant_edge.sales_orders` **5** · clone customers 17 / sales_orders **73** (71 + Online W03 72 + ingested 73) /
sale ingestions **16** / return ingestions **1** / sales_returns **7** (4 + Online W09 5 + probe 7 + Edge 8) · 8096 local sales_orders **73**
(72 after the writer run; the handback's returnable refresh mirrored the Online sale) / sales_returns 7 (mirror) / outbox 12 · 8095 sales_orders 1 /
sales_returns 1.

## 8. Findings, observations

- **H1 on both runtimes (verdict):** the SAME refusal text, from the SAME service constant, reaches the cashier on the Online form (302 back + alert)
  and on the Edge twin's page route (302 back + alert) and JSON route (422 `errors.shift`); with an open shift on the counter the cash return posts
  on both and the row carries `shift_id / terminal_id / processing_shift_uuid / business_date` of the PAYING shift; the paying shift's refund
  buckets move on both (shift 7 Online, shift 1 on 8095, shift 5 on 8096). F-FB2-1 closed.
- **O-FB3-1 (subset):** `--only W01,W02,W09,W16` cannot prove W09 — the workflow skips without W03's sale; W03 was added (§0). Harness unchanged.
- **O-FB3-2 (Edge form, fact):** the Edge create form cannot post a non-cash refund (the options are disabled with the hint), so the offline
  refund-method policy is only reachable on the JSON route (`errors.refund_method`); the shift rule never fires for a non-cash refund there.
- **O-FB3-3 (probe artefact):** a submit that carries the form's untouched `refund_amount` is refused by the existing "Refund amount must match
  the calculated refund" guard before anything of H1 — the probe blanks the field (the view's JS fills it on a qty change in real use). The rolled-back
  attempt left an auto-increment gap (clone `sales_returns` id 6 absent).
- **O-FB3-4 (Cloud shift mirror):** `shift_id` stays NULL on the Cloud row of an Edge return (0 `shifts` rows with the processing uuid) — the H1
  commit's documented limitation ("the future Edge-shift mirror resolves it"); the identity + terminal + business date are on the row.
- **O-FB3-5 (gate after release):** `authority-status` run 19 s after `release-lease` (worker stopped) still read `AUTHORITY_TAKEOVER_SAFE false`
  / `can_take_over false` with `last_ack_at 17:49:31` — the appliance's last heartbeat predates the release, so its view of the Cloud lease is the
  post-handback live lease until that view ages out; §0 shows the gate true on this tree once the view is stale (Team FB2's release of the day before).
  Re-checked at 17:53:37 UTC: **all 9 gates true, `can_take_over true`** — the gate is `EdgeAuthorityService::cloudLeaseLapsedLocally()` =
  `now ≥ authority_last_ack_at (17:49:31) + ttl 120 s + skew margin 30 s` (17:52:01), local arithmetic with no Cloud call, so it reads false for
  150 s after the last heartbeat whatever the Cloud's lease row says (fail closed), then true. Not a defect; the final state is a clean standby.
- **O3 of Team FB2 re-confirmed (environment):** node v20 must be on PATH; the first 15-s HTTP probes timed out under the release build's load
  only; Playwright's Node-side `request` cannot resolve `edgehomelab.localhost` (ENOTFOUND) — the probe submits from inside the page instead.

## 9. Harness changes

None. `tools/edge-browser-proof/*.mjs` were not edited. Scratchpad helpers used (not part of the tree): Team W's `migrate-devclone-tenant.php`
(unchanged), `fb3-run.sh` (env wrapper around the harness), `fb3-refund-shift-probe.mjs` (Playwright, loopback-only, posts the two probe returns),
`fb3-pair-facts.php` (SELECT-only, devclone/devonline names only), `fb3-replay-outbox.php` (Team W's replay + the sender's URL-by-schema routing).

## 10. Every command (chronological, Git Bash, node v20 + PHP 8.3 on PATH, secrets via env from the files)

```
php <scratchpad>\migrate-devclone-tenant.php ; php <scratchpad>\migrate-devclone-tenant.php --apply      # clone tenant 215 → 217
bash tools/edge-dev-instance/seed.sh                                                                     # 8095 re-seed (2:16)
tools/edge-dev-instance/serve-clone.sh artisan edge:local:schema-upgrade --dry-run --json                 # plan hash 1a7d5145…
tools/edge-dev-instance/serve-clone.sh artisan edge:local:schema-upgrade                                  # applied 2, skipped 24, 216 → 218
node shared-pos-workflows.mjs --mode online --base-url http://edgehomelab.localhost:9704 --user lab.cashier@edgehomelab.test --only W01,W02,W03,W09,W16 --out …\w09-ac5b168d\online
node <scratchpad>\fb3-refund-shift-probe.mjs --mode online … --sale-id 72 --out …\w09-ac5b168d\probe-online
node shared-pos-workflows.mjs --mode edge --base-url http://127.0.0.1:8095 --user DEVCASH1 --manager-user DEVMGR1 --only W01,W02,W03,W09,W16 --out …\w09-ac5b168d\edge-8095
node <scratchpad>\fb3-refund-shift-probe.mjs --mode edge --base-url http://127.0.0.1:8095 --user DEVCASH1 --sale-id 1 --cases cash,json,bank --out …\probe-edge-8095 ; … --cases jsonbank --out …\probe-edge-8095-jsonbank
tools/edge-dev-instance/serve-clone.sh worker stop ; edge:local:authority-takeover --confirm --by="Team FB3 ac5b168d W09"
node shared-pos-workflows.mjs --mode edge --base-url http://127.0.0.1:8096 --user LAB2C5D --manager-user LABMF2DE --only W01,W02,W03,W09,W16 --out …\w09-ac5b168d\pair-writer-8096
php <scratchpad>\fb3-pair-facts.php                                                                       # before sync
tools/edge-dev-instance/serve-clone.sh worker start ; edge:local:sync-status
php <scratchpad>\fb3-pair-facts.php 5 ; APP_ENV=edgeclone php <scratchpad>\fb3-replay-outbox.php 12 ; php <scratchpad>\fb3-pair-facts.php 5
tools/edge-dev-instance/serve-clone.sh artisan edge:local:authority-handback --assess ; edge:local:authority-handback --by="Team FB3 ac5b168d W09"
tools/edge-dev-instance/serve-clone.sh worker stop ; php C:\Users\Dell\BingooEdgeLab\scripts\devclone-cloud.php release-lease "Team FB3 ac5b168d W09 complete: worker stopped"
```
SQL: read-only SELECTs on `pos_devonline_tenant_edge`, `bingoo_edge_devclone_local`, `bingoo_edge_devtest_local`, `information_schema`, and
`pos_lab_tenant_edge.sales_orders` (count only).

Evidence root: `C:\Users\Dell\BingooEdgeLab\evidence\phase5\w09-ac5b168d\` — `online\`, `probe-online\`, `edge-8095\`, `probe-edge-8095\`,
`probe-edge-8095-jsonbank\`, `pair-writer-8096\` (each with `report.json` + PNGs); scratchpad: `fb3-8096-plan.json`, `fb3-pair-facts-{before-sync,
after-sync,after-replay}.json`, the run logs `fb3-*.log`.
