# Manufacturing Finance Posting — Design & Gap Audit

> **Status: DESIGN / DOCUMENTATION ONLY (MFG-FIN-DESIGN-1).**
> Nothing in this document is implemented. No posting code, no migrations, no
> stock-ledger entries, no journal entries, no changes to existing finance /
> inventory / POS / sales / purchase services. This file specifies *how* the
> future Manufacturing accounting/posting layer should work so it can be built
> safely, phase by phase, without disturbing anything that is already live.
>
> Companion file: [`MANUFACTURING_FINANCE_BACKLOG.md`](MANUFACTURING_FINANCE_BACKLOG.md)
> (the running backlog of finance work). This document is the detailed design;
> the backlog is the checklist.

---

## 1. Executive Summary

The manufacturing operational foundation (MANUF-1…MANUF-10) is live and is
**tracking-only**: it plans, requests, records and reports production activity
but posts **nothing** to inventory or the General Ledger (GL).

This design describes a future, **opt-in** accounting layer that will translate
manufacturing events into:

1. **Stock movements** — raw materials leave stock, finished goods enter stock,
   scrap/rejections leave stock — recorded in the existing `stock_ledgers` /
   `stock_balances` tables via the existing `InventoryService`.
2. **Double-entry journals** — `Dr/Cr` pairs recorded in the existing
   `journal_entries` / `journal_lines` tables via the existing `JournalService`.

The system already has a mature, idempotent, reversible posting engine
(`JournalService`, `JournalPostingService`, `InventoryService`). **The
manufacturing posting layer should be a thin set of new event translators on top
of that engine — not a new engine.** This is the single most important design
decision: *reuse, don't reinvent.*

Core promises:

- **Off by default.** No tenant gets manufacturing GL/stock postings until they
  explicitly enable it and map the required accounts.
- **Idempotent.** Posting the same document twice never double-posts.
- **Reversible.** "Unpost" creates opposite entries; it never deletes.
- **Balanced.** Trial Balance stays at `difference = 0`; P&L net profit always
  equals Balance Sheet current earnings.
- **Non-invasive.** POS, Sales, Purchasing, Inventory and existing Finance keep
  working exactly as they do now.

---

## 2. Current Manufacturing Foundation

| # | Module | Table(s) | Key fields available for posting |
|---|---|---|---|
| 1 | Manufacturing Customers | `manufacturing_customers` | (master data only — no posting) |
| 2 | Production Orders | `production_orders` | `branch_id`, `product_id` (finished good), `planned_quantity`, `produced_quantity`, `status` |
| 3 | Bill of Materials | `manufacturing_boms`, `manufacturing_bom_lines` | finished product + component products + quantities (the cost recipe) |
| 4 | Material Requisition (MRC) | `material_requisitions`, `material_requisition_lines` | `component_product_id`, `required_quantity`, `issued_quantity` |
| 5 | WIP Jobs | `wip_jobs`, `wip_job_lines` | `branch_id`, `finished_product_id`, `planned_quantity`, `completed_quantity`, material snapshot |
| 6 | Finished Goods | `finished_good_receipts`, `finished_good_receipt_lines` | `finished_product_id`, `received/accepted/rejected/scrap_quantity`, batch/lot |
| 7 | Scrap / Hard Waste | `manufacturing_scrap_records`, `manufacturing_scrap_lines` | `scrap_type`, `source_type`, `total/recoverable/disposed_quantity`, `estimated_loss_value` |
| 8 | Rejections | `manufacturing_rejection_records`, `manufacturing_rejection_lines` | `rejection_type`, `disposition`, `total/rework/scrap/accepted_after_review/disposed_quantity`, `estimated_loss_value` |
| 9 | Consumption | `manufacturing_consumption_records`, `manufacturing_consumption_lines` | `component_product_id`, `consumed_quantity`, `wastage_quantity`, `estimated_unit_cost`, `estimated_total_value` |
| 10 | Production Reports | (read-only views) | — |

All quantities exist; **all of these reference the same `products` and `branches`
tables that inventory uses**, which is what makes posting feasible. What is
missing is *cost capture* and *posting state* (see §3 and §20).

---

## 3. Current Finance / Inventory Architecture Observed

### 3.1 Chart of Accounts (`App\Models\Tenant\Account`)
Fields: `code`, `name`, `type` (`asset|liability|equity|income|expense`),
`normal_balance` (`debit|credit`), `parent_id`, `is_system`, `is_active`.
Accounts are referenced **by code** in posting code.

