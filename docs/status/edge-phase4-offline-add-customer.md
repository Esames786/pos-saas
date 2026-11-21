# Next Edge release — Phase 4: offline add-customer (Team D output)

Date: 6 Oct 2026. Branch `feat/edge-config-refresh-v1` on top of 718057e. Source + isolated automated tests only (`_edgewt_t14` DBs);
nothing committed by Team D; the LAB appliance, LAB Cloud, production and the dev servers' databases were not touched. Owner's words:
*"Canonical identity must be customer_uuid, NOT the local numeric customer id … Do not ship this partially."* Everything below is shipped
together; nothing is behind a half-flag.

```
CANONICAL_IDENTITY=customer_uuid (ULID, HasCanonicalIdentity) on both sides; local numeric ids are local-only and never cross a boundary
NORMALISER=app/Support/PhoneNormalizer.php (ONE rule, Cloud + appliance): digits only; 0092XXXXXXXXXX / 92XXXXXXXXXX / 3XXXXXXXXX → 03XXXXXXXXX; other numbers keep their digits; no digits → null
ENVELOPE=edge-sale-envelope-v3 (new generation) carries customer.kind=new_customer; emitted ONLY while the Cloud advertised capabilities.customer_create=true on a heartbeat
CLOUD=deterministic create-or-match (uuid → alias → phone_normalized → create with the offline uuid); alias table customer_uuid_aliases; idempotent on replay
REFRESH=customers / addresses matched by customer_uuid / address_uuid; a local-origin row the Cloud does not carry is never deactivated; the local id is kept when the Cloud id differs; alias(U→W) merges the local row into the canonical one
BOOTSTRAP_SCHEMA=edge-bootstrap-v8 (unchanged — additive fields inside the unreleased v8 generation; the same build carries the migration)
CAPABILITY=EdgeStandbyAdvertiser customer_create=true; appliance stores the ACK on edge_local_meta.cloud_capabilities_seen; EdgePosRuntimeFactory customerCreate/customerAddressCreate follow it; quick-add enabled on the shared view with NO markup change
GATES=Feature/Edge 172 green · Feature/Pos 26 green (198 / 37,434 together) · new MySQL classes 23 tests green · coordinator Edge MySQL filter on _t14: 359 / 5,665 green (§8)
```

## 1. Branch Server: add customer / address at the till

| Piece | File |
|---|---|
| Endpoints (Online twins) | `app/Http/Controllers/Edge/EdgeLocalCustomerController.php` — `POST /edge/local/pos/customers/quick-store` (`edge.local.pos.customers.quick-store`), `POST /edge/local/pos/customers/{customer}/addresses` (`edge.local.pos.customers.addresses.store`); `routes/edge_runtime.php`, `config/edge.php` allowlist, URI census `tests/Feature/Edge/EdgeBranchServerRegistrationTest.php` |
| Rules | `app/Services/Edge/EdgeLocalCustomerService.php` |
| Request / response | exactly Online `CustomerController::quickStore` / `storeAddress`: `{name required, phone?, email?, address?}` → `{ok, reused, customer{…, customer_uuid, phone_normalized, addresses[{id, address_uuid, label, address, is_default}]}}`; `{label?, address required, is_default?}` → `{ok, address}`. The shared modal (`index.blade.php` quickSave / new-addr-save) works unchanged. |

Three gates, in order, on both actions:

