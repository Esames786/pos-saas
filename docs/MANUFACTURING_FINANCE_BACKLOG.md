# Manufacturing → Finance Impact Backlog

> **Status: documentation only.** Nothing in this file is implemented yet.
> The current Manufacturing modules (Customers, Production Orders, BOM,
> Material Requisition / MRC) are **planning / configuration / request-only**.
> They do **not** post to inventory, WIP, finished goods, COGS, or the General
> Ledger, and they do **not** affect POS, Sales, Purchasing, Inventory, or the
> existing Finance reports.
>
> This backlog tracks the finance/accounting work that will be needed **later**,
> as the Manufacturing track matures, so it can be added safely without
> disturbing other modules. Each item is tagged with the phase that first
> requires it:
>
> - **[WIP]** — required for the Work-in-Process phase
> - **[FG]** — required for the Finished-Goods phase
> - **[COST]** — future costing / accounting-maturity phase

---

## 1. Current state (as of MANUF-4)

| Module | State | Finance impact today |
|---|---|---|
| Manufacturing Customers | CRUD | none (separate from POS/Sales customers, no AR) |
| Production Orders | planning | none |
| Bill of Materials | configuration | none |
| Material Requisition (MRC) | request/planning | none (no stock issue, no GL) |
| Work in Process (WIP) | **tracking/planning (MANUF-5)** | none (no stock issue, no WIP accounting, no GL) |
| Finished Goods | **tracking only (MANUF-6)** | none (no inventory increase, no WIP→FG accounting, no COGS, no GL) |
| Scrap / Rejections / Consumption / Reports | Coming Soon | none |

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
