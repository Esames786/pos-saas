# Manufacturing → Finance Impact Backlog

> **Current status (2026-07-16): active implementation backlog.** Settings and
> posting infrastructure are live in code. Consumption posting (Phase C),
> finished-goods receipt posting (Phase D), and WIP close/variance (Phase G) are
> implemented behind the tenant posting-settings gate. Scrap/rejection/rework,
> manufactured-goods COGS, and valuation/reconciliation reports remain pending.
>
> This backlog tracks the finance/accounting work that will be needed **later**,
> as the Manufacturing track matures, so it can be added safely without
> disturbing other modules. Each item is tagged with the phase that first
> requires it:
>
> **➡ Detailed design:** the full posting design (events, journal/stock-ledger
> design, required settings, schema gaps, phased roadmap A–H) now lives in
> [`MANUFACTURING_FINANCE_POSTING_DESIGN.md`](MANUFACTURING_FINANCE_POSTING_DESIGN.md)
> (MFG-FIN-DESIGN-1, subsequently implemented in phases).
>
> - **[WIP]** — required for the Work-in-Process phase
> - **[FG]** — required for the Finished-Goods phase
> - **[COST]** — future costing / accounting-maturity phase

---

## 1. Current state (reconciled 2026-07-16)

> The detailed historical phase notes below describe the foundation when each
> MANUF module first shipped. Current posting reality: consumption, accepted FG
> receipts, and ready-WIP closing can create reversible stock/GL entries when
> posting settings are enabled and ready. COGS, scrap, rejection, and rework
> posting remain unimplemented.

| Module | State | Finance impact today |
|---|---|---|
| Manufacturing Customers | CRUD | none (separate from POS/Sales customers, no AR) |
| Production Orders | planning | none |
| Bill of Materials | configuration | none |
| Material Requisition (MRC) | request/planning | none (no stock issue, no GL) |
| Work in Process (WIP) | **tracking/planning (MANUF-5)** | none (no stock issue, no WIP accounting, no GL) |
| Finished Goods | **tracking only (MANUF-6)** | none (no inventory increase, no WIP→FG accounting, no COGS, no GL) |
| Scrap / Hard Waste | **tracking only (MANUF-7)** | none (no inventory deduction, no scrap expense, no WIP variance, no GL) |
| Rejections | **tracking only (MANUF-8)** | none (no inventory deduction, no scrap auto-create, no rejection/rework expense, no WIP variance, no GL) |
| Consumption | **tracking only (MANUF-9)** | none (no inventory deduction, no raw-material issue, no WIP/MRC mutation, no consumption accounting, no COGS, no GL) |
| Production Reports | **read-only (MANUF-10)** | none (pure SELECT aggregation; no posting, no mutation) |
| Posting Settings | **config only (MFG-FIN-A)** | none (account mapping + policy stored; disabled by default; NO posting code) |
| Posting Infrastructure | **schema/scaffold only (MFG-FIN-B)** | none (posting-state + cost columns + service scaffold; NO posting code) |

> **Phase B implemented (MFG-FIN-B):** Posting **infrastructure / schema only**.
> Added posting-state columns (`posting_status`/`posted_at`/`posted_by_user_id`/
> `journal_entry_id`/`reversed_of_id`) to `production_orders`,
> `manufacturing_consumption_records`, `finished_good_receipts`,
> `manufacturing_scrap_records`, `manufacturing_rejection_records`; WIP cost columns
> (`accumulated_cost`/`costed_quantity`/`average_unit_cost`); FG cost columns
> (`unit_cost`/`total_cost`); `stock_ledgers.reversal_of_id`. Added
> `HasManufacturingPostingStatus` trait + `ManufacturingPostingService` scaffold
> (reads settings, validates readiness, answers idempotency, flips posting-state
> columns — but **never** calls JournalService/InventoryService or creates journal/
> stock rows) + read-only posting-status badges on 5 show pages (no buttons).
> **No event posting, no journals, no stock movements, no GL, no document business
> mutation; all documents default `unposted`.** **Phase C (Consumption posting
> Dr WIP / Cr Raw Material) is next — not implemented in this sprint.**