Existing relevant codes (from `DefaultChartOfAccountsSeeder`):

```
1000 Assets            1400 Inventory Asset      2100 Accounts Payable
1100 Cash on Hand      1500 Undeposited Funds    2200 Sales Tax Payable
1200 Bank Accounts     1300 Accounts Receivable  3200 Retained Earnings
4110 Retail Sales      4120 Restaurant Sales     4200 Sales Discounts
5000 Cost of Goods Sold  5100 Product COGS  5200 Recipe / Ingredient COGS
6000 Expenses (6100 Rent … 6800 Misc)
```

> There is currently **one** inventory asset account (`1400`) and **no** WIP,
> Finished Goods, Raw Material, Scrap-expense, Variance, or Direct-Labour /
> Overhead accounts. These are gaps (§5, §20).

### 3.2 GL posting engine (`App\Services\Finance\JournalService`)
- `post($sourceType, $sourceId, $sourceNo, $description, $entryDate, $lines, $userId): JournalEntry`
  - **Idempotent:** if a non-reversal `posted` entry already exists for
    `(source_type, source_id)`, it is returned unchanged (no double-post).
  - Lines accept `account_id` **or** `account_code`; each line has `branch_id`,
    `description`, `debit`, `credit`.
  - **Balance-enforced:** total debit must equal total credit, ≥ 2 lines, no
    negative amounts, no line with both debit and credit. Throws otherwise.
  - Runs inside `DB::connection('tenant')->transaction()`.
- `reverse($entry, $reason, $userId): JournalEntry`
  - Creates a new entry with `reversed_entry_id` set and debits/credits flipped,
    `source_type` suffixed `_reversal`, `is_reversal = true`.
  - **Idempotent** (returns existing reversal); **never deletes** the original.
- `findPostedForSource($sourceType, $sourceId)` — the idempotency lookup.
- `accountId($code)` — resolve active account by code (throws if missing/inactive).

`JournalEntry` columns: `entry_no`, `entry_date`, `source_type`, `source_id`,
`source_no`, `description`, `status`, `total_debit`, `total_credit`,
`posted_by_user_id`, `posted_at`, `is_reversal`, `reversed_entry_id`.
`JournalLine` columns: `account_id`, `branch_id`, `description`, `debit`,
`credit`, `sort_order`.

### 3.3 Event translators (`App\Services\Finance\JournalPostingService`)
Per-event methods that build balanced `$lines` and call `JournalService::post()`.
**Crucially, every method is "safe":** it is wrapped in `try/catch`, and on any
problem (missing account, unmapped event) it calls `report($e)` and returns
`null` — it never throws into the operational flow that triggered it. This is the
exact contract the manufacturing translators must follow.

Existing examples to copy:
- `postPaidSale()` — `Dr cash/bank … / Cr revenue + tax + service + tips` and an
  independently-balanced COGS pair `Dr 5100 / Cr 1400`.
- `postSalesReturn()` — reverses revenue + COGS (restock).
- `postPurchaseBill()` — `Dr 1400 Inventory / Cr 2100 AP`.
- `reverseForSource($sourceType, $sourceId, $reason, $userId)` — void flow.

### 3.4 Inventory engine (`App\Services\Inventory\InventoryService`)
- `postIn(branch, product, variant, qty, unitCost, movementType, ref…)` — adds
  stock; updates `stock_balances.average_cost` via **weighted (moving) average**.
- `postOutFefo(branch, product, variant, qty, movementType, ref…)` — issues stock
  **first-expiry-first-out**, valuing each slice at that balance's `average_cost`;
  **throws on insufficient stock** (negative stock currently disallowed).
- `transfer(…)` — branch-to-branch out+in.
- `ensureStockTracked($product)` — throws unless `product.is_stock_tracked`.
- Writes a `stock_ledgers` row (`movement_type`, `direction`, `quantity`,
  `unit_cost`, `total_cost`, `balance_after`, `reference_type/id/no`) and updates
  the matching `stock_balances` row.

`StockLedger` columns: `branch_id`, `product_id`, `product_variant_id`,
`inventory_batch_id`, `movement_type`, `direction`, `quantity`, `unit_cost`,
`total_cost`, `balance_after`, `reference_type`, `reference_id`, `reference_no`,
`notes`, `created_by_user_id`.

