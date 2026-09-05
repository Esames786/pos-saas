# Deals belong under their own heads — research

**Status:** RESEARCH ONLY. Nothing built, nothing deployed.
**Date:** 2026-09-02 · **Tenant:** Kashif Food (#348)
**Source:** the client's old-software **Z Report of 19 Aug 2026** (WhatsApp photos, 7 pages,
grand total **1,126 items / 613,970**) compared against our Report Center.

Two questions were asked. They are answered separately below, because only one of them turns out
to need code.

---

# Part 1 — Do the products match?

## 1.1 Method

The Z report prints quantity and amount per line, so the unit price is `amount ÷ qty`. Every line
legible in the photographs was reduced that way and compared with tenant #348's live
`default_selling_price`.

## 1.2 Result: every price that can be read matches. Not one is out.

A sample across the card, old software → ours:

| Item | Z report (÷ qty) | Ours |
|---|---|---|
| Singaporean Rice (Regular) | 128,400 ÷ 214 = **600** | 600 ✅ |
| Singaporean Rice (Large) | 71,400 ÷ 68 = **1,050** | 1,050 ✅ |
| Singaporean Rice (Platter) | 12,640 ÷ 8 = **1,580** | 1,580 ✅ |
| Singaporean Khass | 14,500 ÷ 5 = **2,900** | 2,900 ✅ |
| Family Pack (Large / Small) | **4,100 / 2,750** | 4,100 / 2,750 ✅ |
| Plain Rice | 340 ÷ 2 = **170** | 170 ✅ |
| Chicken Tikka Chest | 17,550 ÷ 27 = **650** | 650 ✅ |
| Chicken Malai Boti | 8,050 ÷ 7 = **1,150** | 1,150 ✅ |
| Adana Kebab Beef | 2,900 ÷ 2 = **1,450** | 1,450 ✅ |
| Soft Drink (345 ml) | 19,200 ÷ 160 = **120** | 120 ✅ |
| Mineral Water (Small / Large) | **80 / 160** | 80 / 160 ✅ |
| Biryani Chicken (Small) | 20,800 ÷ 52 = **400** | 400 ✅ |
| Chicken Zinger Burger (w/ fries) | 16,800 ÷ 21 = **800** | 800 ✅ |
| Chicken Chow Mein (1 Person) | 4,800 ÷ 4 = **1,200** | 1,200 ✅ |
| Fish and Chips (5 Pcs) | 4,470 ÷ 3 = **1,490** | 1,490 ✅ |
| Paratha / Paratha Large | **80 / 150** | 80 / 150 ✅ |
| Raita | 1,900 ÷ 19 = **100** | 100 ✅ |
| Cream Cocktail (Cup) | 2,800 ÷ 14 = **200** | 200 ✅ |

**So the catalogue is sound.** What differs is spelling and shelving, not money.

## 1.3 The outliers — spelling

Ours reads differently from the old software on these. None changes a price; two are our own typos
and worth correcting because the owner reads both papers side by side:

| Old software | Ours | Note |
|---|---|---|
| PARATHA / PARATHA LARGE | **Parhata** Small / **Parhata** Large | ⚠ our typo |
| CHICKEN KRUNCH BURGER | Chicken **Crunch** Burger | old software's typo |
| THRIL ZINGER BURGER SPICY | **Thrill** Zinger Burger (Spicy) | old software's typo |
| CHIKCEN GARLIC BOTI | Chicken Garlic Boti | old software's typo |
| BEEF BEHARI BOTI | Beef **Bihari** Boti | spelling variant |
| BEEF DHAGAH KEBAB | Beef **Dhaga** Kebab | spelling variant |
| CHICKEN BALUCHI BOTI | Chicken **Balochi** Boti | spelling variant |
| CHICKEN SHAHI CHATTAKH | Chicken Shahi **Chatakh** | spelling variant |

## 1.4 The outliers — which shelf an item sits on

This is the real difference, and it matters because it changes what a category subtotal says:

| Item | Old software's category | Ours |
|---|---|---|
| Extra Sauce · Garlic Fried · Plain Rice | **Singaporean Rice** | Extras |
| Ustad Special Roll · Ustad Special Chipotle Roll | **Chicken Roll** | its own "Ustad Roll" category |
| Salad | **Bar B Q** | Raita & Salad |

So a "Singaporean Rice" subtotal on the old paper includes the sauce and the plain rice; ours does
not. Neither is wrong — but the two papers will not agree line for line until one moves.

## 1.5 One structural difference worth naming