1. **permission** `tenant.pos.customers.quick-store` (the Online quick-store route permission; the same permission gates both twins — `PosPermissionCatalog` entry updated. CORRECTION, F2 below: Online's add-address route did NOT lack a permission — it was gated by its own route NAME, which no tenant can grant; since F2 Online aliases that route name to quick-store, so the two runtimes really are gated by the one permission) → 403 `{message, permission}`;
2. **Cloud capability** `customer_create` advertised (`EdgeCloudCapabilities`) → otherwise 422 `{ok:false, capabilityOff:true, code: CLOUD_CAPABILITY_NOT_ADVERTISED, message}` (the shared JS shows the capability hint on `capabilityOff`); the page never reaches it anyway because the routes are `null` while not advertised;
3. **branch authority** `EdgeAuthorityService::assertLocalMutationAllowed()` — the same lease gate every Edge mutation uses → standby appliance 422 `{ok:false, code: STANDBY, message}`.

Create rule (Online quickStore mirrored): a known phone — any spelling, matched on `phone_normalized` first, then `CustomerDirectory::findByPhone`
legacy spellings — **reuses** that customer (`reused:true`, name/email topped up only when empty, never renamed); otherwise a new row with
`customer_uuid` (ULID minted by the model), `phone` = Online's digits-only spelling, `phone_normalized` = the shared key (model hook),
`status active`, **`edge_origin = 'local'`**, `edge_created_at`. Addresses: Online's `storeAddressOnce` text rule (identical text = same
address), first address = default, `address_uuid` (ULID, `CustomerAddress` now uses `HasCanonicalIdentity`), `edge_origin = 'local'`.

**Local numeric ids** of rows born at the till start at `EdgeLocalCustomerService::LOCAL_ID_FLOOR = 1_000_000_000` (allocation serialised on
the `edge_local_meta` row lock — the same lock a refresh holds), so a refresh inserting Cloud rows by their Cloud id can never collide with
a locally-born row. Ids stay local-only: every cross-system reference travels by uuid.

The new customer is immediately attachable: the lookup (`/customers?q=`) lists it at once; `POST /sales` with its `customer_id` completes
(the envelope carries the uuid + intent, §2).

## 2. Envelope contract

`EdgeSaleEnvelopeBuilder` (`customer` block):

| kind | shape | when |
|---|---|---|
| `walk_in` | `{kind, name, phone}` | no customer |
| `customer` | `{kind, customer_uuid, name, phone}` | a customer the Cloud already knows and no local edits — **v1/v2 byte shape unchanged** |
| `new_customer` | `{kind, customer_uuid, name, phone, new_customer:{name, phone, phone_normalized, email, addresses[{address_uuid, label, address, is_default}]}}` | `customers.edge_origin = 'local'` **or** a synced customer with an address whose `edge_origin = 'local'` (then only the local-born addresses ride) |

`name`/`phone` stay the SALE's snapshot (as for `customer`); `new_customer` is the book intent. A sale with the new kind is
**`edge-sale-envelope-v3`** (`SCHEMA_VERSION_V3`; v3 ⊇ v2: the Cloud consumes modifier stock exactly as for v2 and runs the create-or-match).
`content_hash` covers the intent (same canonical JSON). `EdgeAuthorityLeaseClient::envelopeVersions()` reports v3 in the heartbeat build block.

**Gating (both directions, proven):**
- the builder emits `new_customer`/v3 **only** while `EdgeCloudCapabilities::fromMeta(meta, 'customer_create')` is true; otherwise a sale with a local customer **fails closed** at the till (`ENVELOPE_UNSUPPORTED …`, 422, sale rolled back, no envelope) — the cashier attaches a synced customer or sells as walk-in. (Quick-add itself is refused while not advertised, so this only happens after a Cloud downgrade.)
- an old Cloud receiving v3 answers `refused / SCHEMA_UNSUPPORTED` (explicit code, no write); `EdgeSyncSender` **parks** the row under the bounded backoff lease (W6 rule — never `failed_permanent`); after the Cloud upgrade the identical bytes apply once. `new_customer` on a v1/v2 envelope is `ENVELOPE_INVALID` (terminal).

Codes on the Cloud: `CUSTOMER_INVALID` (bad uuid / kind / intent / `phone_normalized` disagrees with the shared normaliser / bad address identity), `CUSTOMER_UNKNOWN` (kind `customer`, uuid neither a customer nor an alias), `ENVELOPE_INVALID` (new kind on an old schema), `SCHEMA_UNSUPPORTED` (old Cloud).

## 3. Cloud ingestion: deterministic create-or-match