> **Costing method observed: moving weighted average per (branch, product,
> variant, batch).** Manufacturing material issues must value raw materials at
> this same `average_cost`, exactly as sales COGS does — this guarantees
> consistency between stock value and GL inventory value.

### 3.5 Reporting (must stay balanced)
- `FinancialExportService::trialBalance($asOf, $branchId)` → `['difference' => 0]`.
- `ProfitLossService::statement([...])` → `net_profit`.
- `BalanceSheetService::statement([...])` → `current_earnings`.
- `BranchProfitLossService` — per-branch P&L.

**Invariant for every future change:** `tb_diff = 0` and
`abs(P&L.net_profit − BalanceSheet.current_earnings) <= 0.01`.

### 3.6 Settings pattern observed
Settings are stored as dedicated per-domain tables/models (e.g.
`ServiceChargeSetting` with an optional `branch_id` for branch override).
Manufacturing posting settings should follow the same shape (§5).

---

## 4. Posting Principles

1. **No automatic accounting unless explicitly enabled** (per tenant).
2. **Tenant-specific** — every setting and posting lives in the tenant DB.
3. **Reversible** — unpost = opposite journal + opposite stock movement, never delete.
4. **Idempotent** — re-posting is a no-op that returns the existing result.
5. **Traceable stock** — every `stock_ledgers` row carries `reference_type` +
   `reference_id` + `reference_no` pointing at the source manufacturing document.
6. **Traceable GL** — every `journal_entries` row carries `source_type` +
   `source_id` + `source_no` pointing at the same source document.
7. **Trial Balance always balances** — only ever post balanced pairs.
8. **Non-invasive** — never modify existing POS/Sales/Purchase/Inventory/Finance
   posting paths or math; manufacturing adds *new* `movement_type`s and
   `source_type`s only.

### Idempotency, explained
"Post" must be safe to click twice.

- **GL:** `JournalService::post()` already keys on `(source_type, source_id)`. A
  second call returns the first entry — no double journal. Manufacturing
  translators inherit this for free by using a unique `source_type` per event
  (e.g. `mfg_consumption`) and the document id as `source_id`.
- **Stock:** `stock_ledgers` has **no** such guard built in. The manufacturing
  layer must add one. Recommended: before issuing/receiving, check for an existing
  `stock_ledgers` row with the same `(reference_type, reference_id, movement_type)`
  (and line id where line-level), and skip if present. Equivalent to the
  `CashBankAccountTransaction` "exists?" guard already used in
  `postSalesCashBankMovement()`.
- **Document state:** the source document carries a `posting_status`
  (`unposted | posted | reversed`). The Post action:
  - if `unposted` → post, set `posted`;
  - if `posted` → show "already posted" (no-op);
  - if `reversed` → allow re-post (new posting) only via an explicit action.

Re-posting policy recommendation: **block** by default (status guard), with a
separate **reverse** action that must run before any re-post.

---

## 5. Required Accounting Settings

A new tenant-level settings record maps each posting role to a CoA account.
Recommended new table `manufacturing_posting_settings` (one active row per tenant,
optional `branch_id` for later override), following the `ServiceChargeSetting`
shape.

| Setting (role) | Suggested new CoA code | Account type | Exists today? |
|---|---|---|---|
| Raw Material Inventory | `1410` | asset | **new** |
| WIP Inventory | `1420` | asset | **new** |
| Finished Goods Inventory | `1430` | asset | **new** |
| Manufacturing Overhead (applied/clearing) | `1490` or `5320` | asset/clearing or expense | **new** |
| Direct Labour | `6210` (under 6200) | expense | **new** |
| Scrap / Waste Expense | `6900` | expense | **new** |
| Rework Expense | `6910` | expense | **new** |
| Production Variance | `5300` (under 5000 COGS) | expense | **new** |
| Manufactured-Goods COGS | `5310` (or reuse `5100`) | expense | reuse/new |
| Inventory Adjustment | `5800`/`6920` | expense | **new** |

> `1400 Inventory Asset`, `5100 Product COGS`, `5000 COGS`, `2100 AP` already
> exist and stay as-is. The manufacturing accounts above are sub-accounts /
> siblings; adding them does not change any existing posting.

