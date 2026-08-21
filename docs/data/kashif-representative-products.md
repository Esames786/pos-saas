# Kashif Catering — Representative First-Setup Set

**Prepared:** 2026-08-21
**Source:** client export screenshots in `public/menu` (32 unique captures, 941 product rows)
**Status:** SUGGESTION ONLY

> ## OWNER MUST CONFIRM THE ACTUAL ACTIVE MENU
>
> Nothing below is a confirmed seller. These fifteen were chosen because between
> them they exercise **every shape the Cost Block grammar has to support** — not
> because anybody has said Kashif sells them this month.
>
> The owner decides what goes live. This list exists so that whatever they choose,
> the shapes have already been proved on real data first.

---

## Why these fifteen

Setting up 888 items before anyone has checked that the model holds is how a
catalogue becomes 888 items of the wrong shape. Each row below tests something
different, and together they cover the whole grammar:

| # | Item | Shape being tested | What breaks if it is wrong |
|---|---|---|---|
| 1 | **Chicken Biryani** (`1`, seq 211) | material blocks + making charge, per-KG | the core dish model — chicken 100/KG × 0.5 + rice + making |
| 2 | **Karahi Chicken** (seq 365) | two materials, different ratios | shared material across dishes consolidating correctly |
| 3 | **Handi Chicken** (`519`, seq 362) | a third dish on the same materials | aggregate store requirement across three dishes |
| 4 | **Seekh Kabab** (seq 539) | count-based dish, unit is PCS not KG | unit handling where the quote unit is not weight |
| 5 | **BBQ Platter Large** (`863`, seq 1723) | multi-material platter | a dish whose materials come from several dishes |
| 6 | **Naan Plain** (`267`, seq 911) | high-volume, low-value, PCS | rounding and per-piece pricing |
| 7 | **Cold Drink 500ml** (`597`, seq 1503) | bought-in, material 1:1 | a stock item sold as-is with no making |
| 8 | **Waiters** (`531`, seq 1750) | **charge block, per unit** | labour: quantity is head count, **store issues nothing** |
| 9 | **Decoration / Arrangement** (`553`, seq 2000) | **charge block, lump sum** | flowers and staging — one price whatever the pax |
| 10 | **Tissue Paper Box** (`771`, seq 2051) | **material block 1:1, BOX** | a disposable that IS charged and DOES leave stock |
| 11 | **Packing Material F/P Large** (`656`, seq 2022) | packing material 1:1 | packing charged separately from the dish |
| 12 | **Fans** (`789`, seq 2042) | **charge block per unit, rental-like** | equipment: charged per fan, no stock movement |
| 13 | **Mutton** (`588`, seq 2008) | **raw material sold directly** | needs a sellable wrapper over the stock material, 1:1 by KG |
| 14 | **Chicken Biryani with customer-supplied rice** | **Customer Supplied** | kitchen needs it, our store issues zero — the two-number rule |
| 15 | **Tandoor (Live)** (`140`, seq 922) | **lump-sum live counter** | a counter hire that does not scale with quantity |

---

## What each one proves

**Rows 1–7 — the food model.** Ordinary dishes. If Biryani, Karahi and Handi all
draw chicken and the store sheet shows **one** chicken line with the three
bookings' quantities added, the snapshot-based requirement authority is working.

**Row 8 — labour.** A waiter is a `charge` block, `per_unit`. Ten waiters bills
ten × the rate and the storeman is asked for **nothing**. If a waiter ever
appears on a store issue sheet, the block type is wrong.

**Row 9 — the lump sum.** Decoration is where flowers live. It must be
`lump_sum`, so a 200-pax and an 800-pax wedding are charged the same 25,000
unless somebody changes it deliberately.

**Row 10 vs Row 8 — the distinction that matters most.** Both are "non-food".
A tissue box is a **material** — charged *and* drawn from stock. A waiter is a
**charge** — charged and drawn from nothing. Getting these two right proves the
model can express the whole 2000-series.

**Row 13 — the wrapper pattern.** Kashif sells raw mutton by the kilo. Catering
Materials are deliberately non-sellable, so this needs a sellable product whose
single material block points at the stock mutton at ratio 1. **This is the
pattern the UI never teaches**, and it is worth walking the owner through once.

**Row 14 — the two numbers.** Quote the biryani, mark rice customer-supplied.
Kitchen sheet must still say 8 KG of rice; our store requirement must be 0.
If the rice disappears from the kitchen sheet, something is still conflating the
two.

**Row 15 — counters.** Live tandoor, jalebi stall, ice cream counter, chocolate
fountain, pan stall, salad bar — the export has **at least 30 of these**. All are
almost certainly lump sums. Proving one proves the pattern.

---

## What is still missing for every one of them

The export has `Code #`, `Description` and `Sequence`. It has **no price, no
unit, and no material list**.

So even for these fifteen the owner must supply, per item:

- the **quote unit** (KG / PCS / BOX / head / event)
- the **customer charge**
- either the **materials and quantities** consumed, or a flat **making charge**
- whether it is **stock tracked**
- whether **customer-supplied** is ever possible for it

Use `kashif-active-menu-owner-input.csv` to collect this. Fifteen rows filled in
correctly are worth more than 888 rows guessed at.

---

## Suggested order

1. Owner marks **Y** against what Kashif actually sells this month.
2. Fill the commercial columns for the fifteen above **first**, whether or not
   they are all sellers — they are the shape test.
3. Set them up through the ordinary screens and run one real quotation end to
   end: quote → cost details → send → production → store issue.
4. Only then work through the rest of the owner's **Y** list.
5. The remaining ~800 rows wait for the staged importer, post-release.