> **Phase A implemented (MFG-FIN-A):** Manufacturing posting **CoA accounts**
> seeded (`1410` Raw Material, `1420` WIP, `1430` Finished Goods, `1490` Overhead
> Clearing, `5300` Production Variance, `5310` Manufactured Goods COGS, `6210`
> Direct Labour, `6900` Scrap/Waste Expense, `6910` Rework Expense, `6920`
> Inventory Adjustment Expense — additive, `is_system`, existing accounts
> untouched). New `manufacturing_posting_settings` table + `ManufacturingPostingSetting`
> model + **Posting Settings UI** (show/edit/update) under Manufacturing, with 3
> settings permissions and account-type + enable-readiness validation. **Settings
> are disabled by default and post nothing** — no journal entries, no stock-ledger
> entries, no inventory movement, no document mutation, no finance/inventory
> service change. **Phase B (posting infrastructure / posting-state columns /
> idempotency) is next — not event posting.**

> **Production Reports phase note (MANUF-10):** The Production Reports module is now
> live as **read-only** analytics. It aggregates existing manufacturing operational
> data only (orders, MRC, WIP, finished goods, scrap, rejections, consumption) into
> summary cards, grouped tables, yield/variance indicators and CSV exports. It does
> **not** implement inventory deduction, WIP/FG accounting, variance accounting, COGS
> or GL posting, and performs **no writes/mutation** of any manufacturing record.
>
> **Foundation completion:** Manufacturing foundation modules **MANUF-1 through
> MANUF-10 are now live** (all tracking/configuration/read-only). The
> accounting/posting layer (inventory movements, WIP/FG/COGS/variance, GL) remains
> **deferred** behind explicit settings and future approval — the items in section 2
> below are the roadmap for that phase.

> **Consumption phase note (MANUF-9):** The Consumption module is now live as
> **tracking-only** — it records planned vs consumed material (with wastage and an
> auto-calculated variance = consumed − planned) from a WIP job, a material
> requisition, or manually. **Material-consumption accounting is NOT implemented:**
> no inventory deduction/adjustment, no stock ledger `production_out` movement, no
> raw-material issue posting, no Dr WIP / Cr Inventory, no material usage/yield
> variance posting, no COGS, no GL journals. It also does **not** mutate WIP line
> `consumed_quantity` or MRC line `issued_quantity` — a consumption record is a
> separate tracking document. `estimated_unit_cost`/`estimated_total_value`/
> `estimated_consumption_value` are informational only. This module is the natural
> hook point for the **[WIP] raw-material issue → stock + WIP accounting** backlog
> item when perpetual inventory/GL posting is enabled.

> **Rejections phase note (MANUF-8):** The Rejections module is now live as
> **tracking-only** — it records rejected quantity, defect reason, severity and
> disposition (rework / scrap / accept-after-review / disposed, with batch/lot and
> defect-code lines) from a WIP job, finished goods receipt, or manually.
> **Rejection accounting is NOT implemented:** no inventory deduction/adjustment, no
> stock ledger entry, **no automatic Scrap record creation**, no rejection or rework
> expense posting, no WIP usage/yield variance, no COGS, no GL journals.
> `estimated_loss_value` is informational only. Recording a rejection does not change
> WIP / Finished Goods / Production Order status. Future: link a rejection's
> `scrap_quantity` disposition to an actual Scrap record + inventory/GL postings, and
> rework cost capture (re-issue materials/labour to WIP).