**Scope of settings (recommended phased):**
- **Tenant-level first** — one mapping per tenant. *Simplest, safe first version.*
- **Branch-level override later** — optional `branch_id` row overrides the tenant
  default (reuse the `ServiceChargeSetting` branch pattern).
- **Product / category-level override later** — only if a tenant needs per-product
  WIP/FG accounts; most do not. Defer.

**Recommended first version:** **tenant-level account mapping only.** Branch and
product/category overrides are explicitly deferred.

---

## 6. Required Inventory Settings

Before any manufacturing stock posting can run for a document:

1. **Raw-material products must be stock-tracked** (`product.is_stock_tracked`).
2. **Finished-goods products must be stock-tracked.**
3. **BOM components must map to stock-tracked products** (they already reference
   `products`; the flag must be on).
4. **Units consistent or convertible** — consumption/BOM `unit_id` must match the
   product's stocking unit, or a conversion must exist. (Unit-conversion infra
   exists for kitchen inventory and can be reused; otherwise require same unit.)
5. **Negative-stock policy must be explicit.** `InventoryService` currently
   **blocks** negative stock (throws). Manufacturing should keep that default:
   if issuing more than on-hand and negative stock is disabled → **block the post**.
6. **Batch/lot policy.** If a product `requires_batch`/`has_expiry`, manufacturing
   lines should capture batch/lot (consumption, scrap, rejection, FG lines already
   have `batch_no`/`lot_no`).

**Safe behaviour when a precondition is missing:**

- Missing account mapping → **Post button disabled** (with a clear reason).
- Product not stock-tracked → **posting blocked** (cannot value/move it).
- Insufficient stock + negative stock disabled → **posting blocked**.
- Quantities not validated / document not in a postable status → **posting blocked**.

---

## 7. Manufacturing Posting Events (design only — do not implement)

Defined in the order they occur in a production cycle.

### Event 1 — Material Issue / Consumption
- **Trigger:** a Consumption record is *posted* (`manufacturing_consumption_records`).
- **Stock:** raw-material stock **decreases** (`postOutFefo`, movement
  `manufacturing_material_issue`), valued at `stock_balances.average_cost`.
- **GL:** `Dr WIP Inventory (1420) / Cr Raw Material Inventory (1410)` for the
  issued value.
- **Source:** `manufacturing_consumption_records` + `manufacturing_consumption_lines`
  (`component_product_id`, `consumed_quantity`).

### Event 2 — Finished Goods Receipt
- **Trigger:** a Finished Goods receipt is *accepted/posted* (`finished_good_receipts`).
- **Stock:** finished-goods stock **increases** (`postIn`, movement
  `manufacturing_fg_receipt`), valued at the **WIP unit cost** (see §11/§16).
- **GL:** `Dr Finished Goods Inventory (1430) / Cr WIP Inventory (1420)`.
- **Source:** `finished_good_receipts` + `finished_good_receipt_lines`.

### Event 3 — Scrap
- **Trigger:** a Scrap record is *posted* (`manufacturing_scrap_records`).
- **Stock:** stock **decreases** for the scrapped product (movement
  `manufacturing_scrap`).
- **GL:** `Dr Scrap / Waste Expense (6900) / Cr (WIP 1420 | Raw Material 1410 |
  Finished Goods 1430)` — credit account chosen by `source_type`
  (`wip` → 1420, `finished_goods` → 1430, `manual`/raw → 1410).

### Event 4 — Rework (Rejection disposition = rework)
- **Trigger:** a Rejection with `disposition = rework` and an approved rework cost.
- **GL:** `Dr WIP Inventory (1420) or Rework Expense (6910) / Cr (Raw Material
  1410 | Direct Labour 6210 | Overhead 1490)`.
- **Stock:** additional material issue if rework consumes new components
  (reuses Event 1 mechanics).

### Event 5 — Variance (Production order / WIP close)
- **Trigger:** a Production Order / WIP job is *closed*.
- **GL:** clear residual WIP to variance — `Dr/Cr Production Variance (5300) /
  Cr/Dr WIP Inventory (1420)` so WIP for that job nets to zero.

### Event 6 — COGS on sale of finished goods
- **Trigger:** sale of a finished good through POS/Sales (existing flow).
- **GL:** `Dr COGS (5100/5310) / Cr Finished Goods Inventory (1430)`.
- **Note:** sales **revenue** posting already belongs to the Sales/POS flow
  (`postPaidSale`). Manufacturing only needs the *cost* side to credit FG
  Inventory (1430) instead of generic Inventory (1400) **when the sold product is
  a manufactured finished good**. This is the one place manufacturing must
  integrate with an existing flow — handle with care (§16).