The old software prints a **DELIVERY** category with delivery charges as line items
(`DELIVERY CHARGES(50)` ×3, `(100)` ×12, `(200)` ×7, `(300)` ×1, `(150)` ×8, `(250)` ×2 —
33 lines, 4,750). In our system a delivery charge is a **field on the order**, not a product, and
appears in the overview as *"Plus Delivery & Other Charges"*.

Same money, different shape. Turning it into line items would mean inventing products, which is the
one thing a report must never do. Worth telling the owner so the two papers are not expected to
match on this row.

## 1.6 What Part 1 cannot answer from a photograph

A complete line-by-line audit needs the old software's **export**, not a picture. The Z report is a
single day, so an item nobody sold on 19 August cannot appear on it at all. If the owner wants a
full catalogue reconciliation, the old software's item list is the input to ask for.

---

# Part 2 — Deals under their own heads

This is the part that needs code.

## 2.1 What the old software does

Its item report has a category head per deal family, each with its own subtotal:

```
POCKET FRIENDLY      3 items      2,550
DEALS               16 items     24,020     Deal 1 Serve 1, Deal 2 Serve 2, Deal 5/6/8/12/13,
                                            Singaporean and Zinger Deal
PLATTER              5 items      8,250     Grille Chicken Al-Faham (Half)
CHULLU KEBAB         4 items      7,700     Chullu Kebab Beef / Chk, Baluci Boti Rice
MIDNIGHT DEAL 1     42 items     29,650
FAMILY DEAL          1 item       3,250
PLATTER2             1 item       1,650
CLASSIC PLATTER      4 items     14,300
```

A deal is one line under its own head. Its parts are never listed, and never counted anywhere else.

## 2.2 What ours does

Two fixes have already landed and they got us most of the way:

| | |
|---|---|
| `REPORT-DEAL-COMPONENTS-1` (31 Aug) | a deal's parts stopped counting as separate sales |
| `REPORT-DEAL-IDENTITY-1` (1 Sep) | a deal reports under **its own name**, not the product its header sits on |

What is still wrong is **which head the deal's money is filed under**. `SalesReportEngine::byCategory`
groups on `p.category_id` — the *product's* category. A combo has no product of its own; its
`combo_header` line sits on whatever product it was built from. So the deal's money lands in that
product's category.

### Measured on production — Kashif Food, 1 September

**94,705.00 of deal money is filed under the wrong head that day.** The whole list:

| Deal | Filed under now | Should be | Qty | Amount |
|---|---|---|---|---|
| Classic Platter 3 (3 Persons) | **Extras** | Platters | 8 | 26,400.00 |
| Singaporean Rice (Regular) (Midnight) | Singaporean Rice | **Midnight** | 36 | 19,800.00 |
| Singaporean Rice Khass (2–3 Persons) | **Bar-B-Que** | Platters | 4 | 11,600.00 |
| Chicken Zinger Burger + Fries + Drink | Burgers | **Midnight** | 5 | 4,000.00 |
| Exclusive Deal 1 – 2 Zinger + 2 S… | Burgers | **Exclusive Deals** | 1 | 2,500.00 |
| Deal 2 (Serve 2) | Singaporean Rice | **Deals** | 2 | 2,380.00 |
| Deal 15 (Serve 2) | Bar-B-Que | **Deals** | 1 | 2,120.00 |
| Deal 13 / 8 / 7 / 12 / 6 / 3 / 1 / 11 (Serve 2) | Singaporean Rice, Chicken Biryani, Sandwiches | **Deals** | 9 | 13,805.00 |
| Balochi Boti Rice (1–2 Persons) | **Extras** | Platters | 1 | 1,900.00 |
| 2 Chicken Zinger Burger + 2 Pcs C… | Burgers | **Meal Deal** | 1 | 2,000.00 |
| six more Midnight deals | Bar-B-Que, Burgers, Sandwiches, Crispy Fried Chicken | **Midnight** | 7 | 6,450.00 |
| Chicken Crunch Burger + Crispy Fr… | Burgers | **Pocket Friendly** | 1 | 750.00 |

Read the first row again: **"Classic Platter 3" — 26,400 — is filed under Extras.** Extras is where
the cheese and the dinner rolls live.

**The good news: the right answer is already in the database.** `combos.category_id` was added on
30 August (`POS-COMBO-CATEGORY-1`) so the POS could group deal tabs, and it is populated —
Deals, Midnight, Platters, Exclusive Deals, Meal Deal, Pocket Friendly. It was declared
**display-only** at the time and reports were told to ignore it. That is the line to cross now.

## 2.3 What the owner asked for