`EdgeInboundSaleIngestionService::resolveCustomerId()` — inside the posting transaction (a refusal/exception rolls everything back; a replay
never reaches it):

| kind | ladder |
|---|---|
| `customer` | 1 `customer_uuid` known → it; 2 alias known → canonical; else `CUSTOMER_UNKNOWN` (never a phone guess for a synced identity) |
| `new_customer` (v3) | 1 uuid known → it; 2 alias known → canonical; 3 `phone_normalized` (shared normaliser; `CustomerDirectory::findByPhone` legacy spellings as fallback) matches an EXISTING customer (row lock) → bind, **insert `customer_uuid_aliases[offline_uuid → canonical]`**, top up empty name/email only (never rename) — **no duplicate**; 4 else **create with the offline uuid as canonical** (`phone` digits-only, `phone_normalized`, email, active) |

In every branch `ensureAddresses()` creates each intent address once by `address_uuid` (Online's identical-text rule reuses an existing
address; the Cloud's default is never demoted; a customer's first address becomes the default). The sale links to the canonical
`customer_id`; `customer_name/phone` keep the envelope's snapshot. Idempotency: exact replay → `already_applied`, zero effects; a retry
after a rolled-back attempt (e.g. `INSUFFICIENT_STOCK`) re-runs the ladder and lands on step 1/2 — one customer, one alias, one address, one
sale; two sales of one offline customer → alias hit, no second alias. Two concurrent first envelopes for the same offline customer race on the
alias PK / uuid unique index → the loser records a retryable `DB_ERROR` and converges on the next attempt (documented, not special-cased).

`EdgeReservationHandbackService` also resolves a reserved `customer_uuid` through the alias book (a reservation made offline for a
phone-matched customer no longer fails `HANDBACK_UNKNOWN_CUSTOMER`).

## 4. Bootstrap / refresh (identity-keyed)

**Schema** (tenant migration `2026_10_06_000001_edge_phase4_offline_customer_identity.php`, runs on the Cloud AND the appliance): `customers.phone_normalized`
(indexed, backfilled from `phone`), `customer_addresses.address_uuid` (ULID, unique, backfilled), table `customer_uuid_aliases`
(`alias_uuid` PK, `customer_id`, `customer_uuid`, `source_device_uuid`, `source`, timestamps; deliberately **no FK** — the appliance maps the id
when it can). Edge-only migration `2026_10_06_000001_edge_phase4_offline_customer_origin.php`: `customers.edge_origin/edge_created_at`,
`customer_addresses.edge_origin`, `edge_local_meta.cloud_capabilities_seen(_at)`. `Customer::saving` derives `phone_normalized` on every
Eloquent write (Cloud and Edge); `EdgeBootstrapService::ensureCustomerIdentities()` also repairs NULL `phone_normalized` / `address_uuid`
on raw rows before export (generate-once, watermark moves once).

**Export** (`EdgeBootstrapService::buildSections`): `customers` + `phone_normalized`; `customer_addresses` + `address_uuid`; new section
`customer_uuid_aliases` (aliases of active customers); watermark includes the alias table. Importer `PLAN` gains the section (raw insert on a
fresh bootstrap). **Bootstrap schema stays `edge-bootstrap-v8`**: the fields are additive inside the unreleased v8 generation and the build
that reads them also carries the migration (an older appliance refuses nothing new because none exists outside this cycle).

**THE REFRESH RULE** (`EdgeLocalConfigRefreshApplier::upsertCustomers` / `upsertCustomerAddresses`):
1. a payload customer whose `customer_uuid` exists locally under ANY local id → UPDATE in place, **local id kept**, `edge_origin → 'cloud'`; the Cloud id → local id mapping feeds the addresses pass (their `customer_id` is MAPPED);
2. uuid unknown → INSERT with the Cloud id; an id held by a different identity → `REFRESH_CONFLICT`, whole refresh rolls back (never one customer overwritten by another);
3. a local row the payload lacks: `edge_origin 'cloud'` → `status inactive` (as before); **`edge_origin 'local'` → untouched** (active, attachable; its sale carries it to the Cloud);
4. addresses likewise by `address_uuid`; a local-origin address is kept, except a local copy whose text the Cloud now carries for the same customer (Online reused it on ingestion) → merged away.

