# Third-Party Department Sales Handover — design note

Status: **DESIGN ONLY (not implemented yet).** This documents how the accounting works today and
proposes how a "hand over this department's sales to its third-party owner" one-click action should
post to the ledger. Nothing here is wired up.

---

## 1. How sales accounting works today (verified from code)

Every fully-paid POS sale is posted to the general ledger by
`App\Services\Finance\JournalPostingService::postPaidSale()` (called from `SalesService` at
checkout; idempotent, and it can never block the sale). One balanced journal entry is created with
these lines:

### Debit side — where the money landed (the "cash flow")
- For **each payment** on the sale, we **debit the Cash/Bank account mapped to that payment method**
  (`payment.method.cashBankAccount.account_id`). Example: a cash payment debits the cash drawer
  account (e.g. `1110 Main Cash Drawer`); a card payment debits the mapped bank account
  (e.g. `1210 Main Bank Account`).
- If a payment method has **no CoA mapping**, it falls back to **`1500 Undeposited Funds`** so the
  entry still balances and nothing is lost.

So "which account the cash flows into" is driven by the **payment method → cash/bank account
mapping**, not by the order type.

### Credit side — what the money represents
- **Revenue account is chosen by ORDER TYPE:**
  - `dine_in`, `takeaway`, `delivery` → **`4120 Restaurant Sales`**
  - anything else (retail / counter) → **`4110 Retail Sales`**
  - (code: `$revenueCode = in_array($order_type, ['dine_in','takeaway','delivery']) ? '4120' : '4110'`)
- **`2200 Sales Tax Payable`** — tax collected (a liability we owe the tax authority).
- **`4130 Service Charges`** — service charge portion.
- **`4140 Tips Income`** — tips portion.
- **`4200 Sales Discounts`** — discount is a *contra-income* line and is **debited** (it reduces
  revenue; `4200` is debit-normal under the Income tree).

### Cost of Goods Sold (separate lines on the same entry)
- Stock items: **Dr `5100 Product COGS` / Cr `1400 Inventory Asset`**.
- Recipe items: **Dr `5200 Recipe / Ingredient COGS`** (inventory `1400` was already reduced at
  ingredient consumption, so it is not credited again).

### Worked example — a $100 dine-in sale, $8 tax, paid cash
```
Dr 1110 Main Cash Drawer        108
    Cr 4120 Restaurant Sales        100
    Cr 2200 Sales Tax Payable         8
Dr 5100 Product COGS             40      (cost of the food)
    Cr 1400 Inventory Asset          40
```
Net effect: cash up 108, revenue up 100, tax liability up 8, inventory down 40, COGS up 40.

### The relevant Chart of Accounts (already seeded)
| Code | Account | Type |
|---|---|---|
| 1100/1110/1120 | Cash on Hand / Main Drawer / Petty Cash | Asset |
| 1200/1210 | Bank Accounts / Main Bank | Asset |
| 1500 | Undeposited Funds | Asset |
| 2100 | Accounts Payable | Liability |
| 2200 | Sales Tax Payable | Liability |
| 4110 | Retail Sales | Income |
| 4120 | Restaurant Sales | Income |
| 4130 | Service Charges | Income |
| 4140 | Tips Income | Income |
| 4200 | Sales Discounts (contra) | Income (debit-normal) |
| 5100 / 5200 | Product / Recipe COGS | Expense |

Posting is done through `App\Services\Finance\JournalService::post($sourceType, $sourceId,
$reference, $description, $date, $lines, $userId)`: it is **idempotent per (source_type, source_id)**,
requires **debits = credits**, and has a **`reverse()`** method for clean reversals.

---

## 2. How department sales work today

Departments (`App\Models\Tenant\Department`) are **mapping / reporting only** — *"no stock balances,
no ledgers, no GL. Branch stock stays the official truth."* A department "owns" a product via
category maps / product overrides (`Department::matchesProduct()`).

**Department sales are DERIVED, not stored:** `DepartmentReportService::sales()` groups paid
`sales_order_lines` by product and attributes each product's `line_total` to the department that
maps it. A sale line has **no `department_id` column** — the department is resolved by mapping at
report time.

So today a third-party department's sales are *already counted as our revenue* (`4110`/`4120`) at
checkout, and only *shown* as "that department's sales" in the report. There is currently **no
journal path that moves that money out to the third party.**

