# Catering — where we got to, 15–16 August 2026

Two days of work on the Kashif Kitchen catering vertical: closing UAT defects,
then starting a genuine rebuild of how a dish is priced.

**Production:** `cbb480f` · **Branch:** `feat/catering-product-ux-v1` · **Canonical:** `cbb480f`
**Deploys:** 3, all verified, zero rollbacks
**Test suite:** 187 → **415 MySQL tests**, 2,477 assertions

---

## Day 1 — 15 August: closing the UAT defects

### What was broken when the day started

Kashif opened a screen and got a 500. That turned out to be the visible end of
several problems.

**Two Catering views crashed in production.** Blade silently truncated a long
`@json` payload at PCRE's recursion limit and emitted unparseable PHP.
`view:cache` reported success on both — it compiles Blade to PHP but never
validates the PHP it emits. That is why the defect shipped.

> A permanent gate now compiles every view and runs `php -l` on the output.
> **`view:cache` is not proof.** `CompiledBladeSyntaxTest` is.

**The dashboard was blocked at login.** `tenant.dashboard` was owned by the
`reports` module, so any plan without reports was met with "Module Not
Available". Reaching the landing page is not an entitlement decision.

**POS menus leaked into a catering-only tenant.** Root cause, and it recurred all
week: `deploy.sh` grants the Owner **every** `tenant.*` permission regardless of
plan, so **`@can` is not an entitlement decision**. Every section without a
module gate leaked.

**Materials were invisible.** All 14 of Kashif's raw materials appeared on no
screen at all — the catalog list excluded them, the manufacturing list excluded
them, and there was no materials screen a catering plan could open. Searching
"chicken" returned only the dish.

### What was built

| Commit | Work |
|---|---|
| `0a74301` | **Behaviour lock** — froze product creation field by field, for every archetype, *before* touching anything near it |
| `5cf34af` | Formatting follow-up, certified in an isolated worktree |
| `addd008` | **Catering › Materials** — kitchen vocabulary, no BOM/WIP language |
| `2a4fb69` | Impact explanations on 9 screens: finance / stock / print / email / reversibility |
| `cb7aa8e` | Made that guidance precise after review |
| `bc234bf` | Print choices on estimate and invoice, Urdu thermal honestly refused |
| `1e7b589` | Cancellation reason, advance preserved |
| `37010c8`, `498cd70` | Release-gate and HTTP authorization proof |

### The lies we removed

The event screen claimed *"no accounting entries are posted"* while
`CateringAdvanceService` was posting a journal entry **and** moving a cash/bank
balance. An operator trusting that label would have misread their own books.

A second copy of the same false claim survived in the advance flash message and
was caught the next day.

> Reversibility is now **three-valued**, not binary. Cancelling after an advance
> is reversible as an operation and **not** reversible as a financial fact.
> A yes/no answer would have to lie about the case that matters most.

---

## Day 2 — 16 August: production breakage, then the rebuild

### Six redirects that 500'd *after* doing their work

Create event, edit event, release production, save a material rate, and all three
rate-impact buttons. Every one wrote its record, then threw.

Tenant routes sit under `Route::domain('{subdomain}.…')`, so `route()` fills the
**subdomain first** — it swallowed the model and left the real parameter empty.
The last screenshot named it outright: *"Missing parameter: subdomain"*.

Nothing caught it because service tests call services and render tests render
views. **No test had ever followed a controller through to its redirect.** There
is now a guard that fails if any catering controller reintroduces `route()`.

### Then the rebuild began

| Commit | Work |
|---|---|
| `f9e259d` | Booking calendar on the dashboard — 3-month window, overdue bookings called out |
| `a1025e6` | Test isolation: stopped three tests depending on what ran before them |
| `cb40f03` | The six redirects, plus the second false finance message |
| `ac09b61` | Double-submit guard · yearly numbering · Kashif plan as code |
| `cbb480f` | Store freed from the order · POS button entitlement fix |

### Double submit — found in the client's own data

```
EV-20260816-0001  created 10:43:54
EV-20260816-0002  created 10:43:55   ← same second
EV-20260816-0003  created 10:43:55   ← same second
EV-20260816-0004  created 10:43:55   ← same second
```

Four bookings in two seconds. On **Record Advance** the same thing posts real
money to the ledger more than once.

A disabled button was not enough — it does nothing for a refresh or a back
button. The guard is on the server: a one-time token, claimed atomically. Three
presses now reach the controller **once**. It is inert without a token, so no
other module changed.

### A negative balance, live

```
EV-20260816-0001
  quotation       458,250.00
  advances paid   492,500.00
  BALANCE        −34,250.00
```

The overpayment guard checks each advance against the balance **at that moment**.
The estimate was edited afterwards, dropping below what had been paid. The guard
only looks forward. The system now refuses further advances and has **no way to
refund or clear** the 34,250 — still open, see below.