**THE ALIAS RULE** (`applyCustomerAliases` → `mergeLocalCustomer`): the alias book is upserted locally (`customer_id` mapped to the local id,
dropped when the Cloud drops it); for alias(U→W) where both the local U row and the canonical W row exist, every reference to U is repointed to W —
FK children of `customers.id` discovered from `information_schema` + the soft references (`customer_addresses.customer_id`,
`edge_local_table_reservations.customer_id/customer_uuid`, `restaurant_table_sessions.customer_id`, `restaurant_tables.reserved_customer_id`) —
duplicate address text is removed (one default kept), then the U row is deleted. If W is not on the appliance (inactive on the Cloud) the U row
stays untouched. **Decision:** the alias table ships to the appliance because the merge needs the instruction and a reservation/held check that
still names U must resolve after handback; the Cloud resolves aliases itself for anything the appliance did not merge yet.

## 5. Capability negotiation / runtime

- Cloud: `EdgeStandbyAdvertiser::CLOUD_CAPABILITIES = ['customer_create' => true]` on every accepted heartbeat ACK.
- Appliance: `EdgeAuthorityService::heartbeat()` stores the ACK's `capabilities` (normalised `{name: bool}`) on `edge_local_meta.cloud_capabilities_seen` on EVERY accepted beat; an ACK without the key stores `[]` (fail closed — an old Cloud advertises nothing). `EdgeCloudCapabilities` is the one reader.
- `EdgePosRuntimeFactory::capabilities()` = the §7 baseline `CAPABILITIES` + `ADVERTISED_CAPABILITIES` (`customerCreate`, `customerAddressCreate` ← `customer_create`); the two routes are set only while advertised. The shared view needs **no markup change** (`@disabled(! $posRuntime->can(...))` + the hint). Hints reworded (ASCII) to say the Online service must confirm offline customer support.
- `config/edge.php` capabilities gains `offline_customer_create` (heartbeat build block + compatibility manifest).
- In manual Local Mode without a lease (no heartbeat URL) nothing is ever advertised → quick-add stays off by design (the owner's "old Cloud must refuse before a device emits"); the LAB runs lease mode.

## 6. Owner requirement → test

| Requirement | Test |
|---|---|
| add customer at Edge till; stable customer UUID; normalized phone; address support; immediate local use | `EdgeCustomerQuickStoreHttpMySqlTest::test_quick_store_creates_a_local_customer_with_canonical_identity_match_key_and_address_in_online_shape`, `…::test_add_address_follows_online_rules_and_the_address_carries_its_identity`, `…::test_a_local_customer_is_attached_at_once_and_the_envelope_carries_the_new_customer_intent_on_v3` |
| local duplicate lookup (existing phone → reuse, like Online) | `EdgeCustomerQuickStoreHttpMySqlTest::test_a_known_phone_in_any_spelling_reuses_the_synced_customer_and_never_duplicates` |
| ONE normaliser (Pakistani 03xx / +92 / 0092; stable) | `EdgeCustomerPhoneNormalizerTest` (4 tests); Cloud refuses an intent whose key disagrees: `EdgeCustomerIngestionMySqlTest::test_new_customer_on_a_non_v3_envelope_is_refused_terminally_with_no_write` |
| sale envelope carries sufficient customer intent; new kind gated on the advertisement; Edge never emits unsupported | `…QuickStore…::test_a_local_customer_is_attached_at_once_and_the_envelope_carries_the_new_customer_intent_on_v3`, `…::test_a_synced_customer_with_a_locally_added_address_travels_as_new_customer_and_without_edits_stays_the_customer_kind`, `…::test_capability_withdrawn_a_sale_with_a_local_customer_fails_closed_with_no_sale_and_no_envelope` |
| old Cloud refuses safely (explicit code, outbox parked, no partial write) | `EdgeCustomerIngestionMySqlTest::test_an_old_cloud_refuses_v3_schema_unsupported_the_sender_parks_the_row_and_the_same_row_applies_after_the_upgrade` |
| Cloud deterministic create-or-match; existing phone → no duplicate; alias preserved | `EdgeCustomerIngestionMySqlTest::test_a_new_offline_customer_is_created_with_its_offline_uuid_as_canonical_identity_and_the_sale_links_to_it`, `…::test_an_existing_cloud_phone_binds_the_sale_records_an_alias_and_never_creates_a_duplicate` |
| retries idempotent / no duplicate on replay | `…Ingestion…::test_a_name_only_customer_is_created_and_the_same_envelope_twice_is_already_applied`, `…::test_an_existing_cloud_phone_binds…` (replay + second sale + alias-as-`customer`), `…::test_a_retry_after_a_rolled_back_attempt_lands_on_the_same_customer` |
| refresh matches by UUID, not numeric id; never deactivate/overwrite an offline customer because the id differs | `EdgeCustomerRefreshMySqlTest::test_refresh_matches_customers_by_uuid_keeps_the_local_id_never_deactivates_a_local_customer_and_tombstones_only_cloud_rows`, `…::test_an_id_held_by_a_different_identity_refuses_the_whole_refresh` |
| alias rule on the appliance | `…Refresh…::test_an_alias_merges_the_local_offline_customer_into_the_canonical_one_and_repoints_every_reference`, `…::test_an_alias_whose_canonical_customer_is_not_on_this_appliance_leaves_the_local_row_untouched` |
| bootstrap fields (phone_normalized, address_uuid, alias section) | `…Refresh…::test_the_export_carries_the_match_key_the_address_identity_and_the_alias_book_and_backfills_a_legacy_row_once`, `…::test_a_fresh_bootstrap_imports_the_alias_book`, `…::test_a_local_copy_of_an_address_the_cloud_now_carries_with_the_same_text_is_merged_away` |
| permission 403; standby refusal | `…QuickStore…::test_the_online_quick_store_permission_gates_both_twins_with_the_403_shape`, `…::test_a_standby_appliance_refuses_to_add_a_customer_or_address`; `EdgeCashierPermissionMatrixMySqlTest` now probes `tenant.pos.customers.quick-store` (removed from NO_EDGE_ENDPOINT) |
| capability negotiation / render | `…QuickStore…::test_nothing_advertised_renders_quick_add_disabled_routes_null_and_the_endpoints_refuse_with_an_explicit_code`, `…::test_once_advertised_the_same_controls_render_enabled_and_the_routes_are_set`, `EdgeSharedPosRegressionGateMySqlTest::test_c2_cloud_advertised_customer_create_turns_quick_add_on_in_the_same_place` (baseline `test_c` unchanged), `EdgeSharedPosGapFixesMySqlTest` E3 (baseline) |
| heartbeat advertises true; appliance stores it; absent key = false; build report carries the capability + v3 | `EdgeHeartbeatBuildReportMySqlTest::test_every_accepted_beat_advertises_cloud_capabilities`, `…::test_the_appliance_heartbeat_tick_reports_its_build_end_to_end`, `…::test_an_ack_without_capabilities_clears_the_stored_advertisement`, `EdgeHeartbeatBuildPayloadTest` |
| census / allowlist / catalogue | `EdgeBranchServerRegistrationTest`, `EdgePermissionCatalogTest`, `EdgeSharedPosRegressionStaticGateTest` (all in Feature/Edge, green) |

Browser harness: `tools/edge-browser-proof/shared-pos-workflows.mjs` W15 — on Edge, `POS_RUNTIME.capabilities.customerCreate === true` → the
quick-add must PASS (create + attach, plus a step asserting the response carries a 26-char `customer_uuid`); `=== false` → EXPECTED-DIFFERENCE
(disabled with the hint). Not run by Team D (syntax-checked only).

## 7. LAB impact (what the next LAB update and the LAB Cloud must carry)

- **Cloud first** (deploy ordering): the LAB Cloud (:9701) must run this build before the appliance update — it then advertises `customer_create`; its tenant DB gets `phone_normalized` / `address_uuid` / `customer_uuid_aliases` by migration (backfill is additive; `ensureCustomerIdentities` repairs raw rows and mints ONE new config revision).
- **Appliance update** (signed package, after the LAB handback): tenant + edge migrations run through the schema upgrader (`customers.edge_origin`, `customer_addresses.edge_origin`, `edge_local_meta.cloud_capabilities_seen`). Quick-add stays disabled until the first acknowledged heartbeat from the upgraded Cloud, then turns on with no page change. Bootstrap schema stays v8 (no re-bootstrap).
- An appliance on the previous build paired to the new Cloud ignores the `capabilities` key (W-F additive) and keeps quick-add off; the new Cloud still ingests its v1/v2 envelopes unchanged.
- The LAB cashier role needs `tenant.pos.customers.quick-store` (TenantProvisioner's cashier template has it; `lab-cloud.php` seeds must be checked — see the Phase 3 report §3.2 list).

## 8. Gates run

| Gate | Result |
|---|---|
| Feature/Edge + Feature/Pos | 198 tests / 37,434 assertions green (Feature/Edge alone 172) |
| New MySQL classes (`EdgeCustomerQuickStoreHttpMySqlTest` 10, `EdgeCustomerIngestionMySqlTest` 6, `EdgeCustomerRefreshMySqlTest` 7) | 23 / 305 green on `_t14` |
| Updated suites (`EdgeHeartbeatBuildReport`, `EdgeCashierPermissionMatrix`, `EdgeSharedPosGapFixes`, `EdgeSharedPosRegressionGate`, `EdgeSharedPosView`) | green on `_t14` |
| Coordinator filter `EdgeSharedPos|EdgeCashier|EdgeBootstrap|EdgeConfigRefresh|EdgeLocalPos|EdgeOutbox|EdgeInbound|EdgeSync|EdgeEnvelope|EdgeW6|EdgeCustomer|EdgeHeartbeat|EdgeStandby|EdgeAuthority` (+ `EdgeReservation|EdgeIdentity|EdgeLocalImport|EdgeHandback|CustomerDirectory`) on `_t14` | **359 tests / 5,665 assertions, 0 failures, 0 errors, 10 min 19 s** (the load-sensitive partition tests are included and passed in this run) |

## 9. Deferred / notes for the coordinator

- Online `CustomerController::quickStore` keeps its contract; `CustomerDirectory::findByPhone` now tries `phone_normalized` first (so "+92 300…" and "0300…" are one phone on Online too) — a behaviour improvement, documented here; Online's stored `phone` spelling (digits only) is unchanged.
- Concurrency of two first envelopes for the same offline customer on two workers converges through the alias PK / uuid unique index (retryable `DB_ERROR`), not through an explicit lock on the alias table.
- A local-origin address whose text matches a Cloud address but with a different `address_uuid` is merged by TEXT on refresh (Online's rule); the till's `address_uuid` then never exists on the Cloud (by design — the Cloud book is the authority).
- `EdgeCleanMachineInstallMySqlTest` (appliance toolchain) not run by Team D; it runs in the release gate.
- The browser proof (W15 on both runtimes) is the coordinator's run.

## F2 — Online add-address permission

**Finding (W15 browser proof, `docs/status/edge-phase4-w15-proof.md` §10 F2):** on the ONLINE POS the shared quick-add modal's "Save address"
(`POST /pos/customers/{customer}/addresses`, route `tenant.pos.customers.addresses.store`) answered 403 "Permission denied." for the LAB cashier.
`EnsureRoutePermission` gates every non-allow-listed route by its NAME; that name is never seeded as a permission (not in `TenantProvisioner`'s
list, no migration, not in the cashier template — only `tenant.pos.customers.quick-store` is), so no cashier could ever be granted it. Section 1
above and `PosPermissionCatalog` said the route "has no permission of its own" — wrong: it had an implicit, ungrantable one. (A tenant whose
Owner published the name through the central route catalog could grant it by hand — `OnboardTawakalKashifCommand` does exactly that — which is
why it never showed on Kashif's tills.)

**Mechanism chosen — a route-name → permission alias in the middleware, no new permission.** `EnsureRoutePermission::ROUTE_PERMISSION_ALIASES`
maps `tenant.pos.customers.addresses.store` → `tenant.pos.customers.quick-store`; `permissionsFor($routeName)` returns `[alias, route name]` and
the gate passes on ANY of them (the alias is one-directional: quick-store itself still needs quick-store). Adding an address to a customer's book is
part of the one "Add Customer" workflow, so Online now gates it by exactly the permission the Edge twin uses (`EdgeLocalCustomerController::PERMISSION`).
Rejected alternatives: an allow-prefix + in-controller check (the quick-report pattern) would move the gate out of the middleware for a route that
needs no different behaviour; a new synthetic permission would need a migration + Permission Center grants on every tenant for no gain, and would
again desynchronise the twins. The route's own name still passes so an explicit route-catalog grant (Kashif's operator roles) keeps working.

**Files changed:** `app/Http/Middleware/EnsureRoutePermission.php` (alias map + `permissionsFor` + any-of gate), `app/Support/Pos/PosPermissionCatalog.php`
(quick-store entry: Edge check site + both Online routes, documents the alias), `tests/Feature/Edge/EdgePermissionCatalogTest.php` (new
`test_route_permission_aliases_point_online_routes_at_catalogued_permissions`: every alias source is a real `routes/tenant.php` name that is NOT a
seeded permission, every target is catalogued, the add-address alias equals the Edge twin's permission), new `tests/Feature/Pos/OnlineAddAddressPermissionAliasTest.php`
(middleware driven directly, no DB: quick-store only → pass on both twins; without it → 403 on both; explicit route-name grant → pass; alias one-directional),
new `tests/MySql/EdgeCustomerOnlineAddAddressPermissionHttpMySqlTest.php` (REAL Online tenant HTTP route on MySQL: a cashier holding ONLY quick-store
gets 200 `{ok, address}` and a row while the `permissions` table holds no route-name row; without quick-store 403 on both twins, no row; the
legacy route-name grant still passes, quick-store stays closed), this document (§1 correction + this section).

**Gates run (19 Oct 2026, `_t18`):** Feature/Edge + Feature/Pos **207 tests / 37,510 assertions green** (6 new); new
`EdgeCustomerOnlineAddAddressPermissionHttpMySqlTest` **3 / 17 green**; coordinator filter `EdgeCustomer|EdgeCashierPermission|PermissionCenter|CustomerDirectory`
**40 tests / 616 assertions green**. Edge behaviour unchanged (`EdgeCashierPermissionMatrixMySqlTest`, `EdgeCustomerQuickStoreHttpMySqlTest` untouched).
Test-isolation fix found by that filter (not F2): `EdgeCustomerRefreshMySqlTest` setUp now also cleans `sales_order_line_cancellations` — the
matrix class before it leaves a KOT-void row (FK → `void_reasons`) that blocked the bootstrap importer's `clearExistingConfig`; the class was green
alone and is now order-independent.

**LAB / tenant impact:** NO grant needed on any tenant — every holder of `tenant.pos.customers.quick-store` (the LAB cashier role, the
`Cashier (Counter)` template, every Owner) gains Online add-address the moment this build is deployed; no migration, no permission row, no cache
flush (the alias is code, the quick-store row already exists). Tenants that granted `tenant.pos.customers.addresses.store` by hand keep it; it is
now redundant and may be left as is. The Edge appliance is unaffected (its twin was already gated by quick-store). The W15 Online run's 403 is
closed by the Cloud deploy alone.
