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

## 2.4 Proposed design

### (a) A deal is filed under the deal's category

```
byCategory():  group on  COALESCE(cb.category_id, p.category_id)
                         └─ a combo line files under the COMBO's category;
                            everything else is unchanged
```

Only combo lines move. A normal product's row cannot shift, because it has no `combo_id`. The
grand total does not change — **money moves between category rows, nothing is created or lost.**

### (b) A new `deals` section

`SalesReportEngine::byDeal($f)` — one row per combo, grouped by its category, with qty, gross,
discount, returns and net. This is the section the old software calls DEALS / MIDNIGHT DEAL 1 /
CLASSIC PLATTER, all in one table with subtotals per head.

### (c) Items stops carrying deals

`byItem()` gains `excludeCombos: true` for the Items section, so a deal appears **once** — in the
Deals section — and never in Items. `orderTypeCombos()` (the "By Order Type" section) reuses
`byItem`, so it inherits the same rule and must be checked, not assumed.

> ⚠️ **The one thing to be careful about.** Today the Items section is the only place a deal shows
> at all. If Items excludes deals and someone prints Items *without* Deals, the report's item total
> stops reconciling with Net Sales. The two must therefore be **linked in the UI**: ticking Items
> also ticks Deals unless the user deliberately unticks it, and the Items table carries a footer
> line saying how much sits in Deals. Otherwise this fix creates a new way to read a wrong total —
> which is exactly what we have spent two days removing.

## 2.5 Every screen this touches

The section list is one array, and everything downstream reads it — so the work is one section plus
its renderers, not eleven separate jobs.

| Screen | What changes |
|---|---|
| **Report Center** — screen | new `deals` checkbox + a Deals tab; Categories now files deals correctly |
| **Report Center** — Print All / Print Selected (A4) | new section in the A4 blade |
| **Report Center** — Print All / Print Selected (Thermal) | new block in `EscPosPayloadService` |
| **Report Center** — Export All / Export Selected CSV | new block in `SalesReportExporter` |
| **Report Center** — Email Now | rides the A4 document, no extra work |
| **Report Center** — Send to Network | rides the thermal payload |
| **Z Report (End of Day)** | preset is Overview + Order Types + Categories + Waiters + Payments + Cash & Bank — **decision needed: add Deals?** The old software's Z report has deal heads, so probably yes |
| **POS Quick Report** — modal | `SECTIONS` const + two checkboxes |
| **POS Quick Report** — Print here / Send to network / Email to owner | same document service, inherits |
| **Nightly cron email** | `report_schedules.sections` — Kashif's schedule #1 would need `deals` added, a data step |

Files: `SalesReportEngine`, `SalesReportDocumentService`, `SalesReportExporter`,
`EscPosPayloadService`, `SalesReportCenterController`, `PosQuickReportController`, the Report Center
blades and the Quick Report modal.

## 2.6 What must not move

| Risk | Answer |
|---|---|
| Net Sales changes | It must not. Deals move **between** category rows. Guard test: the grand total before and after is identical, to the paisa. |
| A deal counted twice | The point of the change. Guard test: a deal appears in Deals and **not** in Items, and the two sections plus the rest sum to Net Sales. |
| A combo without a category | Falls back to the product's category — today's behaviour — so nothing breaks while the owner is still filing deals. Guard test covers it. |
| Khatri | Its combos have no `category_id`, so the fallback keeps it exactly as it is today. Worth confirming before deploy, not assuming. |
| The nightly email silently loses deals | The schedule's stored `sections` will not contain `deals`. It needs adding as a deliberate data step, or the owner's 02:30 report will show Items without deals. **This is the easiest thing to forget.** |

## 2.7 Questions for the owner

1. **Z Report preset** — add Deals to it? (The old software's Z report has the deal heads, so I
   would say yes.)
2. **The three shelving differences** in §1.4 — move Extra Sauce / Garlic Fried / Plain Rice into
   Singaporean Rice, and Ustad rolls into Chicken Roll, to match the old paper? Or keep ours?
3. **"Parhata"** → "Paratha": correct our typo?
4. **Deal categories that do not exist yet.** The Z report shows heads we have no combo category
   for — CHULLU KEBAB, FAMILY DEAL, PLATTER2, POCKET FRIENDLY. Some of our combos are already
   filed under Pocket Friendly and Meal Deal. Should the deal categories be renamed to match the
   old software's heads exactly, so the two papers read the same?

## 2.8 If approved, the build order

1. `byCategory` files a combo under the combo's category — with the grand-total-unchanged guard.
2. `byDeal()` + the `deals` section through all six renderers.
3. `byItem` excludes combos; Items and Deals linked in the UI so a total cannot be read short.
4. Guard tests, each proven to bite; the reconciliation test is the one that matters.
5. Prod before/after snapshot for both tenants, as the deal-identity change was done.
6. Deploy, then re-read the same figures on production, then add `deals` to Kashif's 02:30
   schedule.