### Store freed from the order

The old rule was in the **schema**, not the code: `catering_production_release_id`
was `NOT NULL` and `UNIQUE`. An issue could not exist without an order, and an
order could never be issued against twice.

> **The correction that mattered most:** both foreign keys were
> `ON DELETE CASCADE`. Right while the link was mandatory — no order, no issue.
> Once the link is only a reference, cascade becomes destructive: deleting a
> release would delete the **stock issue**, erasing the record of material that
> physically left the store. Both are now `SET NULL`.
>
> The stock movement is the fact. The order is a note about it.

### Cost blocks — started

A dish becomes the sum of named blocks. Kashif's examples are now executable
tests:

```
Chicken Karahi   chicken 200 + making 500 = 700/KG
chicken → 250                              = 750/KG   automatically

Biryani 10 KG    chicken 5 KG, rice 5 KG   ratio 0.5, not 1:1
                 charged 7,200 · material cost 1,600

Customer brings the chicken → 0 charged AND 0 drawn
Live counter setup → 3,000 whether the order is 10 KG or 100
```

**12 tests / 27 assertions, green.** Core service and data model done; screens
and rate-impact wiring still to come.

---

## Kashif Kitchen — current state

**Working:** bookings, estimates, revisions, advances, production release,
material issue, final invoice, closure · A4 documents in English and Urdu ·
network printing · booking calendar · materials · guide · **purchasing (enabled
today)** · **store issue** · yearly numbering · double-submit protection.

**Data:** 29 products (14 raw materials), 15 recipes, 15 material rates,
8 printer mappings, Estimate #8353 intact at 1,485,800.

**Not working yet:** email delivers nothing (`MAIL_MAILER=log`) · Urdu cannot
print on thermal · no refunds or negative-balance settlement.

---

## What is left — in detail

Roughly **35% of the agreed scope** is done by effort. Five items remain; two are
large. Ordered by dependency, not by size.

---

### 1 · Finish cost blocks — screens, booking line, kitchen sheet

**Size:** large · **Status:** core done (12 tests green), interface not started

The data model and calculation service exist and are proven. What is missing is
every place a human touches them.

**1a · Dish editing screen** — *Catering › Catering Products*

Add a blocks panel to a dish. Each row: label, type (material or charge), the
material it draws from, quantity per unit, rate, and basis (per unit or lump
sum). Live total at the bottom so the operator sees `200 + 120 + 400 = 720/KG`
as they type.

Plus the **costing / recipe switch**. One per dish, never both. Switching a dish
that already has a recipe must warn rather than silently ignore it.

**1b · Booking line** — *event screen*

When a dish in block mode is added, the line expands to show its blocks. Each
material block gets a **"customer is supplying this"** tick, which removes it
from the charge *and* from the kitchen sheet. The line total recalculates live.

The estimate line must **store what it charged**, not recompute later — the same
rule that already protects a sent quotation from a catalog price change.

**1c · Kitchen sheet**

Today it lists dishes. It must also list the **materials those dishes need**,
derived from the ratios: `Biryani 10 KG → chicken 5 KG, rice 5 KG`. Blocks the
customer supplied must not appear at all.

For a recipe-mode dish this stays as it is. So the sheet becomes two things: a
precise pick-list where recipes exist, a work order where they do not.

**1d · Costing readiness**

`CateringRecipeCostingService::readiness()` currently blocks sending a quotation
when a recipe cannot be costed. Block-mode dishes need the same fail-closed check
routed through `CateringCostBlockService::readiness()`, which already exists —
it is the wiring that is missing.

**Tests needed:** dish screen renders and saves blocks · booking line charges and
requires the right amounts · a customer-supplied block vanishes from both · the
kitchen sheet asks for ratio quantities · send is refused when a block has no
rate · a recipe-mode dish is completely unaffected.

**Risk:** the booking line is the most-used screen in the module. The estimate
line must keep its own stored rate, or an old quotation could silently reprice.

---

### 2 · Rate Impact moves the price, and making adjusts in bulk

**Size:** medium · **Blocked by:** item 1

**2a · Impact shows price movement**

Today `applyToDrafts()` calls `costing->snapshot()`, which recomputes **cost**
only — that is why changing beef moved the margin and not the quotation. For
block-mode dishes it must also recompute the **dish rate** and the affected
estimate lines.

The screen gains an old-rate → new-rate column. Right now **Current Cost** shows
a dash because no snapshot exists yet, which is why the change felt invisible.

Unchanged: nothing reprices on its own, the operator picks which drafts, and
sent or agreed quotations are never touched.

**2b · Global making adjustment** — new screen

Select dishes, apply `+100` or `−100` to their making block, review the impact,
apply to chosen drafts.

