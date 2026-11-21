# Edge appliance recoverable-state census

What an encrypted backup MUST capture so a replacement Branch Server loses nothing that affects money,
stock, shift, dine-in/table, KOT, printing correctness, or exactly-once sync. Reviewed against the real
Branch-Server-owned schema (edge migrations + the shared tenant tables the offline POS writes).

Classification: **BACKED_UP** (captured), **CLOUD_REDERIVABLE** (comes back from the Cloud bootstrap/config
import on box B), **SAFE_EPHEMERAL** (loss is safe; deterministically rebuilt at runtime).

| Table | Class | Why |
|---|---|---|
| `edge_local_meta` | BACKED_UP | the appliance binding (tenant/branch/device/epoch/revision) |
| `edge_local_user_credentials` | BACKED_UP | offline local auth (Argon2id, epoch-fenced) |
| `shifts` | BACKED_UP | **money** — open shift + cash reconciliation; `sales_orders.shift_id` depends on it |
| `restaurant_table_sessions` | BACKED_UP | dine-in — active table sessions; held/paid sales reference them |
| `sales_orders` | BACKED_UP | local sales **including held/draft** (`status='held'`, `is_draft`) |
| `sales_order_lines` | BACKED_UP | sale lines (held lines included) |
| `sale_payments` | BACKED_UP | money |
| `kot_batches` / `kot_batch_lines` | BACKED_UP | KOT state — so a restore does not lose/duplicate kitchen tickets |
| `print_jobs` | BACKED_UP | local print queue + printed history — a printed job must not reprint after restore |
| `edge_local_print_deliveries` | BACKED_UP | Edge print delivery state (per-printer FIFO / retry) |
| `edge_operational_stock_baselines` / `_balances` / `_movements` | BACKED_UP | **stock** — accepted selling authority + movement history |
| `edge_baseline_cutovers` | BACKED_UP | cutover audit / lineage |
| `edge_sync_outbox` | BACKED_UP | **exactly-once** — pending / leased / acknowledged / failed_permanent un-synced sales |
| config catalog: `products`, `product_variants`, `categories`, `payment_methods`, `branches`, `terminals`, `users`, `restaurant_tables`, `restaurant_floors`, `printers`, `category_printer_mappings`, units, prices, recipes, modifiers | CLOUD_REDERIVABLE | replicated from Cloud by bootstrap/config-refresh at stable Cloud ids; box B re-imports it BEFORE restore. Restore fails closed if a required reference is still absent. |
| official Cloud stock (`stock_balances`, `stock_ledgers`, `inventory_batches`), `journal_*`, `cash_bank_account_transactions` | NOT EDGE-OWNED | the Cloud is the authority; the appliance never hosts them |
| `edge_local_print_worker_state` | SAFE_EPHEMERAL | print-worker lease/heartbeat; a stale lease auto-expires and the worker re-establishes |
| `edge_auth_audit` | SAFE_EPHEMERAL | audit log only; no money/stock/exactly-once consequence |
| `edge_consumed_assertions` | SAFE_EPHEMERAL | enrollment one-time-assertion replay guard; a fresh re-pair issues a new assertion |
| `edge_local_backups` | SAFE_EPHEMERAL | the backup audit log itself (self-referential) |

## Restore ordering (fresh box B)
1. fresh Edge schema (migrations) → 2. Cloud bootstrap/config import (config catalog at stable ids) →
3. reference-integrity precheck (every config id the recoverable rows need must resolve) →
4. atomic restore of the BACKED_UP tables (parents-first) → 5. non-destructive schema upgrade if older.

A restore into a box whose config is not yet bootstrapped is REFUSED (`RESTORE_REFERENCE_UNRESOLVED`), never
forced by disabling FK checks.
