# Supplier credit clarity (SUPPLIER-CREDIT-CLARITY-1) — 26 Oct 2026

Branch `feat/supplier-credit-clarity-20261026` (worktree `pos-saas-advance`), from production `50d8a8f7`.
Owner's decisions: 26 Oct, after a khatribiryani payment landed on an invoice the client did not have in mind.
**Not deployed. Submitted for review.**

## What happened (prod, khatribiryani, supplier "Kashif kitchen")

Every payment since 20 Oct was saved on **Supplier Payments → New** with "Against Bill" left on "No specific bill".
- A payment saved that way settles the supplier's **oldest** open invoices first
  (`SupplierRunningAccountService::settleCredit`).
- A new invoice saved on **Supplier Invoices → Create Bill** uses any advance left over (`applyCreditToBill`).

So the money settled other invoices than the client meant. The totals were right: the supplier was owed Rs 452,980.50,
which is the sum of its open invoices.

- **The screens did warn, but quietly:** a help line and a small note.
- **Create Bill** showed only a total ("Supplier credit applied"), never which payments, and asked nothing.
- **The payment form** listed every supplier's bills (no supplier filter), and its note was wrong when a bill was
  chosen.

## Owner's decisions

1. **No option is chosen in advance.** Before Save the screen shows exactly where the money goes, and the person
   chooses.
2. **No "skip".** Leaving an advance unused while invoices are open would make the invoice list and the payables
   report say more is owed than the ledger. That mismatch is exactly why the running account was built: supplier
   Faisal Beef Counter showed Rs 117,600 more owed on the invoice list than in the ledger. Instead, a payment that
   settled the wrong invoice can be **moved** to another one.
3. **All tenants**: every tenant with the running account on (all of them today). With it off, nothing changes.

## The change

**A. Supplier Payments → New** (`tenant.supplier-payments.create` / `.store`)
- **A required choice, nothing ticked:**
  - "Pay one invoice", with the invoice list filtered to the chosen supplier and only invoices with a balance; or
  - "Pay the oldest open invoices first".
- **Coming from an invoice's own Pay button** (`?purchase_bill_id=`) counts as choosing it: that invoice is chosen.
- **"This payment will pay" is listed before Save,** invoice by invoice. It uses the same order the server uses: the
  chosen invoice first, then bill date, then id. The Post confirmation repeats the list.
  - Anything left over is listed as **"Not on any invoice — it lowers the supplier's balance"**, not as "advance".
    Reason: an opening balance or a journal can still be owed outside invoices (khatribiryani's "Kashif kitchen" had
    168,000 of opening). The screen's existing warning already says when the supplier ends up paid ahead, from the
    ledger.
- **The server checks the list it posts against the list shown.** If the invoices changed meanwhile (another
  payment, a new invoice), nothing is saved and the page shows the new list.
- **The note** says what really happens.

**B. Supplier Invoices → Create Bill** (`tenant.purchase-bills.create` / `.store`) and **Purchases → New purchase**
(`tenant.purchases.workspace*`)
- When the invoice will use advance, the review says how much and **from which payments and returns** (number, date,
  amount), and what is still to pay.
- A required tick confirms it ("Use this advance on this invoice"). Without the tick, nothing is saved.
- With no advance, nothing new is asked.
- **The server decides.** After the invoice's own ledger row, if the advance paid any of it and the tick is missing,
  the whole invoice rolls back (`CreditNotConfirmedException`).
  - Create Bill: the review reopens on the tick.
  - Workspace: the refusal is keyed `invoice.confirm_credit`.
  - On both screens the box is shown whenever the server asked, even if the screen's own sum said no advance, so
    nobody can be stuck without a box to tick.
- **A net invoice:** a return the invoice deducts is spent on it, so it is not listed as advance.
- **Who must tick:** only the two screens. Code that calls `createFromReceipt()` directly asks nothing
  (`askCredit = false`), so no hidden path changes.

**C. Move a payment from one invoice to another** (new `tenant.purchase-bills.move-credit`,
`POST /purchase-bills/{bill}/move-credit`)
- **The invoice page lists what paid it ("How this invoice was paid"):** each payment or return, its date and the
  amount it pays here. With the running account on, this replaces today's "Payments" table. That table listed only
  payments typed "against" this invoice, at their full amount, voided ones included — the same confusion. With it
  off, the old table stays.
- **Moves between invoices** are listed on both invoices: when, how much, of which payment, from, to, by whom, and
  why.
- **"Move to another invoice":** pick an open invoice of the same supplier, enter an amount, give a reason.
  - The amount can be no more than this payment pays here, and no more than the other invoice still owes.
  - This invoice owes that much again; the other owes that much less.
  - The supplier's balance, the ledger, cash and the GL are untouched.
  - Each move is recorded in a new table, `supplier_credit_moves` (source, from, to, amount, reason, who, when).
  - It is one transaction, locking the supplier and both invoices.
  - The route has the duplicate-submit guard, so one press is one move.
  - The browser asks before moving and names both invoices.
  - Refused, with nothing moved:
    - another supplier's invoice, or the same invoice;
    - more than the other invoice owes;
    - more than this payment pays on this invoice;
    - no reason given.