> **Scrap / Hard Waste phase note (MANUF-7):** The Scrap module is now live as
> **tracking-only** — it records wasted/damaged/lost quantity (total / recoverable /
> disposed, optional estimated loss value, batch/lot lines) from a WIP job, a
> finished goods receipt, or manually. **Scrap accounting is NOT implemented:** no
> inventory deduction/adjustment, no stock ledger entry, no scrap expense posting
> (Dr Scrap/Waste expense / Cr Inventory or WIP), no WIP usage/yield variance, no
> COGS and no GL journals. `estimated_loss_value` is an informational tracking
> figure only — it does not post anywhere. Recording scrap does not change WIP /
> Finished Goods / Production Order status.

> **Finished Goods phase note (MANUF-6):** The Finished Goods module is now live as
> **tracking-only** — it records production output (received / accepted / rejected /
> scrap, with optional batch/lot output lines) from a WIP job. **Finished-goods
> inventory increase and WIP→FG accounting are NOT implemented**: no stock ledger
> `production_in` movement, no Dr Finished Goods / Cr WIP posting, and **COGS is not
> implemented** (it would post on sale of the finished product, once FG inventory is
> real). Acceptance/quality figures are tracking numbers only and do not move stock
> or post to the GL. Recording finished goods does not change WIP or production-order
> status in this phase.

> **WIP phase note (MANUF-5):** The WIP module is now live as **tracking-only** — it
> records planned/started/completed quantities, progress %, and a material snapshot
> (required/issued/consumed/remaining) carried from the MRC. **WIP accounting is still
> pending**: raw-material issue → stock movement, WIP account mapping, and the
> Dr WIP / Cr Inventory posting are NOT implemented. The consumed/issued figures on a
> WIP job are tracking numbers only and do not move stock or post to the GL.

**Invariant to preserve:** every future change below must keep
`trial_balance.difference = 0` and `P&L net_profit == Balance Sheet current_earnings`,
and must not alter POS / Sales / Purchasing / Inventory posting.

---

## 2. Backlog items

### Inventory / stock movement
- **[WIP] Raw material issue from MRC** — issuing an MRC line should create a
  stock ledger `production_out` (raw material) movement and reduce on-hand.
  Needs a `manufacturing` movement source on the stock ledger. No GL yet at this
  step unless perpetual inventory is enabled.
- **[WIP] Raw material consumption accounting** — when perpetual inventory is on,
  raw-material issue should credit Inventory (Raw Materials) and debit
  Work-in-Process. Requires account mapping (below).
- **[FG] Finished goods receipt** — completing production should create a
  `production_in` stock movement for the finished product at computed cost.
- **[COST] Inventory-to-GL reconciliation for production** — a report proving
  manufacturing stock movements reconcile to GL inventory/WIP balances.

### Chart of accounts / mapping
- **[WIP] WIP account mapping** — a configurable "Work in Process" asset account.
- **[FG] Finished Goods account mapping** — a configurable "Finished Goods"
  inventory asset account.
- **[WIP] Raw Material consumption account mapping** — link raw-material issues to
  the correct inventory/WIP accounts (per branch / cost center if needed).
- **[COST] Manufacturing overhead account(s)** and **labor cost account(s)**.

### Costing
- **[COST] BOM standard cost roll-up** — compute a finished product's standard
  cost from component costs + wastage (+ labor/overhead when available).
- **[COST] Actual vs standard costing report** — compare BOM standard cost to
  actual issued quantities/costs per production order.
- **[COST] Production variance accounting** — material usage variance and rate
  variance postings once standard costing exists.
- **[COST] Manufacturing overhead allocation** — allocate overhead to WIP/FG by a
  chosen driver (labor hours, machine hours, qty).
- **[COST] Labor cost allocation** — capture and allocate labor to WIP.

### Scrap / rejection
- **[WIP] Scrap / Hard Waste accounting** — scrap during production should reduce
  WIP/inventory and post to a scrap expense/loss account.
- **[FG] Rejection accounting** — rejected finished goods handling (rework cost or
  write-off) with the appropriate account.

