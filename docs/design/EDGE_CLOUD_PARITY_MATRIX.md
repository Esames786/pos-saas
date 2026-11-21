# EDGE ↔ CLOUD PARITY MATRIX (EDGE-CONFIG-REFRESH-1 / EDGE-COMPATIBILITY-CONTRACT-1)

Classification of recent Cloud features against the Branch Server (Edge) runtime, grounded in the
actual bootstrap/refresh sections (`EdgeLocalBootstrapImporter::PLAN`), the Edge local POS runtime
(`EdgeLocalPosService`), and the offline restrictions section the bootstrap ships.

Vocabulary:

| Class | Meaning |
|---|---|
| `CLOUD_ONLY` | Runs only in the Cloud; the Branch Server never needs it. |
| `CONFIG_REPLICATED` | Carried to the appliance by the bootstrap/refresh config sections (now revisioned by EDGE-CONFIG-REFRESH-1). |
| `OFFLINE_TRANSACTIONAL` | Executes locally on the Branch Server (shared or Edge-specific code paths). |
| `EDGE_SOFTWARE_REQUIRED` | Behavior lives in the Edge build itself; changing it requires shipping a new Edge artifact (the compatibility contract classifies stale builds as `software_update_required`). |
| `UNSUPPORTED_OFFLINE` | Explicitly refused on the Branch Server (fail-closed; `feature_unavailable_offline` in the compatibility contract — never silent partial behavior). |

## Matrix

| Cloud feature (recent work) | Class | Grounding |
|---|---|---|
| Shift explicit counted cash ("no close may invent a count") | `OFFLINE_TRANSACTIONAL` + `EDGE_SOFTWARE_REQUIRED` | Edge shift open/close reuses the SAME `ShiftService` (locked open-shift revalidation); counted-cash semantics ship inside the Edge build — a rule change needs a new artifact. |
| `business_date` | `OFFLINE_TRANSACTIONAL` | Derived from the locked open shift on Edge exactly as on Cloud; frozen at first hold, never rolled forward on revision (`EdgeLocalPosService`). |
| Zero-quantity line guards | `OFFLINE_TRANSACTIONAL` + `EDGE_SOFTWARE_REQUIRED` | Edge filters `quantity > 0` at sale/hold time (same refusal semantics as the Cloud guard). |
| Manual discount + per-branch approval mode | `UNSUPPORTED_OFFLINE` (today) | Edge refuses any request carrying a discount/promotion (no proven offline approval wiring). `branches.manual_discount_approval_mode` is deliberately NOT exported yet; when offline discounts are built it becomes `CONFIG_REPLICATED` policy + `EDGE_SOFTWARE_REQUIRED` enforcement. |
| Customer required on delivery POS orders | `CLOUD_ONLY` | Customers are not a bootstrap section; delivery orders (with charges/customers) are refused on the Branch Server. |
| Delivery charge (incl. return-with-charge) | `UNSUPPORTED_OFFLINE` | Edge rejects any non-zero `delivery_charge_amount`; `branches.default_delivery_charge` is not exported. |
| Held/KOT cancellation policies (split policy, manager codes) | `CONFIG_REPLICATED` + `OFFLINE_TRANSACTIONAL` | `held_kot_cancellation_approval_mode` + line-level mode ship in the `branch` section (and in `restrictions`); enforcement + offline manager re-auth (`verifyManager`) run locally. |
| Cancel/void reasons | `CONFIG_REPLICATED` | `void_reasons` section; refresh tombstones removed reasons (`is_active = 0`). |
| Terminal scope / order-type scope (own-terminal shifts, per-user order types) | `CONFIG_REPLICATED` + `OFFLINE_TRANSACTIONAL` | `terminals`, `users.allowed_order_types` / `default_order_type` / `default_terminal_id` ship; the Edge POS enforces terminal/branch binding locally. |
| Combos / modifiers (incl. cart-preview combos) | `CONFIG_REPLICATED` + `OFFLINE_TRANSACTIONAL` | `combos`, `combo_components`, `modifier_groups`, `modifiers` sections (coherence-checked); sale-time expansion runs locally. |
| Printer mappings / receipt & KOT layouts (column layout, paper size, per-category routing) | `CONFIG_REPLICATED` + `OFFLINE_TRANSACTIONAL` | `printers`, `category_printer_mappings` (branch-or-GLOBAL parity), `terminal_printer_settings`, `receipt_layout_settings` sections; the lease-safe local print worker executes them. Config refresh now propagates changes without re-imaging. |
| Cloud print agent (installer versioning, keep-awake) | `CLOUD_ONLY` | The Windows print agent serves Cloud-mode branches; the Branch Server uses its own local print worker. |
| Delivery rider roster | `CONFIG_REPLICATED` | `delivery_riders` section (tombstoned on refresh). |
| Delivery rider reassignment flow (audit trail) | `CLOUD_ONLY` | Delivery orders do not execute on the Branch Server. |
| Returns / refund rules (refund method required, return accounting) | `UNSUPPORTED_OFFLINE` | `restrictions.blocked_capabilities` includes `returns_against_cloud`; deliberately NOT implemented offline this phase. |
| Card / wallet / bank / customer-credit payments | `UNSUPPORTED_OFFLINE` | `restrictions.allowed_payment_types = [cash]`; the Edge POS refuses everything else. |
| Sales reports / NET-SALES reconciliation / Daily Closing aggregation | `CLOUD_ONLY` | No reporting surface in the Edge route allowlist; Daily Closing aggregates in the Cloud after sync (future). |

## How parity moves between classes

1. **Config changes** flow through EDGE-CONFIG-REFRESH-1: a Cloud edit mints a new monotonic
   `config_revision`; the appliance applies it non-destructively (upsert + tombstone).
2. **Behavior changes** require a new Edge artifact; EDGE-COMPATIBILITY-CONTRACT-1 lets the Cloud
   classify a device as `software_update_required` (never silently mixed behavior).
3. **Transactional history** (sales made offline) waits for OFFLINE-SYNC-ENGINE-1 — NOT started.
