# Offline Edge — Batch 2 + Sync 1B closure — 2026-08-25

Branch `feat/edge-config-refresh-v1`. Prerequisite closure before Cloud ingestion (1C).
Production `c4fc021` Cloud-only; Local Mode inactive; `activation_ready=false`; no appliance deployed.

## Re-ground
- EDGE_START_HEAD: `a46a506` (docs work-summary on top of the Batch-1 `70be516`).
- CURRENT_CANONICAL: `origin/feat/14d-2-plan-upgrade-requests` @ `410c2a9`.
- Canonical delta since the Batch-1 register (`ec09b6a..410c2a9`, 38 commits): 1 catalog guard (ported),
  ~10 Kashif/Catering (NOT_APPLICABLE), the rest Cloud POS/report/print-agent UX (ONLINE_ONLY) or docs.

## Commits (narrow, by domain)
| Commit | Domain |
|---|---|
| `a595fd5` EDGE: upgrade an appliance without rebuilding its local database | non-destructive schema upgrade (#16) |
| `6096c60` EDGE: close cross-system sale envelope identity | 1B §4 customer identity |
| `d6178b7` EDGE: prove outbox and config-revision races, deadlock-free lease | 1B §5+§6 |
| `02a65cc` EDGE: align current product and quick-sale contract | CATALOG-GUARD-1 (#8) + quick-sale hard-require (#17) |

## What changed
1. **Non-destructive schema upgrade** — `edge:local:schema-upgrade` (EdgeLocalSchemaUpgrader + command,
   CLI-allowlisted): forward-only, pending-only, audits protected operational tables (a shrink is a hard
   failure), records the applied edge schema generation on `edge_local_meta`, fails closed. `db-init` now
   refuses an already-bootstrapped appliance even with `--fresh`. Compatibility manifest reports
   `applied_edge_schema_version`.
2. **Catalog guard parity** — backend coercion (`stock_item -> none` when Track Stock off; `recipe`
   untouched) + frontend form sync (canonical 28500ad). Edge runtime is also fail-safe: a contradictory
   replicated row never decrements operational stock.
3. **Customer cross-system identity** — envelope customer block is explicit: walk-in `{kind: walk_in}`;
   attached customer keyed on canonical `customer_uuid` (+ snapshot name/phone); a missing uuid fails
   closed. Never a local PK. No envelope version bump; no historical row mutated.
4. **True lease concurrency** — the two-process race exposed a real deadlock in the old
   `UPDATE ... ORDER BY id LIMIT 1` lease (InnoDB 1213); rewritten to `SELECT ... FOR UPDATE SKIP LOCKED`
   + UPDATE by PK. Proven: one-row/two-workers -> one claimant; two-rows -> distinct claims; expired
   lease -> reclaimed; never duplicate ownership.
5. **Config-refresh vs paid-sale coherence** — the REAL refresh authority commits N->N+1 atomically under
   the meta lock; a sale, built in one snapshot-consistent transaction, is ALWAYS entirely one generation
   (price and stamped `config_revision` agree; the historical envelope is never retro-mixed). Post-refresh
   selling models the operational baseline cutover (in-binding baseline replacement is deliberately
   refused — overselling guard; cutover belongs to future sync).
6. **Quick-sale contract (resolved)** — an offline Quick Sale now hard-requires vehicle + waiter on direct
   pay, hold and revise, server-enforced in EdgeLocalPosService AND EdgeLocalPosController
   (`required_if`), matching the Cloud POS. No frontend/backend disagreement.
7. **Draft sync contract** — Draft/Hold stay branch-local; only settlement yields exactly one immutable
   paid envelope (`is_draft=false`, final attribution).

## Focused tests (new/updated)
EdgeLocalSchemaUpgradeMySqlTest, EdgeSyncEnvelopeContractMySqlTest, EdgeSyncRaceMySqlTest (+lease/refresh
workers), EdgeCanonicalAlignmentMySqlTest (untracked-consumer no-stock; quick-sale require), catalog
guard in ProductArchetypeContractMySqlTest; Edge POS/HTTP/outbox fixtures carry quick-sale vehicle+waiter.

## Deliberate Edge capability differences / limitations
- Post-refresh offline selling requires an operational **baseline cutover** (future sync/reconciliation);
  baseline replacement within a binding is refused by design (overselling guard). Documented, not a bug.
- Returns/refunds/card remain UNSUPPORTED_OFFLINE (compatibility contract reports it).
