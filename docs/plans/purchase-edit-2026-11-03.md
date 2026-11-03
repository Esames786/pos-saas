# PURCHASE-EDIT-1 + SUPPLIER-LEDGER-DAILY-1 — plan (3 Nov 2026)

Branch `feat/purchase-edit-20261103`, worktree `pos-saas-purchase-edit`, from prod `830f04ec`.
Status: **plan — owner approved the direction on 3 Nov ("teekh hai ab md banao or kam start kardo")**. §4 is the
design after a full read of the purchase posting code and a read-only look at the first tenant's data.

## 1. Why

A live restaurant tenant's accounts person sent three voice notes (3 Nov) after being told why the GRN screen no longer
shows **Void**:

1. "We want to check the ledger **daily**: debit, credit and the balance must be visible — today this invoice, its
   total, this payment."
2. "If an invoice has a wrong **date**, we should not have to reverse it — change the date and the entry goes to the
   right day."
3. "Entries must follow the **invoice date**, not the posting date."
4. "If a **rate** has to change later, we should not have to reverse — **open the GRN, change the rate**, and
   everything corrects itself."
5. (Separate) unused heads in the chart of accounts / TB / P&L and expense categories — **at the end, not in this
   work** (owner, 3 Nov).
6. Changes as they come, not in a monthly batch.

Why **Void** disappeared: GRN-VOID-1 (`27d5fed3`, 3 Oct) allows a void only while the receipt has no supplier invoice.
The New purchase screen (W3/W4 S4, 24 Oct) makes the invoice in the same Post, so every receipt now has one (first
tenant: 74 of 74 posted receipts). Nothing was removed; the condition never holds any more.

### What the first tenant's data says (read-only, 3 Nov)
- 74 posted receipts, all invoiced; **62 invoices carry payments** — the supplier running account is ON, so payments
  settle the oldest invoices automatically. A rule "no edit once paid" would block 62 of 74.
- No purchase orders, no batch/expiry lines, no returns, no nettings, no invoice discount or tax.
- 11 old invoices carry a `purchase_bill_recost` GL correction, and 39 old receipts (215 lines, Aug–21 Oct) carry the
  22 Oct `grn_line_stock_expense` correction (stock moved to cost, items purchase-only since).
- The 7 receipts since 23 Oct that have stock lines: all goods still on hand, none paid.

## 2. What the client gets — no new screens

### 2.1 Edit happens ON the GRN page (the client's own words: "GRN kholein aur rate daalein")
Before: `[Back]` only.
After: `[✏️ Edit] [Void] [Back]`.
- **Edit** turns the Received Lines table into boxes: **Qty** and **Rate** per line (in the unit shown, e.g.
  "30 Each @ 560"), plus **Receipt date**, **Invoice date**, **Supplier invoice no.**, **Extra charges** and **Notes**.
  Qty 0 removes a line (two duplicate lines → put the total on one, 0 on the other). The new total and the difference
  show while typing ("45,460 → 44,860 (−600)"). Buttons **Save changes** / **Cancel**.