- **The payment page lists which invoices the payment paid.**
- **Who may move:** the Owner through deploy.sh. Other roles get it at deploy only where they already may void
  supplier payments (`tenant.supplier-payments.void`), the same rule as moving return credit (21 Oct). The grant is
  additive.

## Impact

**Tables**
- New `supplier_credit_moves` (tenant). It also had to join two lists of tables:
  - **`tenant:reset-transactions` wipes it,** with the allocations and invoices it points at. The reset refuses to run
    while any tenant table is unlisted, so without this every reset (demos, the owner's System reset screen) would
    have stopped.
  - **Deleting a voided payment for good** (VOID-PURGE) takes that payment's move records with it, into the hidden
    copy. No invoice is left naming a payment that is gone. Its void already took it off every invoice.
- `supplier_credit_allocations`: rows changed only by a move.
- `purchase_bills.amount_paid / balance_due / status`: recomputed by a move.
- Nothing else is written differently.

**Screens**
- Supplier Payments new and show.
- Supplier Invoices create and show.
- Purchases → New purchase (review).
- The supplier ledger's "Record Payment" (the same form).

**Not changed**
- How payments and invoices post.
- Journals, the supplier ledger, cash / bank.
- The running-account rules themselves (oldest first; an advance used on the next invoice).
- Returns.

**Tests**
- New `SupplierCreditClarityMySqlTest` (14). Every case goes through the real controllers, posted and rendered.
- `PurchaseWorkspaceMySqlTest`: one new test (the workspace's tick, and its refusal key). The test that pays more than
  an invoice owes after credit now sends the tick it needs.
- `SupplierRunningAccountMySqlTest`: its `bill()` helper sends the tick. Three of its invoices use an advance; that tick
  is the person's yes.
- Calls to `createFromReceipt()` that skip the screens are not affected (`askCredit` defaults to false).

## Deploy order (schema first — rule from 20 Oct)

1. **Deploy 1/2: the table only** (`supplier_credit_moves`). Nothing reads or writes it yet.
2. **Deploy 2/2: the code.**
   - deploy.sh gives the new `tenant.purchase-bills.move-credit` to Owner.
   - Then, additively (`givePermissionTo`), to every non-owner role that already has `tenant.supplier-payments.void`;
     then `system:clear-tenant-permission-cache`.
   - Snapshot every role before and after: no role may lose anything.

There is no switch. It applies wherever the running account is on (all tenants today); where it is off, nothing
changes. Rolling back is a code revert: moves already made stay as allocation rows, which is consistent either way
(an invoice's paid is still the sum of its rows).

## Results (26 Oct, local)

- **Dev run:** `SupplierCreditClarityMySqlTest` + `SupplierRunningAccountMySqlTest` + `PurchaseWorkspaceMySqlTest` —
  33/33.
- **Sabotage:** four guards broken at once; each one is caught by its own test, with its own message.

  | Guard broken | Caught by |
  |---|---|
  | The tick check | Create Bill and workspace tests (posted without the tick) |
  | The list check | Both payment-list tests |
  | The same-supplier check on a move | The move refusal test: the move to another supplier's invoice went through |
  | The permission check on the move form | The move permission test: the form showed with no permission |

  Restored byte-for-byte (md5).
- **Browser walk:** local isolated store, desktop 1366 and phone 390 — 31/31 checks.
  - Payment: nothing chosen; only the supplier's invoices; both orders listed; hidden list; confirmation; posted as
    listed.
  - Create Bill: the tick, then posted.
  - Move: amount filled, asked first, moved; both invoice pages and the payment page say it.
  - Workspace: refused without the tick, posted with it.
  - Phone: no sideways scroll. Switch OFF: today's screen.
  - No script errors on any page.
  - The walk found one real issue, fixed: with one invoice chosen, "Remaining After" showed that invoice's balance
    minus the payment ("Advance 1,300.00") while the list said 300 was left over. On account it is now "Supplier
    Balance After", from the supplier's balance.
- **Linkage check (after the first commit):** the new table was missing from two lists.
  - `tenant:reset-transactions` — it fails closed on an unlisted table.
  - The voided-payment purge.

  Both are fixed and tested (`test_a_transaction_reset_wipes_the_moves_with_the_invoices`,
  `test_deleting_a_voided_payment_for_good_takes_its_moves_with_it`). Undo either fix and these fail, along with
  `CateringTenantResetMySqlTest::test_no_tenant_table_is_left_unclassified`.
  Dev run of the clarity, purge and reset classes plus `SupplierNetBillMySqlTest`: 56/56.
