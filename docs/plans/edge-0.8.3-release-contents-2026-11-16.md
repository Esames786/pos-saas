# Edge 0.8.3 — release contents (preparation only, 16 Nov 2026)

Owner next step (16 Nov 2026): "Freeze this integrated Edge baseline for release preparation … Prepare the next Edge release
contents … Identify mandatory versus optional changes." No release is built and no real appliance is migrated by this plan.

Frozen baseline: tag **`edge-baseline-release-prep-20261116`** = `5bb43b7c` on `feat/edge-config-refresh-v1` (merge `e23f74d3`
of rehearsal `dc9f1cec`; canonical integrated to `5c74107f`). Newer canonical commits are NOT chased unless a critical
compatibility or security defect appears.

Impact from the system map (rebuilt from the integ DBs at `dc9f1cec`: 279 tables / 445 FKs / 749 screens): tables
`receipt_layout_settings`, `product_pack_units`, the eight `*_lines` tables, `purchase_bills`, `sales_order_lines.deal_share`,
`combos.report_by_items`, `combo_components.report_amount`, `supplier_ledgers.entry_date`, `goods_receipt_revisions`;
screens: shared POS (`tenant.pos.index`), Edge shifts (`/edge/local/pos/shifts…`), Edge held-sale split, KOT / receipt documents;
tests: `EdgeTenantMigrationUpgradeMySqlTest`, `EdgeShiftCounterScopeMySqlTest`, `EdgeCashierDealsDiscountsHttpMySqlTest`,
`EdgeInboundSaleIngestionMySqlTest`, `EdgeCashierScreenRendersHttpMySqlTest`, `tests/Feature/Edge`.

## 1. What 0.8.3 would carry versus 0.8.2-edge (`a6113772`)

### A. In the frozen baseline already (Edge branch, `a6113772..5bb43b7c`)

| # | change | commits | class |
|---|---|---|---|
| A1 | **F-1 standby write gate** — `POS.canMutate` / `applyMutationGate` in the shared transport; Review & Pay / Complete Sale / Open shift / hold / deals refuse while gated with the reason; 422 authority refusals surfaced; cart never discarded; live standby → local_active → standby without reload | `db102243`, `ffed2ef3`, `8874f342` (+ rehearsal `25fbb425`) | **MANDATORY** (LAB finding F-1: silent UI in standby) |
| A2 | **Counter-wise shift isolation** (SHIFT-LIST-COUNTER-1 twin) — Edge shifts list / show / close scoped to the cashier's counters, 403 with Online's words | `844854f2` | **MANDATORY** (security: cross-counter shift and cash visibility on 0.8.2) |
| A3 | **CRLF enrol hardening** — credential-file reads rtrim LF/CRLF only (`EdgeLocalEnrollCommand::credentialFromFileContents`) | `ab6bfd3b` | **MANDATORY** (Windows reliability: a CRLF-saved secret file failed enrolment) |
| A4 | **Online cashier catch-up** (canonical 073238de..5c74107f, chunks 1–5): recall of POS-hidden items (`recallProductsPayload`), manager-PIN Enter/focus, KOT order-note + deal-heading + customer-font-size columns replicated in the bootstrap, whole-deal split twin + deal-share ingestion parent link, shift-handover rendering compatibility (`handover` null on Edge), bootstrap column contracts | `e20ed6d6`, `26e22923`, `8c3aaddc`, chunks 9–11 (`dd2e7311`, `7efcd21c`, `aa4ddc5e`), merge `e23f74d3` | **MANDATORY** for parity (owner: Online POS = spec for Edge; ONE shared cashier view) |
| A5 | Migration manifest `@2026-11-15.1`: 10 canonical migrations classified (7 ship, 3 CLOUD_ONLY excluded), 8 upgrade proofs + 3 populated-data proofs | `4355f32d`, this record's commit | **MANDATORY** (release blocker rule: UNCLASSIFIED = 0) |
| A6 | `print_prepares` wipe classification for the transaction reset (Edge tree) | `25fbb425` | optional at runtime (the reset never runs on an appliance) — ships because it is in the tree |
| A7 | Catch-up / promotion / Phase F records, system map | docs | n/a |

### B. NOT in the baseline — decision needed: `rel/0.8.3-service-reliability` (Agent F, worktree `pos-saas-rel-083`, head `0b73606f`)

| item | content | class |
|---|---|---|
| F-1 MySQL readiness | `EdgeLocalDbReadiness` + `edge:local:wait-db` (classified bounded wait 0/1/2), workers wait for the DB after a Windows reboot instead of looping as "duplicate" | **MANDATORY** for a pilot appliance (reboot without an operator) |
| F-1b database loss | `EdgeWorkerDatabaseLoss`: workers survive a disappearing database (connection-level loss → bounded retry, no crash loop) | **MANDATORY** (the LAB saw three mysqld outages) |
| F-G10 heartbeat identity | `EdgeProcessLiveness`, `EdgeWorkerSlotPolicy`, process identity on `edge_local_print_worker_state` (one EDGE_OWNED migration `2026_10_30_000001_add_process_identity_to_edge_worker_state`) — a restarted worker takes its slot instead of exiting as duplicate | **MANDATORY** (Scheduled-Task workers exiting after a < 90 s heartbeat) |
| F-3 printer reachability | `EdgePrinterReachabilityProbe`, classified transport exceptions, failed jobs in overall health | optional but recommended (operator diagnostics) |
| F-4 scoped control | `Invoke-EdgeScopedControl.ps1`, `edge:local:worker-status` | optional (operations tooling) |
| PR-1 | 0.8.2 web-backend supervisor false restart after Modern Standby (wall-clock liveness) | **MANDATORY** (laptop appliances sleep) |