- Not editable in v1: supplier, branch, product, adding a line, batch/expiry, pack unit.
- A **"Badlav ki history"** box under the lines: who changed what, when ("3 Nov 4:10 PM — Imran: Big Apple (CRT)
  rate 920 → 620; invoice date 31 Oct → 30 Oct").
- When Edit is not possible the button is greyed with the reason in plain words.
- The **Supplier Invoice** page gets the same Edit button (it opens the GRN page in edit mode) and shows "Edited".

(Earlier idea — reopen the New purchase screen pre-filled — dropped: that screen can only create, rebuilds lines from
pack splits it never stored, and lets product/supplier change. Editing in place on the GRN page is what the client
asked for and is far safer.)

### 2.2 Daily visibility = the existing Supplier Ledger page
- Every entry shows on its **business date** (invoice date / payment date / return date); the time it was typed shows
  small.
- Two views: **Har entry** and **Roz ba roz** (one row per day: opening balance, bills (credit), payments (debit),
  closing balance; a day opens to its entries). From / To dates.
- Supplier picker gains **"Sab suppliers"**: all suppliers' bills, payments and total payable per day.

## 3. Rules

- **Money follows the document's own date.** Supplier ledger and GL corrections are dated on the receipt / invoice
  date (the old entry is wiped on its own date, the corrected one posted on the new date) — what the client asked for.
- **Stock follows today.** Stock quantity/value corrections are posted today. Stock is never back-dated (same rule as
  GRN-VOID-1 and VOID-ON-ORIGINAL-DATE-1): a back-dated movement rewrites average cost history that later sales
  already used.
- **Payments**:
  - running account ON (first tenant): edit allowed. If the new total is **below** what payments already cover, the
    excess comes off this invoice and shows as **"Not on any invoice"** (advance) — the screen says so before Save;
    it is never put on another invoice silently (SUPPLIER-CREDIT-CLARITY-1). If the new total is higher, the invoice
    simply shows more due.
  - running account OFF: edit refused while the invoice has any payment ("void the payment first" — that void already
    asks reverse today / wipe on its own date).
- **Edit / Void refused (v1), each with its reason:** a purchase return or netted return against it; a purchase
  order behind it; batch / expiry lines; an old GL correction on it (`purchase_bill_recost`, `grn_line_stock_expense`
  — the 22 Oct clean-up); a changed stock line whose goods are no longer on hand; the receipt already voided; the
  screen out of date (someone else saved first).
- **Void** (with or without an invoice): receipt and invoice voided together; asks **reverse today / wipe on its own
  date** for the money (same two cards as every other money void); stock comes out today.
- **Who:** new permission `tenant.goods-receipts.revise` (not "edit" — that name falls into the generic Edit
  checkbox of the Permission Center); void keeps `tenant.goods-receipts.void`. Granted to the roles that hold the void
  permission today.
- **Switches** (Master → tenant → Business, OFF everywhere; ON for the first tenant when the owner says):
  `purchase_edit` (Edit + Void with an invoice) and `supplier_ledger_daily` (dated ledger, daily view, all
  suppliers). OFF = every screen exactly as today.

## 4. Technical design

### 4.1 Schema (deploy 1, additive only)
1. `supplier_ledgers.entry_date` DATE NULL + index (supplier_id, entry_date).
2. `goods_receipt_revisions` (id, goods_receipt_id, purchase_bill_id NULL, revision_no, action ENUM(edit,void),
   before JSON, after JSON, changes JSON (human lines), reason NULL, date_mode NULL, reversed_journal_ids JSON,
   journal_entry_id NULL, created_by_user_id, timestamps). Unique (goods_receipt_id, revision_no). Listed in
   `TenantResetTransactionsCommand::WIPE_TABLES`.
3. `goods_receipts.revision_no` INT DEFAULT 0 (the form's "is this still what you saw" stamp).
4. `purchase_bills.status` ENUM + `'voided'`; `voided_at`, `voided_by_user_id`, `void_reason` NULL.

### 4.2 The revise engine — `App\Services\Purchasing\PurchaseRevisionService`
One tenant transaction; locks GRN → bill → supplier (same order as `createFromReceipt`); refuses on a Branch Server /
local-mode branch like every stock and GL writer.

1. **Check** (before any write): switch, permission, `revision_no` matches, guards of §3.
2. **New lines**: v2 receipts (`entry_json`) rebuild their entry from `entry_json` with the new packs count / price and
   run the same `PackLines::toLines` (so line discount, scheme free lines and the bill discount behave exactly as at
   entry); old-style receipts take qty / unit cost as typed. Landed cost through the existing
   `PurchasingService::landedUnitCosts`.
3. **Stock** — only for stock lines whose (product, variant) group changed in quantity or landed cost: every un-reversed
   `in` entry of that group for this receipt is reversed with `InventoryService::reverseReceiptEntry` (today), then the
   new lines of the group go in with `postIn` (today, landed cost). New flag on the reversal for this path:
   **restore the weighted average** (inverse of `postIn`'s averaging); refused if that would leave a negative stock
   value (= the goods were already sold). GRN-VOID-1's own behaviour is unchanged.
4. **Lines**: the receipt's lines are replaced by the new ones (`cost_account_id` carried over per product); the
   invoice's lines are re-copied from them exactly as `createFromReceipt` copies them; header fields updated;
   `revision_no` + 1.
5. **Invoice totals**: recomputed the way `createFromReceipt` computes them (subtotal + extra − discount + tax).
   Running account ON → if allocations exceed the new total, the excess is released from this invoice
   (`SupplierRunningAccountService` release path) and stays unallocated; then `recomputeBill`.
6. **Supplier ledger**: same date → one `purchase_bill_revision` row for the difference (debit if up, credit if down),
   `entry_date` = invoice date. Date changed → `purchase_bill_revision` credit of the old total on the old date + debit
   of the new total on the new date.
7. **GL**: every effective journal of the invoice (the original `purchase_bill`, or the latest
   `purchase_bill_revision`) is reversed **on its own date** (`JournalService::reverse(..., onDate)`), then the full
   corrected journal is posted with `source_type = purchase_bill_revision`, `source_id = revision id`, dated the
   invoice date, built by the same code as `postPurchaseBill` (extracted, not copied). Needed because
   `JournalService::post` returns the old entry for the same source even after it was reversed. A null from the poster
   rolls everything back.
8. **History**: the revision row with before / after snapshots and the human change list.
9. **Void** = the same engine with every line at 0: stock out today, ledger and GL per the chosen date mode, all
   allocations released, receipt `voided`, invoice `voided` with its amounts at 0 (so any report that does not know
   the new status still adds nothing). Its numbers live on in the revision snapshot.

### 4.3 Supplier ledger by business date
- `PurchasingService::postSupplierLedger` gains `entryDate`; every caller passes the document's date: invoice →
  `bill_date`; payment → `payment_date`; payment void → the void's resolved date; return → `return_date`; netted return
  → `bill_date`; manual journal mirror → `entry_date`; opening balance → its date; revisions → §4.2.6.
- Old rows: `finance:supplier-ledger-dates` (dry-run first, then `--yes`, per tenant, idempotent) fills `entry_date`
  from the referenced document; anything unmatched stays NULL and the screen falls back to `DATE(created_at)`.
- Screen (switch ON): ordered by business date, then id; running balance computed in that order from the opening
  balance before *From* (the stored `balance_after` stays as is — VoidPurge and history rely on it). Two views and
  "Sab suppliers" via `GET /suppliers/ledger` (`tenant.suppliers.ledger-all`, granted to roles holding
  `tenant.suppliers.ledger`). Same Blade; DR/CR labels and `balanceLabel()` unchanged.

### 4.4 Tests (MySQL, new)
- `PurchaseRevisionMySqlTest`: rate change on a purchase-only line (invoice, AP, cost account, GL moved, ledger row,
  history); invoice date change (old journal wiped on its own date, new on the new date, ledger rows); stock line rate
  change on hand (stock out/in today, average restored, stock value = ledger); qty 0 removes a line; second edit
  reverses the first revision, not the original; paid invoice cut below its payments → excess unallocated, never on
  another invoice; running account OFF + paid → refused; each §3 guard refused with nothing written; stale form
  refused; double submit writes once; switch OFF → 404; no permission → 403; TB balanced and AP control = subledger
  after every step.
- `GrnVoidWithInvoiceMySqlTest`: both date modes; stock, ledger, GL, allocations; invoice voided at 0.
- `SupplierLedgerDailyMySqlTest`: every writer stamps `entry_date`; the backfill; daily rows and balances; all
  suppliers; switch OFF = old screen.
- Page renders through the controllers (GRN page normal / edit mode / refused, invoice page, ledger both views).
- Sabotage runs on each guard; then the full MySQL suite on a frozen detached worktree.

## 5. Impact (system map, built 2 Nov at `75d7e97d`; later deploys did not touch purchasing)

| Table | used by | screens |
|---|---|---|
| goods_receipts / goods_receipt_lines | 41 / 31 | goods-receipts, purchases, purchase-returns, purchase-orders, suppliers |
| purchase_bills / purchase_bill_lines | 50 / 8 | purchase-bills, purchases, supplier-payments, suppliers, finance, reports |
| supplier_ledgers | 41 | suppliers (ledger), finance, reports, purchase-bills, supplier-payments |
| stock_ledgers / stock_balances | 128 / 122 | inventory, reports, POS, kitchen, stock counts … (writes only through InventoryService) |
| journal_entries (+ lines) | 64 | finance (TB, P&L, GL), reports |
| supplier_payments / supplier_credit_allocations | 44 / 43 | supplier-payments, purchase-bills, finance |
| purchase_returns / nettings | 34 / 22 | read only here (a guard) |

Screens changed: goods-receipts show, purchase-bills show, suppliers ledger (+ all suppliers). New routes:
`POST /goods-receipts/{grn}/revise`, `GET /suppliers/ledger`. Void route unchanged.

## 6. Safety

- Own worktree and branch; production untouched until the owner says deploy.
- Every write goes through existing posting / reversal code inside one transaction (all or nothing); refusals happen
  before the first write.
- Full MySQL suite + sabotage runs (money / stock / GL).
- Deploy: backup → deploy 1 (schema only) → deploy 2 (code) → backfill dry-run, then run → grant permissions
  additively → switches ON for the first tenant on the owner's word.
- Post-deploy proof: TB difference 0 on every tenant, AP control = supplier subledger, stock = ledger, no role lost a
  permission, sites 200.

## 7. Not in this work

- Unused heads / expense categories, and moving the old Petrol / Electric / PTCL vouchers from 6300 Utilities into the
  heads created on 27 Oct — at the end, owner's call.
- Editing old receipts that carry the 22 Oct / recost corrections, receipts from purchase orders, batch/expiry
  receipts, adding lines or changing the product/supplier — v2 if asked.
- "Close the month" lock — offered, not asked.
- GRN-VOID-1 still leaves the average cost as it was (its own known drift); not changed here.

## 8. Built and proven (3 Nov)

Commits on `feat/purchase-edit-20261103`: `0a7a2bc2` plan · `f0019787` **1/3 SCHEMA ONLY** · `0d07db90` 2/3 supplier ledger
by date · `27db1bc5` 3/3 edit / void · `30abc05a` system map.

What differs from §4, decided while building:
- The edit boxes are the GRN page's own Qty and Unit Cost columns (per base unit). A changed line's "last deal" becomes
  that unit price; `PackLines` is not re-run, so a line's old discount / scheme is not re-applied over a typed net cost.
- The stock reversal for an edit restores the weighted average (`reverseReceiptEntry(restoreAverage: true)`) and is
  refused when that would leave a negative value (the goods were sold). GRN-VOID-1's own void is unchanged.
- The corrected invoice journal keeps each purchase-only line on the head it was first booked to (`cost_account_id`).

Tests (MySQL, through the real routes as the store's owner):
- `SupplierLedgerDailyMySqlTest` 4/4 — every writer dates its row; the backfill (dry run, then --yes, idempotent,
  amounts / balances / updated_at untouched); a late-typed invoice on its own day with the balance carried; all
  suppliers; permission; switch off = old page.
- `PurchaseRevisionMySqlTest` 9/9 (150 assertions) — rate change; invoice date moved; stock line out / in with the
  average restored; receipt without an invoice; line to 0 and a second edit; paid invoice cut below its payments;
  guards; void (both date modes); the pages. After each: TB balances, AP in the GL = supplier ledger = supplier balance,
  stock quantity and value = stock ledger.

Sabotage (`scratchpad/pedit/sabotage.php`, detached worktree at `27db1bc5`): all **11 RED**, each on its intended
assertion — GL not reversed · average not restored · paid guard dropped · excess kept on the invoice · ledger date not
written · switch not checked · ledger difference flipped · sold goods not refused · old entry wiped today instead of on
its date · day view in typing order · stale form not refused.

Full suite, FROZEN detached worktree at `30abc05a` (HEAD and a clean tree checked before and after, DB set `_pfull`):
**MySQL 2,610 tests / 17,426 assertions, 0 failures** (4 h 10 min — run beside the sabotage runs). Unit 139: 3 failures
— CateringClientFeedbackUi sidebar, EscPosReportPayload, DeliveryRiderReassignment — the same 3 fail on prod `830f04ec`
(re-run there, 3 Nov). Note: `GrnVoidMySqlTest` fails 2 when run ALONE on prod code too (its bill store silently fails
in that fixture) but passes inside the full suite — order-dependent, pre-existing, not touched here.

## 9. Deploy (on the owner's word only)

1. Backup (`TenantBackupService` for each tenant / deploy.sh's own) · permission snapshot of every role.
2. **Deploy 1 = `f0019787` only** (schema: three additive migrations, nothing writes them). Smoke.
3. **Deploy 2 = the rest** (code). Smoke: `tb_diff=0` ×13, `neg_on_disallowed=0`, `department_negative=0`, no ERROR, sites 200;
   permission diff "no role lost anything".
4. `finance:supplier-ledger-dates <tenant>` dry run, read, then `--yes` — every tenant (rows only gain a date).
5. First tenant only, owner's word: grant `tenant.goods-receipts.revise` to the roles holding `tenant.goods-receipts.void`,
   `tenant.suppliers.ledger-all` to the roles holding `tenant.suppliers.ledger` (additively), `system:clear-tenant-permission-cache`;
   switches `purchase_edit` + `supplier_ledger_daily` ON in Master.
6. Proof on the live tenant, read-only: the GRN page shows Edit / Void (or the reason); the ledger's day view for this month.

## 10. Deployed (3 Nov 2026, owner: "Deploy + Khatri ON")

- Backup `storage/app/private/backups/20261103_183454` (master + every tenant, retaildemo has no DB) · role snapshot.
- **Deploy 1 `88a61f09`** (schema only) ~18:36 UTC: the three migrations on all 12 tenants (checked per tenant); tb_diff 0.
- **Deploy 2 `3b978b30`** (code) ~18:40 UTC: routes 824; tb_diff 0 ×12, neg_on_disallowed 0, department_negative 0;
  "PERMISSIONS OK: no role lost anything" (each Owner +2: revise, ledger-all); only error since = the known
  printing:autoclose-stuck one; five sites 200.
- Backfill `finance:supplier-ledger-dates`: dry run on all 12, then `--yes` where rows existed — khatribiryani 189,
  kashifkitchen 343, pharmacydemo 5; 0 left undated; amounts / balances / row count unchanged.
- First tenant (khatribiryani): Manager + Accounts given `tenant.goods-receipts.revise` and `tenant.suppliers.ledger-all`
  (Owner had them); switches `purchase_edit` + `supplier_ledger_daily` ON via TenantBusinessService (audited), the
  other five switches unchanged; tenant permission caches cleared.
- Proof, read-only, as its Owner through the controllers: GRN-…636 shows Edit + Void, edit mode has its 14 lines as
  boxes; 30 receipts editable, 44 refused with their reason (39 earlier stock correction, 5 earlier cost correction);
  a supplier's day view closes at exactly its balance today.
