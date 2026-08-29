# Catering product model — what is wrong, and the work to fix it

**Status:** plan only. Nothing in here is built yet.
**Verified against:** production, 15 August 2026, `55949b2`.
Every claim below was read from source or measured on a live tenant. Where a
number appears, it was counted, not estimated.

---

## Part 1 — What is actually wrong

Nine defects. Ordered by damage, not by effort.

### D1 · Kashif's 14 materials are truly invisible ⚠️ blocks UAT

Measured across all 9 production tenants:

| Tenant | Hidden but reachable | **Truly invisible** |
|---|---|---|
| demo | 19 | 0 |
| enterprisedemo | 15 | 0 |
| **kashifkitchen** | 0 | **14** |

demo and enterprisedemo hide materials behind the *Include shared materials*
checkbox on the Manufacturing screen — annoying, but findable. Kashif's 14 are in
**no list at all**, because they carry `product_kind = sale_item` instead of
`raw_material`.

- Catalog list needs `pos_visible ∨ sellable ∨ recipe ∨ service` → they have none
- Materials list needs `bom_component ∨ bom_output ∨ finished_good` → they have none
- The "shared materials" escape hatch needs `kind ∈ {raw_material, packaging_material}` → they are `sale_item`

They survive only because Recipes and the Rate Book hold them by ID. **This is a
provisioning error we made, not a platform bug.** It is why searching "chicken"
finds one dish, and why Materials renders empty.

### D2 · The Materials screen cannot show a kitchen's materials ⚠️

Even with D1 corrected, the Materials screen still would not list them. Its
filter demands `can_be_bom_component = 1` — a **manufacturing** concept. A kitchen
has no BOMs. Correctly-created kitchen ingredients (`raw_material`, no BOM flag)
land in the "shared materials" bucket, which is revealed only by a checkbox on the
**Manufacturing** screen — a screen a catering-only plan cannot open.

So the escape hatch exists but is behind a door catering has no key to.

### D3 · Creating a material saves the wrong flags ⚠️ platform-wide

The form pre-selects a type card but does **not** apply that card's defaults on
first paint:

```
applyMode($currentMode, false)   // withDefaults = false
```

On a blank Create form the card reads *Manufacturing Raw Material — hidden from
POS*, while the badges underneath read **"Appears in POS · Sellable · Stock
Tracked"** and the checkboxes agree with the badges, not the card.

Fill in a name, press Save, and you get a **POS-visible sellable product** even
though the screen said raw material. The defaults only apply if you happen to
click the card that is already selected. This affects every tenant, not just
catering, and is a plausible source of bad product data across the platform.

### D4 · Manufacturing vocabulary on a catering screen

`/catering/materials/create` reads: *"Back to Manufacturing Products"*,
*"materials and finished goods used in **manufacturing**"*, *"consumed in
**BOMs**"*, *"produced through **WIP/FG receipts**"*, and offers **Manufacturing
Raw Material** / **Manufacturing Finished Good** cards plus *Can be BOM
Component / Can be BOM Output / Manufactured Finished Good* checkboxes.

A caterer buys mutton. None of those words mean anything to them. Cause: the
consumed-products filter is named after a **module** (`manufacturing`) instead of
a **role** (consumed vs sold), so the module's language travelled with the code.

### D5 · Manufacturing cards appear on a plan without manufacturing

The cards are gated on `$mfgAvailable`, which is a **permission** check:

```php
$u->can('tenant.manufacturing.bom.index') || $u->can('tenant.manufacturing.products.index')
```

`deploy.sh` grants the Owner every `tenant.*` permission regardless of plan, so
this is `true` for Kashif even though the module is entitlement-**denied**. Same
root cause as the sidebar leak fixed in `1da0bc4`: **`@can` is not an entitlement
decision.**

### D6 · Product types are not filtered to the tenant's world

A catering-only tenant is offered *POS Sale Item* ("sold at the till") and the
`Hybrid` product type. There is no till. Meanwhile the types that genuinely serve
catering — service charges, packing material — sit unlabelled among them.

### D7 · Type card text contradicts the badges on edit

Editing *Salad Plate*: the card says *"Recipe / Kitchen Item — **Sold in POS**"*
while the badge directly below says **"Hidden From POS"**. Both are "true" — the
card describes the archetype, the badge describes this product — but shown
together they read as a bug. For a catering tenant "Sold in POS" is meaningless
regardless.

### D8 · Tooltips cover only one screen

Only Events & Estimates has them. Catering Products, Materials, Rate Book, Rate
Impact, Printers, Settings and the Events list have none, and no screen carries a
"what this does / what it affects" line.

### D9 · Email reaches nobody

`MAIL_MAILER=log`. Estimate-sent, advance and final-invoice mails are recorded in
`catering_email_logs` and written to the Laravel log. **No customer has ever
received one.** Reminders (D-7 / D-3 / D-1 / same-day) depend on the same dead
transport and on the scheduler running `catering:reminders`.

