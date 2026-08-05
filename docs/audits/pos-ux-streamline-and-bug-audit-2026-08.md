# POS UX streamline + bug audit (2026-08) — code-grounded

**Audit / plan only** — no code, no migrations, no deploy. Cloud POS canonical (branch
`feat/14d-2-plan-upgrade-requests`, POS HEAD `693b1af`). Prioritises **bugs first** (make the
current screen *trustworthy*), then **print unification** (browser "print here" vs agent "send to
network"), then **UX streamline** (one bill, one primary action, plain language, everything in-POS).

**Guardrail:** do **not** disturb the hardened KOT/Reminder/Direct-Pay engine
(`PrintJobService`, `PrintRoutingService`, `DirectPayPrintOrchestrator`, `kot_batches`,
`sales_orders.direct_pay_print_state`) or the split-brain guard. All fixes below are the **UI layer
+ targeted controller/JS corrections** over that engine.

Confirmed decisions (user): **"print here" = browser window print dialog**; **"send to network" =
the Print-Agent path, and every network action must actually fire when an agent is connected.**

---

## Part A — Trust-breaker bugs (fix first)

### A1 — Fractional quantity on PCS items (Orange Juice = 1.001 PCS)  ·  **P0**
- **Symptom:** a "pieces" item shows `1.001` in cart, split bill, and bill preview (screenshots 1/3/4/8).
- **Root cause:** the **Amount-entry field** in the qty modal computes
  `qty = amount / price` → `.toFixed(3)` with **no `isMeasurableProduct()` guard**
  ([index.blade.php:1774-1781](../../resources/views/tenant/pos/index.blade.php#L1774-L1781)); the
  modal's `step`/`min` are hard-set to `product.quantity_step || 0.001` regardless of unit type
  ([1762-1763](../../resources/views/tenant/pos/index.blade.php#L1762-L1763)). Entering `42.04` for a
  `42.00` PCS item yields `1.001`. The `+/-` stepper is correct (it rounds non-measurable —
  [2296-2299](../../resources/views/tenant/pos/index.blade.php#L2296-L2299)) so it can never *repair*
  the value (adds integer 1 → `2.001`), which is exactly what the red marks in screenshot 4 flag.
- **Fix direction:** make the amount→qty path unit-aware: for non-measurable products round to the
  nearest integer (or reject fractional), and set the modal `step`/`min` from `qtyStep(product)`.
  Optionally snap existing fractional PCS lines to integer on recall.
- **Risk:** low, isolated to the qty modal; measurable (KG/volume) items unaffected.

### A2 — Split-bill "remaining quantity wrong on cart"  ·  **P0**
- **Symptom:** after paying a split, the POS cart shows an incorrect remaining quantity (user #1).
- **Root cause:** the server side is actually **correct** — `recalculateHeldSale()` reduces the held
  line by `remaining = available − split`
  ([SplitBillController.php:165-176, 234-268](../../app/Http/Controllers/Tenant/SplitBillController.php#L165-L176)).
  The problem is the **flow**: `store()` **redirects to `/sales-orders/{paidSale}`**
  ([line 230](../../app/Http/Controllers/Tenant/SplitBillController.php#L230)) — a back-office page —
  so the **POS cart (a client-side snapshot in another tab/modal) is never re-synced** and keeps the
  stale recalled quantities. Compounded by A1 (a `1.001` base makes the leftover look obviously wrong).
- **Fix direction:** after a split, the POS must **re-fetch the held sale from the server** (or return
  to POS and reload the recalled sale) so the cart reflects the true remaining lines. Fix A1 first so
  the base quantities are integral. Do the split **inside POS** (Part C) so there is no cross-page
  staleness at all.
- **Risk:** medium (touches the split→POS handoff); server split math itself needs no change.

### A3 — Recent Prints disappear after a page reload  ·  **P1**
- **Symptom:** "Recent Prints" is empty after reloading the POS (user #11).
- **Root cause:** `openRecentPrints()` requires `_lastSaleId`, an **in-memory JS variable**; on reload
  it is `null` → "No recent sale to reprint"
  ([index.blade.php:3923-3935](../../resources/views/tenant/pos/index.blade.php#L3923-L3935)). The jobs
  themselves persist server-side (`/api/pos/print-jobs/{saleId}`), the POS just forgets *which* sale.
- **Fix direction:** persist `_lastSaleId`/`_lastSaleNo` per terminal (localStorage), and when a table/
  sale is recalled, point Recent Prints at that sale. Then reload keeps the list.
- **Risk:** low.

### A4 — Held Orders "Items" shows a summed quantity (`3.201`), not a count  ·  **P1**
- **Symptom:** Held Orders list shows `Items 3.201` (screenshot 5).
- **Root cause:** `'items' => round($s->lines->sum('quantity'), 3)`
  ([HeldSaleController.php:66](../../app/Http/Controllers/Tenant/HeldSaleController.php#L66); same at
  line 168 `items_count`). It sums quantities (1 + 1.001 + 1.2 = 3.201) instead of counting lines.
- **Fix direction:** use `$s->lines->count()` for a line count (and/or label the summed value "units").
  Fixing A1 also removes the fractional oddity.
- **Risk:** trivial.

### A5 — "Print Receipt" doesn't reach a printer; "Send KOT" says "No new items"  ·  **P0/P1**
- **Symptom:** from the Sales Order page, Print Receipt appears to do nothing and Send KOT returns
  "No new items to send to kitchen" (user #16, screenshot 11).
- **Root cause (two distinct things):**
  1. **Receipt/KOT route to the Agent *only if a `printer_id` is mapped*.** `queueReceipt/queueKot`
     build `print_jobs`; when `printer_id` is empty the job is a **`fallback`** and the request just
     `redirect`s to the browser document
     ([PrintJobController.php:42-60, 99-135](../../app/Http/Controllers/Tenant/PrintJobController.php#L42-L60)).
     On the demo, printers are seeded at `127.0.0.1:9100` (a dead loopback) so *network* jobs queue but
     the agent can never print them (memory: "demo printers 127.0.0.1 → ECONNREFUSED"). So "not
     printing" = **no real printer mapped / agent not connected**, not a code fault in the queue.
  2. **"No new items" is KOT-delta idempotency.** `queueKot` only sends **unsent** items; a recalled
     held order whose items were already KOT'd has no new items → the message
     ([line 99-103](../../app/Http/Controllers/Tenant/PrintJobController.php#L99-L103)). A **reprint**
     needs `reprint=true` (a duplicate KOT). Correct behaviour, confusing label.
- **Fix direction:** (a) surface printer/agent state — if no printer mapped or no agent online, the
  button should say **"Print here (browser)"** and the network option should be disabled with "No agent
  connected"; when an agent *is* online for the branch/terminal, "Send to network" must actually queue
  to that printer (this is the Part B unification). (b) Relabel: **"Send KOT"** (new items) vs
  **"Reprint KOT"** (duplicate), and show "All items already sent" instead of a dead-end message.
- **Risk:** low for labels; the network path itself works — it needs a real mapped printer + connected
  agent to demonstrate (blocked on the demo's fake loopback printers).

### A6 — "Close (Paid)" does nothing on the Table Board  ·  **P1**
- **Symptom:** Close (Paid) appears broken (user #15, screenshot 10 banner "Cancel or complete every
  open order through POS before closing this table session.").
- **Root cause:** the session still has an **open held order** (HS-…310 is `Held`, screenshot 9). The
  `close()` guard correctly refuses to close a session with unpaid/uncancelled orders
  ([routes: tenant.restaurant.table-sessions.close](../../routes/tenant.php#L489)). So it is **not a
  bug** — it's a correct guard with a **dead-end UX**: the cashier can't resolve the held order from
  the board; they must go to POS, recall it, and pay/cancel.
- **Fix direction:** make the block actionable — from the board, link the offending held order straight
  to "Recall in POS → Pay/Cancel", or allow closing once all orders are paid; show *which* order blocks.
- **Risk:** low (UX/link), no change to the guard.

### A7 — Closed table still shows the old order id  ·  **P1 (needs one more trace)**
- **Symptom:** after closing a table, its previous order/session id still appears (user #6).
- **Status:** not yet root-caused in this pass — likely a **stale POS URL/state** (the POS keeps
  `held_sale_id`/`table_session_id` in the query string, e.g.
  `pos?held_sale_id=55&table_session_id=9`) or a table-board cache not refreshing after close. **To
  verify before fixing:** whether the board re-queries session state post-close and whether POS clears
  its recalled-sale state. Flagged for the fix sprint, not guessed here.

---

## Part B — Print engine unification ("print here" vs "send to network")

**Current reality (mapped):** the POS mixes two engines with no explicit user choice.
- **Browser ("print here"):** POS bill-preview modals print via a hidden **iframe**
  `frame.contentWindow.print()` ([index.blade.php:4308-4315](../../resources/views/tenant/pos/index.blade.php#L4308-L4315));
  the `printing/documents/{receipt,kot,reminder}` blades use `window.print()`; and receipt/KOT
  `redirect` to those documents when no printer is mapped.
- **Network ("send to network"):** `PrintJobService` creates `print_jobs`; **iff** a `printer_id` is
  mapped, the Print Agent polls `/api/print-agent/pending`, claims, prints, and ACKs. No printer →
  `fallback` → browser only.

**The confusion is that the *same* action silently picks an engine based on whether a printer is
mapped.** The user never chooses, and on the demo the network path points at a dead printer.

**Target design — one explicit control, everywhere something prints (bill, receipt, KOT, reminder,
reprint):**
```
[ Print here (browser) ]   [ Send to network ▾ (Kitchen A) ]
```
- **Print here** → always the browser dialog (the iframe/document path that already exists).
- **Send to network** → queue a `print_job` to the chosen printer via the existing Agent path.
  **Enabled only when an agent is connected** for that branch/terminal and a real printer is mapped;
  otherwise greyed with "No agent connected." (Agent liveness is already tracked —
  `print_agents.last_seen_at`; the control keys off that.)
- **Reprint** (fixes user #2): route the receipt/KOT reprint button through the same control with
  `reprint=true` (duplicate `copy_no`) instead of the modal path that currently no-ops.
- Keep the hardened auto-print/Direct-Pay behaviour underneath unchanged; this only makes the
  *destination choice explicit and reliable*.

---

## Part C — UX streamline (phase 2, after bugs)

Root problem: the UI exposes the full domain model (session → held → round) instead of a simple task
flow. Target: the cashier thinks only **Table → Order → Bill → Pay**, and never leaves POS.

- **One bill surface.** Merge "Current Cart Preview" (unsaved cart) and "Table Bill Preview" (server
  session) into a **single panel** with the unsent cart clearly marked inside it — same fonts, one
  layout (fixes #4.1, #10, #12; the duplicated look-alike modals go away).
- **One primary action per state, plain words.** Empty table → **Start Order**; items in cart → **Send
  to Kitchen**; guest done → **Bill & Pay**. Stop the button renaming itself Review&Pay / Close&Pay /
  Save Order / Add Round / New Order (fixes #7, #8).
- **Plain language + feedback for jargon.** "Add Round" → **"Add more items"** (only on an open check);
  "Request Bill" → **"Guest wants the bill"**, and after pressing, the button flips to a filled
  **"Bill requested ✓"** so it visibly did something — it currently only sets the session to
  `Bill_requested` (visible only on the board, screenshots 9/10) which is why #3/#13 feel like nothing
  happens.
- **Split Bill & table actions inside POS.** Today Split Bill and Table Board open as **full
  back-office pages** (the Bingoo sidebar appears *inside* the Split Bill modal, screenshot 8). Re-host
  them as **POS-native panels** so there is no app/tab switch mid-transaction (fixes #17, and removes
  the A2 cross-page staleness).
- **Split Bill — full visibility + clear next action (#14, and the cashier must always know what to
  do):** the split screen must show the **complete state of the check in one view**:
  - every **already-paid split** (the paid orders — `SO-…`, visible in the session, screenshots 1/9)
    with its items, amount, and payment method;
  - the **remaining unpaid items** and the **remaining balance**;
  - a running **"Paid X of Y · Remaining Z"** header so the cashier instantly sees how much of the
    table is settled and what is left to collect;
  - a clear primary action ("Pay selected" / "Pay remaining") so there is never ambiguity about the
    next step.
  Default the payment method to **Cash**, not Bank Transfer (screenshot 8 shows Bank Transfer default).
  The data already exists (paid order history + held remaining on the session) — this is a
  presentation change: aggregate paid + remaining into one always-visible split ledger. Because this
  lives **inside POS** (above), the cashier never loses this picture by switching pages.
- **Persistent "New Sale / New Table"** escape hatch that is always available and clearly distinct from
  "New Order on this table" (addresses #8 rush-hour confusion) — designed so it can't silently abandon
  an open check.
- **Customer phone (#5):** clarify its purpose (delivery/loyalty/receipt) or hide it for order types
  that don't use it.

---

## Part D — Sequenced plan

1. **POS-BUGFIX-1 (P0 trust):** A1 fractional PCS, A2 split remaining re-sync, A5 relabel + printer/
   agent-aware buttons, A4 items-count. Ship with MySQL-suite-style regression on quantities/totals.
2. **POS-BUGFIX-2 (P1):** A3 recent-prints persistence, A6 Close(Paid) actionable block, A7 stale
   closed-table (after the pending trace).
3. **PRINT-UNIFY-1 (Part B):** the single "print here / send to network" control across bill/receipt/
   KOT/reminder/reprint, agent-liveness gated. No change to the hardened queue/orchestrator.
4. **POS-STREAMLINE-1 (Part C):** one bill surface + one primary action + plain language + in-POS
   split/table + New Sale escape hatch + split defaults.

**What we will NOT touch:** KOT/Reminder/Direct-Pay durability, `logical_key`/`copy_no` idempotency,
split-brain guard, table move/merge locking, Merge-Table (stays hidden). Every change above sits on
top of that engine.

## Part E — Compatibility with hardened Reminder/KOT/Direct-Pay + Offline/Edge (mandatory)

Every item in Parts A–D must be built so it **does not conflict with, and ideally advances,** the
recent hardened work and the Edge/offline contracts. Explicit cross-checks:

**Reminder (REMINDER-PRINT-1) — must be preserved exactly:**
- The unified print control (Part B) routes Reminder through `PrintRoutingService::reminderRoutesForSale`
  and `DirectPayPrintOrchestrator` **unchanged** — it must **not** collapse KOT/Reminder/Receipt into
  one document, and must honour the **"Ask before resend on additional round"** policy and the
  immutable **non-fiscal** Reminder payload.
- Reminder **historical destinations** (cancellation Reminder → printers that got the original) must
  keep working; Part B's destination snapshot (below) strengthens this, never weakens it.

**KOT / Direct-Pay / cancellation (recent hardening) — preserved:**
- `logical_key` enqueue-idempotency and `copy_no` manual-duplicate semantics stay; "Reprint KOT"
  (A5/Part B) = duplicate via `copy_no`, not a bypass. Direct-Pay intent-before-finalize and
  `sales_orders.direct_pay_print_state` untouched. A1's quantity fix must not alter KOT delta math
  (`kot_sent_quantity`).

**Offline / Edge — every change stays Edge-ready:**
- **Print unification (Part B) is the biggest Edge lever.** "Send to network" = the **Agent path**,
  which is the *same* path Edge re-points to the **Branch Server** locally (§8b). So the control must
  key off **agent liveness** (`print_agents.last_seen_at`) — cloud vs local URL agnostic — so it works
  identically offline. Keep browser "print here" as **at-least-once, never exactly-once**
  (Edge §H2.11/§MTF): reprint/`copy_no` dedup semantics preserved.
- **Print-history destination snapshot** (Edge §MTF.2/§G): when we touch the print buttons, lay the
  groundwork to **snapshot the destination** (printer name/ip/routing identity) on the print event so
  historical Reminder cancellation survives a printer being removed — this is *required* by the Edge
  contract and is cheap to add now, aligning cloud + Edge.
- **Quantity correctness (A1/A4)** directly supports Edge **operational stock** (§MTF.4 / §D): integral
  PCS quantities and correct line semantics are what `EdgeOperationalStockService` will decrement.
  Fixing them now is a prerequisite, not a conflict.
- **Split / held / table lifecycle (A2, A6, Part C)** must keep the **UI layer separable from
  `finalizePaidSale`**, because Edge swaps that boundary for `EdgeSaleCaptureService` (no GL/COGS/FEFO).
  In-POS split/table (Part C) reduces the surface Edge must strip (POS is reused class A/B) — a win —
  but the split's *posting* must remain the one finalize path Edge replaces, not a new parallel one.
- **`sale_no` / identity:** any UI that displays or persists a sale number must treat it as an opaque
  string (Edge moves to `SO-{branch}-{terminal}-{ULID}`, §H2.8) — no UI may parse or sort by its shape.
- **New Sale / New Table escape hatch (Part C):** must not bypass the table-session state machine that
  Edge relies on; it starts a clean check via the existing controllers, leaving open checks recallable.

**Net:** the streamline makes the POS *simpler and more Edge-ready* (one reused UI, one agent-driven
print path, correct quantities), provided we route everything through the existing hardened services
and never fork the finalize/print engine.

## Open questions
- A7: confirm whether the stale closed-table id is POS query-string state or a board cache (one trace).
- Split inside POS vs. keeping the dedicated page — do you want Split fully in the POS modal now, or
  just fix the cart re-sync first and re-host it in Part C?
- "New Sale" escape hatch: should it hard-require confirmation when an open check exists, or always
  start clean and leave the check on the table for later recall?