> **None of these events are implemented in this sprint.** They are specified for
> the phased roadmap (§21).

---

## 8. Journal Entry Design

All journals go through `JournalService::post()` with a manufacturing-specific
`source_type` and the document id as `source_id` (idempotency key). Branch is set
on every line.

| Event | `source_type` | Debit | Credit |
|---|---|---|---|
| Material issue | `mfg_consumption` | WIP Inventory `1420` | Raw Material Inventory `1410` |
| FG receipt | `mfg_fg_receipt` | Finished Goods `1430` | WIP Inventory `1420` |
| Scrap | `mfg_scrap` | Scrap Expense `6900` | WIP/RM/FG (by source) |
| Rework | `mfg_rework` | WIP `1420` / Rework Exp `6910` | RM `1410` / Labour `6210` / OH `1490` |
| Variance | `mfg_variance` | Variance `5300` ↔ WIP `1420` | (whichever balances) |
| COGS (mfg FG sale) | (extends sales COGS) | COGS `5100`/`5310` | Finished Goods `1430` |

### Worked examples

**Example 1 — Raw material issued to WIP (value 10,000):**
```
Dr  WIP Inventory (1420)            10,000
Cr  Raw Material Inventory (1410)            10,000
```

**Example 2 — Finished goods received (value 15,000):**
```
Dr  Finished Goods Inventory (1430) 15,000
Cr  WIP Inventory (1420)                     15,000
```

**Example 3 — Scrap from WIP (value 1,000):**
```
Dr  Scrap / Waste Expense (6900)     1,000
Cr  WIP Inventory (1420)                      1,000
```

**Example 4 — Sale of finished goods (cost 8,000):**
```
Dr  COGS (5100 / 5310)               8,000
Cr  Finished Goods Inventory (1430)           8,000
```

**Notes**
- Sales **revenue** is already posted by `JournalPostingService::postPaidSale()` /
  `postCreditSale()`. Manufacturing must **not** duplicate revenue.
- The only manufacturing-specific change to the sale path is *which inventory
  account the COGS credit hits* (1430 for manufactured FG vs 1400 for purchased
  goods). Until that integration is built (Phase F), manufactured FG sold via POS
  simply behaves like today (credits 1400) — no double counting, but FG/inventory
  sub-account accuracy is deferred.

---

## 9. Stock Ledger Design

New `movement_type` values (added by manufacturing, alongside existing
`transfer_in/out`, sale, purchase, adjustment types):

```
manufacturing_material_issue       (out  — raw material → WIP)
manufacturing_fg_receipt           (in   — finished goods)
manufacturing_scrap                (out  — scrapped goods)
manufacturing_rework_issue         (out  — extra material for rework)
manufacturing_variance_adjustment  (in/out — closing adjustment, rare)
manufacturing_cogs_issue           (out  — FG sold; or reuse sales movement)
manufacturing_reversal             (mirror of any of the above on unpost)
```

For each movement the manufacturing layer records (all columns already exist on
`stock_ledgers`):

| Field | Source |
|---|---|
| `movement_type` | one of the above |
| `direction` | `in` / `out` |
| `product_id` | `component_product_id` / `finished_product_id` |
| `branch_id` | source document branch |
| `quantity` | line quantity |
| `unit_cost` | `average_cost` (issues) or WIP unit cost (FG receipt) |
| `total_cost` | quantity × unit_cost |
| `balance_after` | computed by `InventoryService` |
| `reference_type` | e.g. `mfg_consumption_line` |
| `reference_id` | the source line id |
| `reference_no` | document number (e.g. `CONS-000123`) |
| `created_by_user_id` | the posting user |
| reversal link | via a new column or a `manufacturing_reversal` row referencing the original (see §20) |

**Idempotency (stock):** before posting, check for an existing `stock_ledgers`
row with the same `(reference_type, reference_id, movement_type)`; skip if found.

---

## 10. WIP Accounting Design

WIP Inventory (`1420`) is a **clearing account per production order / WIP job**:

- **Debited** by material issues (Event 1), rework (Event 4), and later labour /
  overhead absorption.