---

## Part 2 — The work

### Phase 1 — Stop the bleeding · ~1 slice

| | Fixes | Change |
|---|---|---|
| 1.1 | **D3** | Apply the selected mode's defaults on first paint. Guard against clobbering a real product on edit — defaults apply on create only. |
| 1.2 | **D1** | Reclassify Kashif's 14 materials: `product_kind = raw_material`, `is_purchasable = 1`, `is_stock_tracked = 1`. Metadata only — no transaction, stock row or ledger entry references these flags. **Needs the owner's word.** |
| 1.3 | **D2** | Materials list includes `raw_material` and `packaging_material` by default. For a kitchen these *are* the materials; there is nothing to opt into. |

**Exit:** searching "chicken" finds it, Materials lists all 14, saving a new
material stores what the screen promised.

### Phase 2 — Name things by role, not by module · ~1 slice

Fixes **D4**, **D5**, **D6**, **D7**.

Redefine the consumed-products filter by what it *is* — **products consumed
rather than sold** — and give each vertical its own vocabulary over the same
query and the same proven form.

| Manufacturing sees | Catering sees |
|---|---|
| Manufacturing Raw Material — BOM component | Ingredient — consumed by your recipes |
| Manufacturing Finished Good — produced via BOM | *(hidden)* |
| Back to Manufacturing Products | Back to Materials |
| consumed in BOMs / WIP/FG receipts | what your dishes are made from |
| Can be BOM Component / Output / Manufactured FG | *(hidden)* |

Card visibility switches from `@can` to **entitlement** (`hasModule`), closing
D5. Type cards filter to the tenant's world (D6), and the card blurb defers to
the badges instead of contradicting them (D7).

**Non-regression:** a manufacturing tenant sees byte-identical wording and
identical cards. Proven by test, not by inspection.

### Phase 3 — Explain every screen · ~1 slice

Fixes **D8**. Tooltip on every action button naming its effect, plus a header
line per screen: what it is for, what it changes, what it does *not* touch.
Impact badges — 🟢 safe / 🔵 finance / 🟠 stock / 🔴 irreversible — so the cost
of a click is visible before the click. English and Urdu.

### Phase 4 — Email · blocked on the owner

Fixes **D9**. Two routes:

| | Relay (recommended) | Self-hosted Postfix |
|---|---|---|
| Work | SMTP host, user, password in `.env` | Postfix + OpenDKIM + SPF + DKIM + DMARC + PTR |
| Time | ~15 min | hours, plus reputation warm-up |
| Deliverability | established from day one | built from zero on a VPS IP range |
| Debugging | dashboard shows the bounce | mail logs and blocklist checks |

**Two corrections to the brief as written:**

1. The domain is **`bingoopos.com`**, not `bingoo.com`. Every tenant runs on it.
   Records built for `bingoo.com` would fail SPF immediately.
2. **PTR / reverse DNS is set at Hostinger, not Namecheap.** Without
   `187.77.140.39 → mail.bingoopos.com`, Gmail and Outlook reject or spam-folder
   regardless of how correct SPF and DKIM are. It is the single most common
   reason self-hosted mail fails and the one step Namecheap cannot perform.

Then: manual **Email to Customer** button with resend (none exists today — email
only rides along with send / advance / invoice), and a scheduler entry for
`catering:reminders`.

### Phase 5 — Remaining agreed backlog

- Print options on Estimate and Final Invoice — manual / network / thermal.
  Only the kitchen sheet reaches the network today.
- Cancel reason field, and explicit handling of an advance already received.

---

## Part 3 — Decisions needed

| # | Question | Blocks |
|---|---|---|
| 1 | May we correct the 14 materials' classification flags? | Phase 1.2 — asked three times, unanswered |
| 2 | Email: relay or self-hosted Postfix? | Phase 4 |
| 3 | If self-hosted: will you set the PTR record at Hostinger? | Phase 4 |
| 4 | Confirm Urdu-on-thermal stays out of scope | Phase 5 |

---

## Part 4 — Constraints on all of it

- **No shared authority is modified.** `InventoryService`, `JournalPostingService`,
  POS, KOT and `PrintJobService` are called, never changed.
- **Split commits.** Anything touching shared code lands as its own PLATFORM
  commit, revertible alone.
- **Gates before deploy:** full MySQL suite with zero exclusions, compiled-Blade
  PHP lint, restricted-tenant route matrix, plus an explicit Khatri/demo
  non-regression pass and a trial-balance check across all 9 tenants.
- `view:cache` is **not** a gate. It reported success on views that could not
  parse. `tests/Unit/CompiledBladeSyntaxTest` is the real signal.
- **Kashif data preserved:** Estimate #8353, its 28 July 2026 date, products,
  Urdu translations, recipes, rates, printer mappings, users.