1. Deals come **out of** the Items list.
2. Deals appear under **their own category heads**, as in the old software.
3. Items that belong to a deal are **not duplicated** in Items.
4. In Quick Report, Report Center and the emails: **two checkboxes** — one for **Deals**, one for
   **Items + Categories**.

Point 3 is already true since 31 August — a deal's components no longer appear as separate sales.
What is left is 1, 2 and 4.

## 2.4 The chosen design — one definition, read at report time

Two solutions were weighed. The second wins, and the reason is history.

| | **A · give every deal its own product** | **B · read the deal's own category** ✅ |
|---|---|---|
| How | A hidden product per combo in the right category; the money lands on it | The deal's category is already stored; the report uses it when the line belongs to a deal |
| **Existing sales** | **Stay wrong.** 182 lines are already written against Arabic Rice. Fixing them means editing bills that have been sold. | **Correct themselves.** Every one of those 182 lines already carries its `combo_id` — **zero are missing it** (verified on production). Yesterday's report becomes right the moment this ships. |
| Blast radius | Changing which product a header lands on touches the POS, the KOT and the reprint | Nothing is written. A read-time interpretation, reversible by reverting one query |
| Work | ~74 products per tenant + an onboarding rule that must never be forgotten | One expression |

**Verified on production before choosing:**

```
KASHIF FOOD   combo_header lines = 182     combo_id missing = 0      (every line carries it)
              combos = 74                  with their own category = 74   (all of them)
KHATRI        combo_header lines = 0       combos = 0                (cannot be affected)
```

### 2.4.1 The rule

```
reporting category of a line  =  COALESCE(combos.category_id, products.category_id)
```

A combo line files under the combo's category; everything else is untouched, because a normal
product line has no `combo_id`. A combo whose category is not set falls back to today's behaviour.

### 2.4.2 The condition that matters more than the rule

The category is worked out in **three** places today. Patching three `COALESCE`s would fix
September and lose again in October, because the fourth place written next month would not know the
rule. **That is precisely how this family of bugs keeps returning** — the quotation against the
kitchen sheet, the report against the deal identity, the engine against six legacy queries, three
times in one week.

So it gets **one definition in one place**, the way `businessDayExpr()` is already the single
definition of the business day:

```php
/** The category a report should file this line under. */
private function lineCategoryExpr(): string
{
    return 'COALESCE(cb.category_id, p.category_id)';
}
```

…and `linesBase()` / `returnLinesBase()` gain the `combos as cb` join once, so **every** consumer
of those two builders inherits it without being told. A new report written next month is right by
default rather than by memory.

### 2.4.3 Exactly where it is read today

Traced line by line through `SalesReportEngine`, so nothing is patched by guesswork:

| Where | Line | What it does now | After |
|---|---|---|---|
| `salesBase()` category filter | 101–105 | `whereExists` lines→products, `fp.category_id` | joins `combos`, uses the expression |
| `salesBase()` category_ids filter | 107–111 | same | same |
| `linesBase()` category name join | 151 | `categories` on `p.category_id` | on the expression |
| `linesBase()` category filters | 152–153 | `p.category_id` | the expression |
| `returnLinesBase()` name join | 186 | `categories` on `p.category_id` | on the expression |
| `returnLinesBase()` filters | 187–188 | `p.category_id` | the expression |
| `byCategory()` grouping | 348, 350 | `groupBy('p.category_id')` | the expression |
| `byCategory()` returns | 359 | `returnStatsBy($f, 'p.category_id')` | the expression |
| `byCategory()` distinct-order rollup | 398 | `CASE p.category_id …` | the expression |
| `DepartmentReportService` | 57, 71, 73 | resolves department from `p.category_id` | the expression |

**The filter is the one that would have been missed.** If only the grouping moved, a deal would
appear under Platters but a report *filtered* to Platters would not find it — a fresh
inconsistency, and a worse one than the original because it looks deliberate.

---

## 2.5 Every screen this reaches — nothing left out

The category change and the new Deals section travel through the same renderers, so this is one
list, not two.

### 2.5.1 Report Center