- **Credited** by finished-goods receipts (Event 2) and closing variance (Event 5).
- **Target:** when a job is complete and closed, its WIP balance should be ~0; any
  residual is posted to Production Variance (5300).

**Gap:** there is no field today that accumulates a WIP job's cost. To value FG at
receipt (Event 2), WIP must track accumulated cost. Recommended new column
`wip_jobs.accumulated_cost` (decimal) maintained by Event 1/4 postings, and a
derived **WIP unit cost** = `accumulated_cost / completed_quantity` used by Event 2.

**Safe first version:** material-only WIP (no labour/overhead absorption). FG cost
= accumulated material cost. Labour/overhead absorption is a later phase.

---

## 11. Finished Goods Accounting Design

- FG receipt (Event 2) moves value **WIP → Finished Goods Inventory** and adds
  stock for `finished_product_id`.
- **FG unit cost** = WIP unit cost at receipt (see §10). The FG receipt tables
  carry **no cost column today** (gap §20) — the receipt must store the applied
  `unit_cost` / `total_cost` so the later sale (Event 6) can credit FG at the
  correct cost and so stock valuation is consistent.
- Acceptance/quality split: only `accepted_quantity` should normally enter
  sellable FG stock; `rejected_quantity` / `scrap_quantity` route to
  Rejection/Scrap events.

---

## 12. Consumption / Raw Material Issue Design

- Consumption is the **authoritative material-issue document** (Event 1).
- Value each line at the **live `stock_balances.average_cost`** for that
  `(branch, component_product)` — **not** the stored `estimated_unit_cost`
  (which stays informational). This matches how sales COGS values stock.
- Issue stock with `postOutFefo` (respects batch/expiry); block if insufficient
  stock and negative stock disabled.
- `wastage_quantity` may either be issued to WIP and then scrapped, or expensed
  directly to Scrap — **open decision (§22)**; recommend: issue full
  `consumed_quantity` to WIP, handle wastage via a Scrap document for clarity.
- Do **not** mutate WIP/MRC line quantities on posting (consumption remains a
  separate document, as today); only WIP `accumulated_cost` is updated.

---

## 13. Scrap Accounting Design

- Posting a scrap record (Event 3) removes stock for the scrapped product and
  expenses the loss.
- Credit account by `source_type`: `wip` → WIP (1420), `finished_goods` → FG
  (1430), `manual`/raw → Raw Material (1410). Debit → Scrap/Waste Expense (6900).
- Value = quantity × relevant average/WIP cost. `estimated_loss_value` stays
  informational; the posted value is the costed amount.
- `recoverable_quantity` (scrap that can be reused) should **not** be expensed —
  open decision whether to keep it in stock or move to a "recovered materials"
  product (§22).

---

## 14. Rejection / Rework Accounting Design

- **Rejection is a QC decision, not automatically a posting.** Posting depends on
  `disposition`:
  - `rework` → Event 4 (capture rework cost; may consume more materials/labour).
  - `scrap` → create/Link a Scrap document → Event 3.
  - `accept_after_review` → no posting (goods accepted).
  - `disposed` → expense like scrap.
- Rework cost: `Dr WIP (1420)` (re-add to job) **or** `Dr Rework Expense (6910)`
  (period cost) — **open decision (§22)**; recommend WIP if the reworked unit is
  still going to be sold, Rework Expense if written off.
- Recording a rejection must continue to **not** change WIP / FG / PO status until
  posting is built.

---

## 15. Variance Accounting Design

- On Production Order / WIP **close** (Event 5), clear residual WIP for the job to
  Production Variance (5300):
  - WIP residual debit balance (under-absorbed) → `Dr Variance / Cr WIP`.
  - WIP residual credit balance (over-absorbed) → `Dr WIP / Cr Variance`.
- This guarantees each completed job's WIP nets to zero and the variance is
  visible in P&L (under COGS).
- Standard-vs-actual costing (BOM standard cost roll-up) is a **later** costing
  phase; first version uses actual moving-average cost only.

---

## 16. COGS Design

- The **only** integration point with an existing live flow.
- Today: `postPaidSale()` posts COGS `Dr 5100 / Cr 1400` from line `cost_total`.
- Future (Phase F): when the sold product is a **manufactured finished good**,
  the COGS credit should hit **Finished Goods Inventory (1430)** instead of
  generic Inventory (1400), and the cost should be the FG receipt cost (§11).
