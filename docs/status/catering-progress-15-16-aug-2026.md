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

## What is left

| # | Item | Size | Blocked by |
|---|---|---|---|
| 1 | Cost block screens + booking line + kitchen sheet | large | in progress |
| 2 | Rate Impact moves **price**; making bulk adjust | medium | item 1 |
| 3 | Negative balance, refunds, customer ledger | large | — |
| 4 | Kitchen instruction list (55 Roman-Urdu entries) | small | export from old software |
| 5 | SMTP | minutes | **client decision** |

Roughly **35% of the agreed scope** is done by effort. The two large items are
still ahead.

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