### Cost center / branch
- **[COST] Manufacturing cost center / branch costing** — production orders and
  MRCs already carry a single `branch_id`; extend reporting so WIP/FG/COGS can be
  analysed per branch / production unit (reuse the existing report branch
  multi-select pattern, reports-only).

### Logic / validation decisions (not finance posting)
- **[FG] Finished-goods received-quantity rule (production-completion rule).**
  MANUF-6 currently enforces `received_quantity <= WIP planned_quantity` (in
  `FinishedGoodReceiptController::validateHeader()`), **not** strict
  `received_quantity <= WIP remaining (planned − completed)`. Reason: strict
  remaining blocks recording finished goods once `WipJob.completed_quantity` has
  already been advanced (remaining = 0), and it contradicts the generate-from-WIP
  prefill which falls back to `planned` when remaining is 0. **Decision deferred:**
  when production-completion posting is built, decide the authoritative rule —
  likely "cumulative received across all FG receipts for a WIP ≤ planned" (sum
  guard across receipts), and whether recording FG should advance/close WIP and
  Production Order status. Until then, keep the `<= planned` guard as-is.

---

## 3. Sequencing guidance

1. **WIP phase:** add WIP + Raw Material account mapping, MRC issue → stock
   movement, raw-material consumption posting. Gate all GL posting behind the
   perpetual-inventory setting; keep planning records untouched.
2. **Finished Goods phase:** finished-goods receipt at cost, FG account mapping,
   scrap/rejection handling.
3. **Costing phase:** standard cost roll-up, variance accounting, overhead/labor
   allocation, actual-vs-standard and inventory-to-GL reconciliation reports.

**Hard rule for every phase:** add new, isolated posting paths behind explicit
settings; never change existing POS/Sales/Purchase/Inventory/Finance posting or
report math. Re-run the finance integrity check (`tb_diff=0`, `pl_vs_bs=OK`)
after each change.

---

## MFG-FIN-C — Consumption Posting (DONE)

First real manufacturing GL posting. Posting a consumption record issues raw material
from stock (FEFO), accrues the actual issued cost onto the linked WIP job, and posts:

```
Dr  Work-In-Process Inventory      total actual issue cost
Cr  Raw Material Inventory          total actual issue cost
```

- Service `App\Services\Manufacturing\ConsumptionPostingService` (`post()` / `reverse()`),
  controller `ManufacturingConsumptionPostingController`, routes
  `tenant.manufacturing.consumption.post|reverse`, perms granted to Owner.
- Strict, settings-gated (`ManufacturingPostingService::assertSettingsReady`), idempotent
  (`assertUnposted`/`alreadyHasJournal`/`alreadyHasStockMovement`), reversible
  (`JournalService::reverse` + stock-in with `reversal_of_id`; originals never deleted).
- New stock movement types `manufacturing_material_issue` / `_reversal`; consumption lines
  gained `actual_unit_cost` / `actual_total_cost` / `posted_quantity`.
- Respects PRODUCT-BOUNDARY-2: component must be `can_be_bom_component` + stock-tracked +
  active; POS visibility/saleable are NOT required (hidden raw materials are valid).
- **Variant-null bugfix (2026-07):** `post()` passed `variant: null` to `postOutFefo`, but
  every normally-purchased product's stock balance is keyed to its DEFAULT variant — so
  posting always failed "Insufficient stock" on real data (same bug previously fixed in
  KitchenWastageController). Now resolves the default variant per line before issuing.
  Reversal side was already variant-correct (reads `product_variant_id` off the issue ledger).
- Verified (rolled-back E2E): post +1 journal (balanced) + stock issue + WIP accrual,
  reverse restores both, `tb_diff=0` throughout; all guards block (disabled settings,
  non-bom-component, non-stock-tracked, insufficient stock, duplicate post/reverse).
- **Not** implemented: finished-goods, scrap, rejection, rework, variance, manufactured
  COGS, BOM cost roll-up, auto-posting, any sales/POS COGS change.