Facts: 6 commits, 32 code files (+2 394 / −113) on top of `a6113772`; gates recorded in `docs/status/edge-agent-f-service-reliability-dev.md`
(full Edge MySQL suite 730 passed / 1 skipped on the `edge_relf` set; clean-machine install class green after the host reboot).
**Merge dry run into the frozen baseline (`git merge-tree 5bb43b7c rel/0.8.3-service-reliability`): the only conflicts are the two
generated system-map files** (`docs/system-map/index.html`, `system-map.json`) — resolved by rebuilding the map; `config/edge.php`
auto-merges. Including it changes the baseline, so it needs the owner's word ("merge rel/0.8.3 into the Edge branch"), after which the
focused gates for the touched workers (`EdgeLocalPrint*`, `EdgeLocalAuthority*`, `EdgeApplianceHealth*`, `EdgeLocalSchemaUpgrade*`,
`EdgeUpdater*`) run again on the merged tree and the tag moves to the new head.

### C. Explicitly NOT in 0.8.3

- Any newer canonical commit after `5c74107f` (frozen).
- Cloud-only canonical work: online-ordering place learning, print-prepare writer, catering, purchasing screens, website — excluded
  by `config/edge.php` 'exclude' and the manifest's CLOUD_ONLY entries (`delivery_place_sightings`, `print_prepares` migration).
- P6, Local-Mode takeover/handback changes, auto-failover (owner scope lock).

## 2. Migrations in the 0.8.3 plan

| migration | manifest class | state |
|---|---|---|
| `2026_11_02_000001_add_order_note_toggle` | EDGE_REQUIRED, additive | ready |
| `2026_11_11_000001_add_deal_heading_toggle` | EDGE_REQUIRED, additive | ready |
| `2026_11_14_000001_add_customer_font_size_to_receipt_layouts` | EDGE_REQUIRED, additive | ready |
| `2026_11_03_000001_add_entry_date_to_supplier_ledgers` | EDGE_SCHEMA_SAFE | ready (upgrade proof incl. the FK-index probe) |
| `2026_11_03_000002_create_goods_receipt_revisions` | EDGE_SCHEMA_SAFE | ready |
| `2026_11_08_000001_add_deal_split_report_columns` | EDGE_SCHEMA_SAFE | ready |
| `2026_10_23_000002_sale_line_entered_unit` (and the other post-0.8.2 EDGE_REQUIRED ones already proven) | EDGE_REQUIRED | ready |
| **`2026_10_23_000001_decimal_pack_sizes`** (R-1) | EDGE_REQUIRED, non-additive (type change) | **OWNER_REVIEW — approval withheld** |
| **`2026_11_03_000003_let_a_purchase_bill_be_voided`** | EDGE_SCHEMA_SAFE, non-additive (ENUM MODIFY) | **OWNER_REVIEW — approval withheld** |
| **`2026_11_02_000002_order_note_toggle_default_off`** | EDGE_REQUIRED, data rewrite | **OWNER_REVIEW — approval withheld** |
| `…add_process_identity_to_edge_worker_state` (rel/0.8.3, EDGE_OWNED) | — | only if B is merged |
| `delivery_place_sightings`, `print_prepares` | CLOUD_ONLY | excluded from the package |

**Enforcement note (honest).** `OWNER_REVIEW_REQUIRED` is validated and reported by the manifest (`ownerReviewRequired()`, plan entries),
but the plan verdict only turns RELEASE_BLOCKER on an unclassified file — a reviewed-but-unapproved migration is still `RUN`. Today the block
is procedural: the owner's "no release build" rule. Optional hardening for 0.8.3 (not done, needs the owner's word): an `OWNER_APPROVED`
key per OWNER_REVIEW entry, with `plan()` listing unapproved ones as blockers, so the package builder itself refuses.

Approval evidence per migration: `docs/status/edge-promotion-2026-11-16.md` §2 (exact DDL, existing-row effects, upgrade guards,
rollback, copy-of-LAB run) plus the populated-data run (`C:\Users\Dell\BingooEdgeLab\evidence\migrev-20261116\populated-run-log.txt`)
and the three suite tests `test_*_populated_rows_*` in `EdgeTenantMigrationUpgradeMySqlTest`.

## 3. Release blockers (as of this record)

1. The three OWNER_REVIEW migrations are in the tree: a package built from the baseline would carry and RUN them → no build until each
   is approved (or the owner decides to hold one back, which means a code change to defer it).
2. Decision on B (rel/0.8.3 reliability): without it, 0.8.3 carries no Windows reboot/sleep fixes; the LAB record shows these matter for a
   pilot. Not a blocker for the LAB demo (0.8.2 stays).
3. LAB demo first (owner priority): no release install on the LAB before the supervised demonstration.

## 4. Procedure once unblocked (P5B custody, unchanged)

Build from the (possibly moved) tag → artifact verification → disposable copy install + upgrade proof (labcopy) → signed update →
owner-attended LAB install under the 0.8.2 record's phases A–D. Not started.