- **Safety:** this must be additive and guarded — detect "is manufactured FG" via
  a product flag/type; if not set, behave exactly as today. Never change the
  revenue side. Re-run finance integrity after any change here.

---

## 17. Reversal / Cancellation Design

- **GL reversal:** `JournalService::reverse()` already creates a flipped,
  idempotent reversal entry and never deletes — manufacturing reuses it via a
  `JournalPostingService::reverseForSource('mfg_consumption', $id, …)`-style call.
- **Stock reversal:** post a mirror `stock_ledgers` movement
  (`manufacturing_reversal`, opposite `direction`, same quantity/cost) referencing
  the original line; guarded so it runs once.
- **Document state:** set `posting_status = reversed`; keep both the original and
  reversal rows. A subsequent re-post is a new posting (new entries), only allowed
  via explicit action.
- Never hard-delete a posted journal or stock movement.

---

## 18. Permissions and Safety Controls

**Controls**
- Post button visible only with the relevant post permission.
- Unpost/reverse is a **separate** permission.
- Posting requires: all account mappings present, stock settings satisfied,
  quantities validated, document in a postable status.
- Posting blocked if the document is cancelled/closed incorrectly, or already posted.
- Every posting records user + time + source (GL `posted_by_user_id`/`posted_at`;
  stock `created_by_user_id`; document `posted_by_user_id`/`posted_at`).
- Reversal creates opposite entries; never deletes.

**Proposed permissions** (registered via `system:routes-sync`, granted to Owner;
`permission:cache-reset` after grant — same pattern as all manufacturing perms):

```
tenant.manufacturing.posting.settings.view
tenant.manufacturing.posting.settings.update
tenant.manufacturing.consumption.post
tenant.manufacturing.consumption.reverse
tenant.manufacturing.finished-goods.post
tenant.manufacturing.finished-goods.reverse
tenant.manufacturing.scrap.post
tenant.manufacturing.scrap.reverse
tenant.manufacturing.rejections.post
tenant.manufacturing.rejections.reverse
tenant.manufacturing.production-orders.close-post
```

*(Not implemented in this sprint.)*

---

## 19. Demo Data / Testing Plan

When posting is built, validate on `financedemo` (current healthy counts:
mfg_customers 3, production_orders 3, boms 2, mrcs 2, wip_jobs 2, finished_goods 2,
scrap 2, rejections 2, consumption 2):

1. Ensure component + finished products are `is_stock_tracked` with opening stock.
2. Map the manufacturing accounts (settings).
3. Post a Consumption → assert raw-material stock down, WIP up (1420 debit),
   balanced JE, `tb_diff = 0`.
4. Post a FG receipt → assert FG stock up, WIP down, balanced JE, `tb_diff = 0`.
5. Post a Scrap → assert stock down, expense up, `tb_diff = 0`.
6. Reverse each → assert stock + GL net to zero, `tb_diff = 0`.
7. Re-post (idempotency) → assert no double entry.
8. After every step: `tb_diff = 0` and `pl_vs_bs = OK`.

Each posting must be **transaction-wrapped** and verified with the finance
integrity check (§ verification) before and after.

---

## 20. Migration / Schema Gap List

> **No migrations are added in this sprint.** This is the list the build phase
> will need.

**Must-have before posting**
- `manufacturing_posting_settings` table (tenant-level account mappings + enable flag; optional `branch_id`).
- New CoA accounts: `1410` Raw Material, `1420` WIP, `1430` Finished Goods,
  `5300` Production Variance, `6900` Scrap Expense (+ `6910` Rework if used) —
  seeded idempotently, `is_system = true`.
- **Posting-state columns** on each postable document
  (`manufacturing_consumption_records`, `finished_good_receipts`,
  `manufacturing_scrap_records`, `manufacturing_rejection_records`,
  `production_orders`):
  `posting_status` (`unposted|posted|reversed`), `posted_at`, `posted_by_user_id`,
  `journal_entry_id` (nullable FK), `reversed_of_id` (self/nullable).
- **WIP cost accumulation:** `wip_jobs.accumulated_cost` (and a way to derive WIP
  unit cost).
- **FG cost capture:** `finished_good_receipts.unit_cost` + `total_cost`
  (applied WIP cost at receipt).
- Stock-ledger reversal linkage: either `stock_ledgers.reversal_of_id` (nullable
  self FK) **or** rely on a `manufacturing_reversal` movement referencing the
  original — pick one in Phase B.

