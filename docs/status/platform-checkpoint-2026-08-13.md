# Platform Checkpoint — 2026-08-13

Authoritative state of the Bingoo POS platform at the moment the Edge and Catering
development streams are split into separate Git worktrees. Verified against production
(read-only) and the local repository on 2026-08-13.

- **Code checkpoint:** `c4fc021` — `EDGE-SPLITBRAIN-STOCK-1`
- **Production HEAD:** `c4fc021` (independently verified; dormant, Cloud-only deploy —
  tracked tree clean, 0 pending migrations, `APP_ROLE` Cloud, TB balanced, Edge inactive)
- **Tag:** `platform-edge-stock-fence-2026-08-13` → `c4fc021`

## CLOUD / POS

- Production HEAD is `c4fc021`.
- Normal cloud POS is the **stable current platform** and the source of truth for all
  shared behavior.
- **Khatri Biryani is now normal production support**, not a development driver.
- **Khatri must not be used as an Edge test tenant** — no reset, re-onboard, Local Mode,
  or exploratory testing against its data.

## EDGE — GREEN / FROZEN (built, proven, dormant)

1. Restricted runtime boundary / fail-closed `APP_ROLE`
2. Restricted reproducible build artifact (route/CLI allowlist, physical audit)
3. Local MariaDB + bootstrap v4 + immutable branch/device binding
4. Device-bound local authentication (Ed25519 enrollment, Edge-only Argon2 credential)
5. Canonical ULID/UUID cross-system identities
6. Local shift lifecycle
7. Local POS: quick/takeaway paid sales
8. Dine-in / table sessions / held orders / Add Round
9. KOT business events + settlement authority
10. Operational (provisional) local stock authority
11. Local LAN printing (lease-owned, per-printer FIFO, retries, stale-lease recovery)
12. **`EDGE-SPLITBRAIN-STOCK-1`** — official stock authority fence (`c4fc021`): one fence
    inside `InventoryService` (all official paths), department custody sink secondary,
    transfer fences both endpoints, Branch-Server always fails closed, Cloud fails closed
    for a branch handed to Local Edge, normal Cloud branch behavior unchanged.

## EDGE — NEXT (in order)

1. `EDGE-CONFIG-REFRESH-1` — deliver online config changes (products, categories, prices,
   tax, combos/modifiers, recipes, users/roles/permissions, terminals, printers, KOT
   routing, layouts) to an existing appliance via revisioned, hash-verified, transactional
   **UPSERT-by-stable-id + tombstone-missing** — never DELETE a referenced config row,
   never overwrite local operational history.
2. `EDGE-COMPATIBILITY-CONTRACT-1` — appliance version/schema/config-revision/capabilities
   handshake so Cloud can decide compatible / update-required / unavailable-offline.
3. `OFFLINE-SYNC-ENGINE-1` — immutable idempotent sale envelopes, activation-epoch +
   config-revision verification, official Cloud posting, ack only after accounting + stock.
4. Appliance update / backup / recovery certification.
5. Entitlement lease + activation / physical certification.

## EDGE — NOT READY

- `activation_ready = false`
- Local Mode inactive (0 branches in `local_edge`)
- No production offline selling.

## CATERING

- A **new independent business vertical**, Cloud-first.
- Must **reuse stable platform primitives** (see shared authorities below), not fork them.
- Must **not change normal POS behavior merely because Catering code exists** — a
  Catering-disabled tenant must behave identically to today.
- Future offline support will use the **generic** Edge Config Refresh / Compatibility /
  Sync machinery — **not** a separate parallel offline architecture.
- First sprint: `BINGOO-CATERING-PREFLIGHT-1` (Cloud V1). Schema/domain choices must be
  Edge-ready from day one, but offline execution is not implemented yet.

## SHARED PLATFORM AUTHORITIES TO PROTECT

Changes to these are platform-level and require regression proof that existing (and
Catering-disabled) tenants behave identically:

- Products
- Categories
- Customers
- Suppliers
- `InventoryService` (official stock / FEFO / costing / valuation)
- Recipe / Unit Conversion
- Finance / GL
- Payments
- Printers (`PrintJob` physical authority)
- Permissions
- Tenant isolation / activation / context
- `BranchOperatingModeService` (operating-mode + official-stock fence)

## WORKTREE SPLIT

- `pos-saas` (this repo) — integration / reference / hotfix coordination / history.
- `pos-saas-edge` — `feat/edge-config-refresh-v1` (Session A: Edge).
- `pos-saas-catering` — `feat/catering-events-v1` (Session B: Catering).

See `docs/development/parallel-workstreams.md` for the per-session start guard.