> **The trap:** `+100` on a **per-unit** block raises a 10 KG line by **1,000**,
> not 100. On a **lump-sum** block it raises it by 100 once. The screen must say
> which it is doing *before* applying, or a bulk change moves ten times more than
> intended.

**Tests needed:** a material rate change moves a block-mode draft's price ·
a recipe-mode draft still moves only its cost · sent quotations never move ·
per-unit and lump-sum bulk adjustments each behave correctly · the preview
matches what apply actually does.

---

### 3 · Negative balance, refunds, customer ledger

**Size:** large · **Blocked by:** nothing — could start today

This is the only item with a **live problem waiting on it**: `EV-20260816-0001`
sits at **−34,250** with no way to settle it.

**3a · Let the balance go negative**

`CateringAdvance` refuses any payment exceeding the outstanding balance —
*"V1 has no customer-credit authority"*. That guard also fires when the estimate
is reduced *after* payment, which is exactly the real-world case. The balance
must be allowed below zero and displayed as **owed to the customer**.

**3b · Refunds — money out**

There is no way to record money leaving. Needs a proper refund action in the
finance layer: posts to the ledger, reduces the cash or bank account it came
from, and can never exceed what was received.

**3c · Customer ledger** — new screen

A running statement per booking: every payment in, every refund out, a running
balance. Today only a total exists.

**3d · Credit carried forward**

Overpayment held against another booking rather than refunded.

**Tests needed:** balance goes negative when the estimate is reduced after
payment · a refund posts correctly and cannot exceed receipts · the ledger
balances to zero after any sequence · cancelling with an outstanding advance
offers settlement instead of silently leaving it · no direct journal writes.

**Risk — the highest in the remaining work.** This is real money leaving the
business. It must be built once, carefully, and never reach a state where the
books and the screen disagree.

---

### 4 · Kitchen instruction list

**Size:** small · **Blocked by:** getting the list out of the old software

The old system has ~55 fixed Roman-Urdu instructions — *Mirch Kam*,
*Chawal Dana Dana*, *Gosht Gala Huwa Ho*, *Koyala*. Multi-select per line, so the
kitchen never receives four spellings of the same thing.

Ours is **free text** today.

Needs: a managed list (English + Urdu), multi-select on a booking line, printed
on the kitchen sheet.

> That vocabulary is twenty years of a caterer's knowledge and exists only inside
> a desktop program. **Export it rather than retype it** — retyping loses the
> nuance.

---

### 5 · SMTP

**Size:** minutes of work · **Blocked by:** the client

Production runs `MAIL_MAILER=log`. Quotation, advance and invoice emails are
recorded and reach nobody. Reminders depend on the same dead transport.

Two decisions outstanding:

- **Relay** (Brevo / Zoho / SES) — ~15 minutes, established deliverability, or
  **self-hosted Postfix** — hours, plus PTR at Hostinger or Gmail rejects it
- The domain is **`bingoopos.com`**, not `bingoo.com` as the original brief said

Until this is answered, no amount of building makes email arrive. A manual
"Email to Customer" button is deliberately **not** being built first — it would
deliver nothing.

---

## Sequencing

```
NOW    1a → 1b → 1c → 1d      cost blocks, end to end
THEN   2a → 2b                impact moves price, making bulk adjust
THEN   3a → 3b → 3c → 3d      refunds and the customer ledger
ANY    4                      instruction list, once exported
ANY    5                      SMTP, once decided
```

Items **3, 4 and 5 are independent** of cost blocks and could be pulled forward.
The argument for doing 3 first is that it has a live unresolved balance; the
argument against is that cost blocks is what the rest of the design hangs on.

Every tranche ships the same way: build → targeted tests → **full suite twice** →
Pint and Blade lint → commit → verified backup → deploy → read-only Khatri check.

---

## Lessons that changed how we work

**`@can` is not entitlement.** Every Owner holds every permission. It caused the
sidebar leak, the dashboard block, and the POS button — three separate symptoms,
one cause. Gates now check the **plan**.

**`view:cache` is not a test.** It reported success on views that could not
parse.

**A test that reads a table must clean that table.** Three separate isolation
failures this week — a leftover plan, truncated migration-seeded permissions, and
an uncleaned releases table. Every one passed alone and failed in the suite.

**Run the full suite twice.** A single pass proves order-safety in one
arrangement. The second pass is what proves no residue survives — and it caught a
real failure.

**Silent skips are worse than errors.** `if ($module = Module::where(...)->first())`
quietly built a plan without catering, so a test passed or failed depending on
what ran before it and proved nothing on its own.

---

## Safety record

Khatri Biryani — the only live tenant — was never written to. Across three
deploys: trial balance **0.00** and orphan journal lines **0** on all nine
tenants, every time. Catering never enabled there. Khatri traded normally
throughout, 122 → 161 sales over the two days.

Every deploy took a verified backup first: 10 dumps, none zero-size, SHA-256
manifest.