**Nice-to-have later**
- Branch-level / product-level account overrides.
- `manufacturing` movement-source enum formalised on stock ledger.
- Standard-cost columns on BOM for actual-vs-standard variance.
- Manufacturing overhead / labour absorption rates.

**Not required**
- Per-line journal links (document-level link is enough).
- New reporting tables — existing GL + stock ledger already support reconciliation
  reports; Production Reports (MANUF-10) can add finance columns read-only.

---

## 21. Implementation Roadmap

| Phase | Scope | Files likely touched | Tables likely needed | Key risk | Verification | Expected TB |
|---|---|---|---|---|---|---|
| **A** | Settings UI + validation only (map accounts, enable flag) — **no posting** | new `ManufacturingPostingSettingsController` + views, `routes/tenant.php`, `TenantProvisioner` (perms), CoA seeder (new accounts) | `manufacturing_posting_settings`, new CoA rows | none (no posting) | settings save/load; `tb_diff=0` unchanged | `0` |
| **B** | Posting infrastructure: idempotency, source linking, posting-state columns, reversal plumbing — **still no auto-posting** | new `ManufacturingPostingService`, posting-state migrations | posting-state columns on all postable docs; WIP/FG cost columns | schema only; keep nullable/backfill safe | migrate on financedemo; `tb_diff=0` | `0` |
| **C** | Consumption posting (`Dr WIP / Cr Raw Material`) + stock issue | `ManufacturingPostingService`, ConsumptionController (post/reverse actions), views | (uses B columns) | stock valuation accuracy; insufficient-stock block | post/reverse/idempotency on demo | `0` |
| **D** | Finished Goods posting (`Dr FG / Cr WIP`) + stock receipt at WIP cost | FG controller, posting service | FG cost columns (from B) | WIP unit-cost derivation | post/reverse; FG stock + value | `0` |
| **E** | Scrap + Rejection + Rework posting | Scrap/Rejection controllers, posting service | (uses B) | source-type → account mapping; rework decision | post/reverse each | `0` |
| **F** | COGS integration with Sales/POS for manufactured FG | careful additive change to sales COGS credit account | product "is manufactured FG" flag | **must not change revenue or existing COGS for purchased goods** | full sales + finance integrity | `0` |
| **G** | Production variance + order/WIP closing | PO/WIP close actions, posting service | (uses B) | residual WIP → variance | close job; WIP nets ~0 | `0` |
| **H** | Manufacturing financial reports / reconciliation (stock↔GL) | reports controller/service (read-only) | none | reporting only | reconcile stock value to 1410/1420/1430 | `0` |

**Hard rule for every phase:** add new isolated posting paths behind the
enable-flag; never alter existing POS/Sales/Purchase/Inventory/Finance posting or
math; re-run `tb_diff=0` / `pl_vs_bs=OK` after each change.

---

## 22. Risks and Open Decisions

1. **WIP cost source.** Use moving-average actual cost first (recommended) vs BOM
   standard cost. → Start actual; add standard-cost variance later.
2. **Wastage handling in consumption.** Issue full consumed qty to WIP and scrap
   separately (recommended) vs split issue/wastage at consumption time.
3. **Rework destination.** `Dr WIP` (reworked unit will be sold) vs
   `Dr Rework Expense` (write-off). → make it a per-disposition choice.
4. **Recoverable scrap.** Keep in stock vs move to a "recovered materials"
   product. → defer; first version expenses non-recoverable only.
5. **Manufactured-FG COGS account.** Reuse `5100` vs new `5310`; and how to flag a
   product as "manufactured FG". → needs a product flag/type (Phase F prerequisite).
6. **Labour & overhead absorption.** Excluded from the safe first version; FG cost
   = material cost until a costing phase adds absorption.
7. **Negative stock.** Keep the current hard block (recommended) vs allow with a
   policy flag. → keep block; revisit only on tenant demand.
8. **Stock-ledger reversal linkage.** New `reversal_of_id` column vs reversal
   movement row. → decide in Phase B.
9. **Backfill.** Existing tracking-only documents stay `unposted`; never
   retroactively post historical manufacturing records.

---

### Appendix — confirmation for this sprint

```
No posting code implemented.
No migration added.
No stock ledger entry created.
No journal entry created.
No finance/inventory service changed.
Documentation only.
```
