# Catering — costing contract and operator parity backlog

**Status:** locked, 17 August 2026 · **Applies from:** production `9b4ad86`

Two things live here. The first half is a **contract**: decisions that are now
settled and that code must not quietly drift from. The second half is a
**backlog**: requirements from the client's old software that until now existed
only as screenshots in a chat window, which is not a place requirements survive.

This is deliberately not a progress log — that is
[catering-progress-15-16-aug-2026.md](catering-progress-15-16-aug-2026.md). This
document says what is true and what is owed, not what happened when.

---

## Part 1 — The costing contract

### 1 · Product type is not costing mode

These are different questions and they stay in different places.

| | Question it answers | Where it lives |
|---|---|---|
| **Product type** | *What is this thing?* | `products.product_kind` — `sale_item`, `raw_material`, … |
| **Costing mode** | *What decides this dish's catering cost?* | `catering_product_profiles.costing_mode` — `recipe` or `blocks` |

Chicken Karahi is a `sale_item` whether it is priced by recipe or by blocks.
Raw Chicken is a `raw_material` either way.

> **There is no `product_type = cost_block`, and there must never be one.**
> Cost blocks are a costing arrangement on a catering profile. Putting them in
> the product taxonomy would push a catering-only concept into the shared
> Product authority that eight other tenants depend on.

A third field already exists on the same profile and is **not** the same thing:

- **`pricing_mode`** (`per_pax` | `fixed`) — how the dish is quoted commercially.
- **`costing_mode`** (`recipe` | `blocks`) — which authority supplies its cost.

Changing one never changes the other. On screen they are labelled **Pricing
Method** and **Costing Source**; neither is labelled "Mode".

### 2 · Existing recipes are not disposable

Kashif has 15 production recipes. They stay.

- No dish is mass-switched to blocks.
- No recipe is deleted, by migration or by mode switch.
- No block figures are guessed to fill a gap.
- No `UPDATE … SET costing_mode='blocks'`, and no tenant-name special case
  anywhere in the code.

Conversion is **per product, explicit, and operator-driven**. A dish changes
costing source when someone configures the new source and confirms the switch —
never because a deployment ran.

### 3 · Both datasets may be stored; only one is the authority

A product may hold recipe rows *and* block rows at the same time. Exactly one of
them is the **active costing authority**, named by `costing_mode`.

The inactive side is dormant, not deleted:

```
Chicken Karahi
  costing_mode = blocks
  blocks       = complete      -> ACTIVE, decides cost and readiness
  recipe rows  = incomplete    -> dormant, ignored entirely
  => READY
```

This is what makes switching safe in both directions. A client who supplies
recipes next month can switch back and find their recipe intact.

### 4 · A material block carries two independent numbers

This is the distinction the whole design rests on, and collapsing it would make
either the customer's bill or the kitchen's sheet wrong with nothing to catch it.

| Field | Means | Feeds |
|---|---|---|
| `rate` | what the customer pays, **per unit of the dish** | the quotation |
| `quantity_per_unit` | how much material **one unit of dish consumes** | the kitchen sheet and the cost |

Worked through, for a 10 KG biryani whose chicken block is `rate 200`,
`quantity_per_unit 0.5`, with chicken at 320/KG in the Material Rate Book:

```
charged for chicken     10 × 200        = 2,000
chicken drawn from store 10 × 0.5       = 5 KG
chicken actually costs   5 × 320        = 1,600
```

**Selling price is not material cost.** A 10 KG line whose blocks total 700/unit
charges 7,000 and costs 1,600. The gap is the margin, and it is only visible
because the two are computed apart.

> Never display, store, or snapshot the commercial block total as cost.

### 5 · Charge blocks

Making, packing, live-counter setup, labour — money with no material behind it,
so they never touch stock. Two bases:

- **per-unit** — `rate × quantity`. Making 500/KG on 10 KG = 5,000.
- **lump sum** — charged once however large the order. Setup 3,000 is 3,000 at
  10 KG and 3,000 at 100 KG.

A lump sum is deliberately excluded from the per-unit rate, because a rate
carrying a flat fee is wrong at every quantity except the one it was divided by.

### 6 · Customer-supplied material is a booking-line decision

If a customer brings their own chicken for one event, that block drops out of
**both** the charge and the store requirement for **that line only**:

```
charge contribution  -> 0
kitchen requirement  -> 0
other blocks (making) -> still charged
dish definition       -> unchanged
```

Charging for material the customer brought, or asking the store for it, are each
wrong on their own and wrong together. One customer's arrangement never edits the
dish everyone else is quoted from.

### 7 · A sent quotation is frozen

A quotation stores what it charged. Later changes to a recipe, a block, a
material rate or a making charge **do not rewrite it**.

- **Draft** — may be repriced, explicitly, by an operator who chose to.
- **Sent / Accepted** — never moves automatically. Ever.

### 8 · Readiness dispatches per line, and the orchestrator fails closed

One estimate may legitimately mix modes, so readiness is resolved **per line**
against that line's own active authority — never once for the document:

```
Estimate
   ├─ Chicken Karahi  → blocks → CateringCostBlockService
   ├─ Biryani         → recipe → CateringRecipeCostingService
   └─ Chicken Handi   → blocks → CateringCostBlockService
                    ↓
            aggregate readiness
```

The estimate is ready only when **every** line is ready under its own authority.
Dormant configuration takes no part in the verdict.

The two engines never call each other. A thin orchestrator owns dispatch, and it
owns **both** readiness and the cost snapshot — because these are the same
question asked twice:

> `expectedMaterialCost()` is a calculator. It is read-only and does not throw;
> a material with no rate is excluded and reported by `readiness()` instead.
> **Called without checking readiness first, it returns an understated number.**

So the orchestrator checks readiness *itself* before producing a snapshot and
refuses an unready estimate. Three callers each remembering to check is three
chances to forget, and the failure would not look like a failure — it would look
like an unusually good margin.

**A wrong-but-plausible cost is worse than a blocked quotation.**

### 9 · Block mode moves price; recipe mode moves margin

Both start from the same event — a material rate changes — and mean different
things, because in block mode the customer's price is *constructed* from the
material and in recipe mode it is not.

```
Raw Chicken 200 → 250

BLOCK MODE                      RECIPE MODE
  Chicken Karahi                  Biryani
  price  700 → 750                price  900 → 900   (unchanged)
                                  cost   380 → 410
                                  margin 520 → 490
```

Recipe-mode cost movement must never be turned into a price movement
automatically. Both are explained by one Rate Impact screen so an operator can
see at a glance *what* changed and *whether price or only margin moved*.

### 10 · Material Rates is the single authority for market rates

Four screens, four questions, and they do not overlap:

| Screen | Question |
|---|---|
| Product Catalog | What **is** this item? |
| Material Rates | What is this material worth **today**? |
| Cost Blocks | How is this dish's price **built** from its parts? |
| Rate Impact | What does a rate change **affect**? |

Daily market-rate edits belong in **Catering → Material Rates**, not in generic
catalog pricing. Saving a rate never reprices a booking — it feeds Rate Impact,
where an operator reviews and chooses what to apply to eligible drafts.

Making is **not** a material rate. It is a charge block, adjusted through its own
bulk flow.

---

## Part 2 — Operator parity backlog

Requirements observed in the client's old software. **Recorded, not scheduled.**
None of this is built in the current tranche.

### A · Item-wise kitchen instructions

The old system has a managed vocabulary of instructions, multi-selected per
booking line: *Mirch Kam*, *Chawal Dana Dana*, *Gosht Gala Hua Ho*, *Oil Kam*,
*Koyala*, and roughly fifty more.

Needed: an instruction master (Roman-Urdu label, Urdu label, active flag),
multi-select per booking line, optional free note alongside, and the selections
printed per dish on the kitchen sheet.

Today's free-text-only field is not parity — it is how the kitchen ends up
receiving four spellings of the same instruction.

> **Blocked on the client:** the authoritative ~55-entry list must come from an
> export. Do not invent the vocabulary.

### B · Calendar dot and date modal

The calendar exists. The refinement is presentational: a date cell shows **one
indicator with a count**, not every booking crowded into the square. Clicking it
opens a panel listing that date's bookings with the fields an operator actually
needs — booking number, customer, phone, event/delivery time, venue, PAX, status.

Not a new calendar engine.

### C · Bulk kitchen and document printing

Select several bookings by checkbox, then print kitchen sheets or estimate
documents for all of them. Reuses the existing print transport — **no second
print queue**. Printing moves no stock and posts nothing.

### D · Bulk address printing

A logistics document: booking number, date, time, customer, phone, venue or
delivery address, for a selected set of bookings. Reuses existing A4/browser
print.

### E · Search

Address search, customer/booking search, and possibly a catering-wide search.

> **Not specified enough to build.** Fields searched and result types must be
> defined first; a speculative "global search" is how you ship something nobody
> can predict.

### F · Dashboard refinements

- Upcoming / next 7 days, rather than a last-7-days-only view.
- Monthly navigation and refresh behaviour on the calendar.
- Guests shown where it is operationally useful.
- **"Net Sale Today"** — requested, but its accounting meaning is undefined.
  Does it net refunds? Advances, or only invoiced revenue? Which tenant-local
  day boundary? **Do not guess this one.** A KPI that is confidently wrong is
  worse than an absent one, because people plan against it.

---

## Sequence

```
DONE     finance    customer credit, refunds, booking statement   (production 9b4ad86)
NOW      Phase A    costing source UI, block configuration, per-line orchestrator
THEN     Phase B    booking-line block snapshot + customer-supplied material
THEN     Phase C    kitchen requirement sheet wiring
THEN     Phase D    Rate Impact — block price vs recipe margin
THEN     Phase E    making bulk adjustment
LATER    parity     calendar modal · instructions · bulk print · address print · search · dashboard
```

Each ships the same way: build → targeted tests → full suite twice → Pint and
Blade lint → commit → verified backup → deploy → read-only Khatri check.
