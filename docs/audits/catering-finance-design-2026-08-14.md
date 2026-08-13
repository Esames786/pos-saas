# CATERING-GO-LIVE-READINESS-1 — Finance & Stock Integration Design (2026-08-14)

Code-grounded design for Catering GL/cash-bank posting and production stock
issue. Every account is resolved by CODE through `JournalService::accountId()`
/ line `account_code` (never hardcoded ids); every posting goes through
`JournalPostingService` translator methods over `JournalService::post()`
(idempotent per `(source_type, source_id)`, balanced-only, Branch-Server
fenced). No business code writes `journal_entries`/`journal_lines`,
`cash_bank_account_transactions` balances outside the grounded patterns, or
stock tables.

## Grounded authorities

| Concern | Authority (verified) |
|---|---|
| GL entry point | `JournalPostingService` translators → `JournalService::post` (`app/Services/Finance/JournalService.php:30` — fail-closed on Branch Server, replay returns existing entry, balanced-only) |
| Cash/bank movement | `cash_bank_account_transactions` + `current_balance` bump under `lockForUpdate` — the `CustomerReceivableService::postCashBankTransaction` / `postSalesCashBankMovement` idempotent-by-reference pattern |
| Cash/bank → CoA | `cashBankCoaId()` via `cash_bank_accounts.account_id`; payment-method mapping via `payment_methods.cash_bank_account_id`; fallback **1500 Undeposited Funds** (postPaidSale policy) |
| AR | account **1300**; customer-payment GL = Dr cash-bank / Cr 1300 (`postCustomerPayment`) |
| Advances liability | account **2300 Customer Advances** — already in `DefaultChartOfAccountsSeeder` |
| Revenue policy | `postPaidSale`: Cr revenue + Cr 2200 tax + Cr 4130 service charges, Dr 4200 discounts (contra) |
| COGS policy | inventory-leaving entries post **Dr COGS ÷ Cr 1400 Inventory Asset** at actual layer cost (FIN-7C) |
| Stock mutation | `InventoryService::postOutFefo` ONLY (split-brain fence built in); returns `StockLedger[]` with actual FEFO `total_cost` — sufficient for COGS |
| Reversal | `reverseForSource()` → immutable reversal entries |

New account: **4160 Catering Revenue** (income, child of 4100) added to the
idempotent `DefaultChartOfAccountsSeeder` — rolled out to existing tenants the
same way the MFG-FIN-A accounts were (re-run the seeder per tenant; keyed on
`code`, touches nothing else).

## Posting contracts (all by account CODE)

**A. Advance receipt — `postCateringAdvance`** (source `catering_advance`, id = advance)
```
Dr  mapped cash/bank account (payment_method → cash_bank_account, else 1500)
Cr  2300 Customer Advances
```
Plus an idempotent cash/bank transaction (+balance) when a mapped real
account exists — 1500 fallback posts GL only, exactly like postPaidSale.

**B. Final invoice — `postCateringFinalInvoice`** (source `catering_final_invoice`, id = invoice)
```
Dr  1300 Accounts Receivable      grand_total
Dr  4200 Sales Discounts          discount_amount        (contra)
Cr  4160 Catering Revenue         subtotal + other_charge_amount
Cr  4130 Service Charges          service_charge_amount
Cr  2200 Sales Tax Payable        tax_amount
```
Balanced by construction: grand = subtotal + service + other + tax − discount.

**C. Advance application — `applyCateringAdvance`** (source `catering_advance_application`, id = invoice)
```
Dr  2300 Customer Advances        advance_total at issue
Cr  1300 Accounts Receivable      advance_total at issue
```

**D. Settlement (advance recorded AFTER the invoice) — `postCateringSettlement`**
(source `catering_settlement`, id = advance)
```
Dr  mapped cash/bank (else 1500)
Cr  1300 Accounts Receivable
```
The §4 advance flow stays the single money-in door; whether a receipt is a
DEPOSIT (Cr 2300) or an AR SETTLEMENT (Cr 1300) is decided atomically by
whether the event's final invoice exists at record time.

**COGS — `postCateringCogs`** (source `catering_material_issue`, id = issue)
```
Dr  5200 Recipe / Ingredient COGS   Σ actual FEFO total_cost
Cr  1400 Inventory Asset            same
```
Actual FEFO layer cost only — the Material Rate Book is QUOTING cost and is
never used for accounting.

## Hard-rule implementation

- **Atomic advance**: `CateringAdvance` creation + GL + cash/bank happen in ONE
  tenant transaction inside `CateringAdvanceService::record()`; any posting
  failure rolls the operational row back. Catering translators deliberately do
  NOT use the safe-null catch: §5 requires no partial state, so they throw.
- **Idempotency**: canonical source identities above; replay returns the
  existing entry (JournalService) and existing cash/bank rows are found by
  reference. **Conflict refusal**: each translator compares an existing
  entry's totals against the expected amount and THROWS on mismatch rather
  than silently accepting a different payload.
- **Invoice accounting state (§6)**: `catering_final_invoices` gains
  write-once linkage columns (`journal_entry_id`,
  `advance_application_journal_entry_id`, `gl_posted_at`); the immutability
  guard allows ONLY these columns to transition from NULL. Commercial fields
  stay frozen. Postings happen inside the issue transaction → an invoice
  exists iff its GL exists (no pending/failed state machine needed —
  repository convention is atomic postings, not async posting states).
- **Material issue (§7)**: immutable `catering_material_issues(+lines)`
  document (ULID, unique per release ⇒ retry-idempotent), issuing ONLY via
  `InventoryService::postOutFefo` with `movement_type: recipe_consumption`
  (existing enum; semantically: raw materials consumed for production) and
  `reference_type: catering_material_issue` for full traceability. Non-stock
  materials record `non_stock` lines with zero movement. Branch explicit,
  allow-negative follows the branch policy, split-brain fence inherited.
- **POS shift cash**: back-office catering receipts are NOT terminal/shift
  cash — no `shifts`/`sale_payments` involvement (grounded: shift totals are
  exclusively the POS settlement authority's).
- **Customer subledger**: `customer_ledgers` entry types are sale/payment
  shaped and owned by `CustomerReceivableService`; catering keeps customer
  exposure at GL AR + event balance in V1 and does not write foreign entry
  types into that subledger. (Documented deviation — revisit if the client
  needs per-customer catering statements inside the AR aging.)