| Screen / button | What changes |
|---|---|
| Categories section (screen) | deals file under their own head |
| **Category filter dropdown** | filtering "Platters" now finds the deals |
| Items section | deals leave it (they live in Deals) |
| **Deals section** (new) | new checkbox + tab |
| "By Order Type" section | reuses `byCategory` + `byItem` per order type — inherits both changes; **must be checked, not assumed** |
| Departments section | department resolution follows the same expression |
| Print All (A4) · Print Selected (A4) | new section in the A4 blade |
| Print All (Thermal) · Print Selected (Thermal) | new block in `EscPosPayloadService` |
| Export All CSV · Export Selected CSV | new block in `SalesReportExporter` |
| Email Now | rides the A4 document — inherits |
| Send to Network | rides the thermal payload — inherits |
| **Z Report (End of Day)** | its preset includes Categories, so it moves. **Add Deals to the preset?** (the old software's Z report has the deal heads — recommend yes) |

### 2.5.2 POS

| Screen / button | What changes |
|---|---|
| Quick Report modal | `SECTIONS` const + the two checkboxes (Deals · Items + Categories) |
| Quick Report → Print here | inherits |
| Quick Report → Send to network | inherits |
| Quick Report → Email to owner | inherits |
| Quick Report **category picker** | same filter fix — picking "Platters" finds the deals |

### 2.5.3 Scheduled / background

| | What changes |
|---|---|
| **Nightly cron email (02:30)** | `report_schedules.sections` is a stored list. Kashif's schedule #1 does **not** contain `deals`. Without a data step the owner's nightly report shows Items with the deals removed and no Deals section — **a total that does not reconcile.** This is the single easiest thing to forget in this whole change. |

### 2.5.4 Legacy screens (decide, don't drift)

| | Note |
|---|---|
| `/reports/sales/items` | prints a `category_name` column resolved from the product. It was brought back into the population yesterday; it should follow this rule too, or it becomes the next screen that disagrees. |
| `/reports/sales/summary`, `/payments`, `/channels`, `/riders` | no category column — unaffected |
| Restaurant → Tables / Waiters / Order Types | no category column — unaffected |

### 2.5.5 Deliberately NOT changed

**KOT routing.** A ticket routes on the **component's** product category, which is how the BBQ
items of a platter reach the BBQ printer. That is correct and must stay exactly as it is. This
change touches reporting only.

---

## 2.6 What must not move — and how each is proven

| Risk | Guard |
|---|---|
| Net Sales changes | It must not. Measured on 1 Sep: **596,995.00 before, 596,995.00 after.** Test asserts the grand total is identical to the paisa. |
| A deal counted twice | Test: a deal appears in Deals and **not** in Items, and Items + Deals + the rest reconcile to Net Sales. |
| A combo with no category | Falls back to the product's category. Test covers it. |
| The category filter disagrees with the grouping | Test: filter by the deal's category and the deal is returned. |
| Khatri | Zero combos — the change cannot reach it. Test asserts a tenant with no combos produces a byte-identical category table. |
| The nightly email quietly loses deals | Not a code risk — a **data step**, listed in the build order below so it cannot be skipped. |
| Departments shifts | Deals move into their deal category, so a department mapped on the old category loses them. **Check Kashif's department maps before deploy** — if none exist, nothing to do. |

---

## 2.7 Questions for the owner

1. **Z Report preset** — add Deals? (Old software has the heads; recommend yes.)
2. **Shelving** (§1.4) — move Extra Sauce / Garlic Fried / Plain Rice into Singaporean Rice, and
   the Ustad rolls into Chicken Roll, to match the old paper? Or keep ours?
3. **"Parhata"** → "Paratha": correct our typo?
4. **Deal head names** — the old software prints CHULLU KEBAB, FAMILY DEAL, PLATTER2. Ours are
   Deals, Midnight, Platters, Exclusive Deals, Meal Deal, Pocket Friendly. Rename ours to match
   exactly, so the two papers read the same?

---

## 2.8 If approved, the build order

1. `lineCategoryExpr()` in `SalesReportEngine`, the `combos` join in both line builders, and every
   one of the ten call sites in §2.4.3 switched to it.
2. `DepartmentReportService` follows the same expression.
3. `byDeal()` + the `deals` section through all six renderers (screen, A4, thermal, CSV, email,
   Quick Report).
4. `byItem()` excludes combos; **Items and Deals linked in the UI** so a total cannot be printed
   short.
5. Guard tests, each proven to bite. The reconciliation test — grand total unchanged — is the one
   that matters most.
6. Prod before/after snapshot for both tenants, as the deal-identity change was done.
7. Full suite → deploy → re-read the same figures on production.
8. **Add `deals` to Kashif Food's 02:30 schedule** (data step — see §2.5.3).
9. Check Kashif's department category maps (§2.6).

---

## 2.9 The worked example, on one page

A live before/after built from Kashif Food's actual 1 September data — the deal traced through the
database, the Categories section computed both ways, and the proof that the day's total does not
move:

**https://claude.ai/code/artifact/37e516b4-45fe-47c8-89d0-555a7aa0e8d2**

The headline from it: **596,995.00 before, 596,995.00 after.** Extras falls from 30,490 to 2,190,
and a Deals head appears at 94,705 — Platters 39,900, Midnight 31,250, Deals 18,305, Exclusive
Deals 2,500, Meal Deal 2,000, Pocket Friendly 750.

---

## 2.10 Splitting Platters into the old software's heads — owner-approved

One "Platters" head holds nine different things. The old software gives each its own, and the owner
asked for the same, **with Singaporean Rice Khass on a head of its own** ("khaas platter he hai —
us ko platter ke new head me daal do alag se").

| New head | Deals it holds | 1 Sep |
|---|---|---|
| **Classic Platter** | Classic Platter 1 (6 Persons) · 2 (4 Persons) · 3 (3 Persons) | 8 · 26,400 |
| **Singaporean Rice Khass** | Singaporean Rice Khass (2–3 Persons) | 4 · 11,600 |
| **Chullu Kebab** | Chullu Kebab Beef · Chullu Kebab Chicken · Balochi Boti Rice | 1 · 1,900 |
| **Platter 2** | Bar-B-Que Platter (4–5 Persons) · Turkiya Kebab (1–2 Persons) | none sold |
| Al-Faham | *already its own head* — matches the old paper's `PLATTER` | none sold |
| | **was: Platters** | **13 · 39,900** |

The four heads add up to exactly what Platters held. Deals stays 94,705; the day stays 596,995.

> **Note on the old paper.** The Z report files `SINGAPOREAN KHASS` under its **SINGAPOREAN RICE**
> head, not under a platter one. The owner's decision is that Khass *is* a platter and gets its own
> head — so on this row the two papers will differ by design, not by accident.

### 2.10.1 This needs no code

These are categories created in the Catalog screen with the deals moved into them. The report reads
whatever heads exist, so nothing in §2.4 changes.

**Where they must sit — and the reason is the POS, not the report.**

`byCategory` maps each line's category to its **ultimate root** (`rootMap()` is a recursive CTE)
and files it as `root → leaf`, skipping any level in between. So a head placed three deep
(*Deals → Platters → Classic Platter*) would still appear as a child row of **Deals** — the report
handles it either way, and an earlier note in this document claiming otherwise was wrong.

The POS is the constraint. Its deal strip renders `$cat->children` — the **immediate** children of
the selected parent, one level only. A head buried under Platters would never get a chip.

**So: create the new heads as direct children of `Deals`, and retire `Platters` once empty.**
That satisfies both screens.

### 2.10.2 Effect — checked, not assumed

| | Effect |
|---|---|
| Money | **None.** Same lines, same sums; only which row they print on. |
| Counts | **None.** 13 items stay 13, across four rows instead of one. |
| **KOT · reminder · receipt** | **None** — proven below. |
| POS "Deals" tab | Gains chips: Classic Platter, Khass, Chullu Kebab, Platter 2. Cashiers need **Ctrl+F5**. |
| Khatri Biryani | **None** — no combos at all. |
| Reprinting an **old** report | The head is read from the deal at report time, not frozen onto the sale, so an August platter reprints under its **new** head. Money identical; only the label differs from an earlier copy. |

**Why the kitchen cannot be affected — from `PrintRoutingService`, line 251:**

```php
// A combo header is a display row — the kitchen makes its COMPONENTS,
// and both renderers skip it.
if ($line->line_kind === 'combo_header') {
    continue;                       // the deal's own row never routes
}
…routes on  $line->product->category_id     // the COMPONENT's category
```

A ticket is routed by each **component's** product category — which is how a platter's kebabs reach
the grill and its rice reaches the counter. The combo is read for its **name** only, by id
(`COMBO-KOT-DEAL-NAME-1`). **`combos.category_id` is never read by any printing code**, so moving a
deal between heads cannot change a single slip.

### 2.10.3 Freezing the head onto the sale — considered and declined

History could be frozen by stamping the category onto every sale line at punch time, the way the
catering release snapshots its materials. Declined, for now:

- it needs a new column **and** a change to how the POS writes a sale — a write-path change for a
  reporting nicety;
- it only works **forward**, so August stays as it is regardless;
- and the drift it prevents is cosmetic: the money never moves, only the label.

Worth revisiting if the owner ever needs a reprint to match an earlier copy line for line.

### 2.10.4 The worked example

Both the split heads and the working Report Center are on the live page, built from 1 September:
**https://claude.ai/code/artifact/37e516b4-45fe-47c8-89d0-555a7aa0e8d2**