---

## 3. The problem to solve

Some departments (e.g. a juice bar, a bakery counter) are **operated by a third party** but ring
their sales through **our** POS. We collect the cash, but that revenue is not really ours — we owe
it to the concessionaire (optionally minus a commission). We need a **one-click action in the
Department Sales report** that records "this department's sales for this period are handed over to
its owner."

There are two accounting questions: **(a) reclassify** the sales out of our revenue into a payable,
and **(b) pay out** the cash when we settle with them.

---

## 4. Recommended accounting model — Agency / pass-through liability

Treat a third-party department's takings as money we **collected on their behalf** (agency sales).
We owe it to them → it is a **liability**, not our income.

### New accounts (proposal)
- **`2400 Payable to Third-Party Departments`** (Liability, child of `2000`), or one sub-account per
  concessionaire: `2410`, `2420`, … `Payable — <Dept name>`. Per-department sub-accounts give a
  clean per-owner balance in the ledger.
- (Optional, if we keep a cut) **`4150 Concession Commission Income`** (Income, child of `4100`).

### Entry 1 — the one-click "hand over sales" reclass (per department, per period)
Move the department's gross sales out of our revenue and into what we owe them:

```
Dr 4120 Restaurant Sales (or 4110)        <dept gross sales for the period>
    Cr 2400 Payable to Third-Party Dept        <dept gross sales for the period>
```
- **Debit the revenue account** (reduces our recognised sales by the department's share).
- **Credit the payable** (increases what we owe the third party).
- If we keep a commission, split the credit:
  ```
  Dr 4120 Restaurant Sales        gross
      Cr 4150 Commission Income        commission
      Cr 2400 Payable to Dept          net (gross − commission)
  ```

`source_type = 'dept_handover'`, `source_id = <a handover record id>` → idempotent, one handover per
(department, period). Reversible via `JournalService::reverse()` if posted in error.

### Entry 2 — paying the third party (settlement, later / separate button)
When we actually hand over the cash:
```
Dr 2400 Payable to Third-Party Dept        <amount paid>
    Cr 1110 Main Cash Drawer (or 1210 Bank)     <amount paid>
```
- **Debit the payable** (clears the liability).
- **Credit cash/bank** (cash physically leaves).

After Entry 1 + Entry 2 the department's takings have fully left our books: our revenue was reduced,
and the cash we collected went back out to the owner. The P&L shows only what is genuinely ours
(our own departments' sales, plus any commission).

### Why debit revenue / credit a liability?
- Income accounts are **credit-normal**, so **debiting** `4120` *reduces* revenue — exactly "these
  sales are not ours."
- Liabilities are **credit-normal**, so **crediting** `2400` *increases* what we owe. Handover =
  "we now owe the third party this money."

---

## 5. Alternative model considered (not recommended as default)
**Post the sale straight to the liability at checkout** (never touch revenue): make third-party
departments' payment methods / lines credit `2400` instead of `4120` at the point of sale. This is
cleaner in theory but invasive — it changes the hot POS posting path and the per-line mapping, and
it makes the day's revenue reports depend on department mapping at checkout time. The **reclass
model in §4 is preferred** because it leaves the POS untouched and the handover is an explicit,
auditable, reversible back-office action.

---

## 6. Proposed implementation (later — not built yet)
1. **Data model**
   - `departments.is_third_party` (bool) + `departments.owner_name` / `owner_contact`.
   - `departments.payable_account_id` (nullable → the `24xx` sub-account; auto-created on first
     handover if absent).
   - `departments.commission_percent` (nullable, default 0).
   - New table `department_handovers` (branch_id, department_id, period_from, period_to, gross_total,
     commission_total, net_total, reclass_journal_entry_id, payout_journal_entry_id, status
     [pending_payout | settled | reversed], created_by, timestamps) — the idempotency + audit record.
2. **UI** — in the Department Sales report (`DepartmentReportService::sales()` view), for a row whose
   department `is_third_party`, show a **"Hand over"** button next to its sales total. It opens a
   small confirm (period + amount + commission preview) → posts Entry 1 and creates the
   `department_handovers` row. A separate **"Record payout"** action posts Entry 2.
3. **Service** — `DepartmentHandoverService`:
   - `previewHandover(dept, filters)` → gross/commission/net from `DepartmentReportService::sales()`.
   - `postHandover(dept, filters, userId)` → `JournalService::post('dept_handover', handover->id, …)`
     with the §4 Entry 1 lines; idempotent per handover; wrapped in a tenant transaction.
   - `postPayout(handover, cashBankAccountId, userId)` → Entry 2.
   - `reverse(handover, reason, userId)` → `JournalService::reverse()` on both entries.
4. **Guards** — only `is_third_party` departments; block double-handover for the same period; respect
   branch permissions; never touch stock/COGS (handover is a pure financial reclass).

---

## 7. Locked decisions (owner, 2026-08-08) — MONEY-ONLY HANDOVER

Concrete wording from the owner: *"if my branch sale is 10,000 and BBQ sale is 5,000 — since BBQ
category belongs to the Kashif Kitchen department — we give away 5,000 directly to them, because
they own that department and their inventory. Inventory works the same as now, we will not disturb
it. The owner owes BBQ sales to Kashif Kitchen and at day-end / next day pays it by cash or bank
transfer."*

1. **Pure pass-through — NO commission.** 100% of the department's sales go to the owner.
2. **Handover amount = the department's SALES TOTAL exactly as shown in the Department Sales report**
   for the chosen day/range (no tax split, no per-item math). In the example: Kashif = 5,000.
3. **Inventory / COGS is UNTOUCHED** — the current stock mechanics stay exactly as they are. The
   handover is a **money-only** reclass (revenue → payable → cash). No stock, no COGS, no `1400`,
   `5100`, `5200` lines.
4. **We collect the cash; the owner is paid at day-end or next day** by cash or bank transfer.
5. **Granularity: per day OR an arbitrary date range** picked in the report.
6. **Per-owner payable sub-account** (e.g. `2410 Payable — Kashif Kitchen`, `2420 Payable — …`) —
   one running "tab" per department owner.

### The two entries (example: Kashif Kitchen = 5,000)
```
1) Hand over the day's department sales (one click in the report):
   Dr 4120 Restaurant Sales                 5,000     (remove BBQ from our revenue)
       Cr 2410 Payable to Kashif Kitchen        5,000   (we now owe Kashif)

2) Pay the owner (day-end / next day, cash or bank):
   Dr 2410 Payable to Kashif Kitchen        5,000     (clear the tab)
       Cr 1110 Cash Drawer / 1210 Bank          5,000   (money goes to Kashif)
```
`2410` is the owner's running balance — "how much do we owe Kashif right now." Idempotent: one
handover per (department, day/range); reversible via `JournalService::reverse()`.

### Known, accepted caveat — profit presentation
Because inventory/COGS is intentionally left as-is, a BBQ sale still posts our COGS (e.g. 2,000) and
reduces our `1400`. After giving away all 5,000 of revenue, the **P&L will show a ~2,000 loss on that
department** (5,000 revenue out, 2,000 cost kept). The **cash handover is still 100% correct**; only
the department's *profit presentation* carries the cost. If the owner later wants this neutral, the
clean fix is to mark that department's products **non-stock-tracked** (owner supplies their own
stock) — NOT part of this feature, and not done now per the "don't disturb inventory" instruction.

## 8. Implementation (later) — updated for the money-only model
1. **Data model**
   - `departments.is_third_party` (bool) + `owner_name` / `owner_contact`.
   - `departments.payable_account_id` (nullable → `24xx` sub-account; auto-created on first handover).
   - New table `department_handovers` (branch_id, department_id, period_from, period_to,
     handover_total, reclass_journal_entry_id, payout_journal_entry_id, status
     [pending_payout | settled | reversed], created_by, timestamps). NO commission/tax columns —
     money-only.
2. **UI** — in the Department Sales report, for an `is_third_party` department row show a
   **"Hand over"** button next to its sales total (posts entry 1 + creates the handover row), and a
   **"Record payout"** action (posts entry 2, pick cash or bank).
3. **Service `DepartmentHandoverService`** — `preview` (reads `DepartmentReportService::sales()`),
   `postHandover` (`JournalService::post('dept_handover', handover->id, …)`, idempotent), `postPayout`,
   `reverse`. Pure financial reclass; **never touches stock/COGS**.
4. **Guards** — only `is_third_party` departments; one handover per (department, day/range); branch
   permission checks.

No blocking questions remain — the model is money-only and inventory is untouched.
