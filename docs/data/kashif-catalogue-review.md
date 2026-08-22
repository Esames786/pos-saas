# Kashif Catering — Source Catalogue Review

**Prepared:** 2026-08-21
**Branch:** `data/kashif-catalogue-prep-v1` (from `origin/feat/14d-2-plan-upgrade-requests`)
**Source:** client export screenshots, `D:\laragon2\www\pos-saas-catering\public\menu`
**Method:** read-only transcription. No application code, no database, no product created.

---

## Register

```text
TOTAL_SOURCE_FILES=33
UNIQUE_SOURCE_FILES=32
EXACT_DUPLICATE_FILES=1
STAGING_CSV_ROWS=942
TOTAL_ROWS=941
EXACT_DUPLICATE_ROWS=52
UNIQUE_ITEMS=888
POSSIBLE_DUPLICATE_ROWS=26
DIRTY_NAME_ROWS=294
REJECT_REVIEW_ROWS=3
NON_FOOD_ROWS=67
OWNER_INPUT_REQUIRED_ROWS=749
OWNER_CONFIRMATION_REQUIRED_ROWS=189
SOURCE_CODE_TRUNCATED_ROWS=106
DUPLICATE_SEQUENCE_ROWS=221
REUSED_SOURCE_CODE_ROWS=6
```

`STAGING_CSV_ROWS` is 942 because the staging file carries one non-product
marker row recording that `9.47.05` is byte-identical to `9.46.26`. Every other
count below is over the **941 product rows**.

`EXACT_DUPLICATE_ROWS` are rows visible in two consecutive screenshots because the
captures overlap by one or two lines. They are **kept** in staging with the
overlap recorded, and collapsed once in the owner sheet — hence 941 rows but 888
distinct items.

`OWNER_INPUT_REQUIRED_ROWS` and `OWNER_CONFIRMATION_REQUIRED_ROWS` are the
`recommended_action` buckets; with the 3 reject candidates they account for all
941. The split is about the **name**: 189 rows need the owner to confirm what the
item actually is (truncated code, duplicate, dirty spelling, misfiled band)
before its commercial data is worth collecting. All 941 need the commercial data
itself — `owner_confirmation_required` is `yes` on every row, because not one of
them arrived with a price.

---

## The finding that decides the plan

**The export contains no commercial data at all.**

Three columns: `Code #`, `Description`, `Sequence`. There is no price, no unit, no
category, no material list, no cost.

A perfect importer would therefore produce **888 names and nothing quotable**.
Every one of them still needs the owner to say what unit it is sold in, what the
customer is charged, and what it consumes. The importer is not the bottleneck —
**the rate sheet is**, and it does not exist in this export.

That is why `kashif-active-menu-owner-input.csv` is the actual next artefact, not
an import script.

---

## Sequence bands observed

Sequence is a **grouping hint and nothing more**. Recorded in `suggested_group`,
never treated as authority.

| Band | Suggested group | Rows |
|---|---|---|
| 1–199 | starters, drinks, soups | 60 |
| 200–299 | rice & biryani | 98 |
| 300–499 | main dishes | 286 |
| 500–599 | kabab & BBQ | 89 |
| 600–699 | fried & grilled | 69 |
| 700–799 | snacks & live counters | 41 |
| 800–899 | desserts | 107 |
| 900–999 | breads | 39 |
| 1001–1006 | raita | 6 |
| 1101–1124 | salads | 21 |
| 1201–1211 | chutneys & sauces | 13 |
| 1302–1310 | tea & coffee | 9 |
| 1401 | pan stall | 1 |
| 1501–1509 | water, drinks, packing | 9 |
| 1601–1614 | misc & decoration | 10 |
| 1701–1743 | platters & assorted | 16 |
| **1750, 2000–2060** | **non-food, service, material** | **67** |

### Probable shape

Derived from the name and the band, recorded in `probable_item_shape`. A guess,
never authority — but it is what decides which Cost Block grammar each row needs.

```text
dish                  794
lump_sum_service       51
material_backed_item   42
packing_disposable     22
raw_material_sale      16
rental_like_charge      6
unknown                 4
service_charge          3
reject_candidate        3
```

**The bands leak.** `PACKING LARGE BOXES` sits at 231 inside the rice band while
other packing sits at 1509, 1609, 2021 and 2022. Two biryanis and a bread sit
inside the main-dish band. Baklava is filed under snacks. Four dishes sit inside
the non-food band at 2001–2004. Every one of these is flagged `MISFILED_BAND`.

---

## Source data problems

### 1. Duplicate sequence numbers — 221 rows

By far the largest defect. Sequence is **not unique** and cannot be used as a key
or a sort order. Runs of consecutive shared numbers appear throughout the main
dish and kabab bands; sequence 501, 504, 505, 506, 507 and many others each carry
two unrelated items.

### 2. Truncated Code # — 106 rows

Four screenshots (`9.40.04`, `9.41.13`, `9.43.02`, and partly `9.46.08` /
`9.53.52`) were captured mid-scroll, so the Code column is cut off in the image
itself. Those codes are **not recoverable from the source provided** and are
recorded as `TRUNCATED` rather than guessed. A handful were recovered from the
overlapping row in the adjacent screenshot and are marked
`SOURCE_CODE_PARTIAL_RECOVERED`.

**A clean re-export would remove this entirely** and is worth asking for.

### 3. Reused source codes — 6 confirmed collisions

`Code #` is not unique either:

| Code | Used by | and by |
|---|---|---|
| 287 | PASINDAY BEEF (331) | CHICKEN BUTTER (350) |
| 190 | QORMA MUTTON BADAMI (422) | LAHORI FISH (619) |
| 362 | FARMAISHI CHAPATI (908) | NAN MILKY (909) |
| 524 | HALWA SUJI DESI GHEE (848) | ZARDA SADA MOTIA (852) |
| 771 | CHICKEN ECLIAR (2038) | TISSUE PAPER BOX (2051) |
| 987 | TANDOORI MINT RAITA (1005) | SUKHY ALOO.DESI FRIES (1732) |

So **neither column in the export is a usable primary key.**

### 4. Spelling and formatting — 294 rows

`DIRTY_NAME_ROWS` counts rows whose `data_problem` carries at least one
name-quality token: the `SPELLING_*` family, `AMBIGUOUS_NAME`,
`ABBREVIATION_IN_SOURCE`, the whitespace and punctuation tokens
(`LEADING_SPACE_IN_SOURCE`, `DOUBLE_SPACE*`, `TRAILING_DASH_IN_SOURCE`,
`TRAILING_COMMA*`, `COMMA_INSIDE_DESCRIPTION`, `FULL_STOP_INSIDE_NAME`,
`DOUBLE_SLASH_IN_SOURCE`, `AMPERSAND_IN_SOURCE`, `MISSING_SPACE`), and the
content tokens (`RECIPE_WORD_IN_NAME`, `MARKETING_WORDS_IN_NAME`,
`UNEXPLAINED_CODE_IN_NAME`, `NAME_TRUNCATED_IN_SOURCE`,
`COMBINED_ITEMS_IN_ONE_ROW`, `TWO_VARIANTS_IN_ONE_ROW`, `TWO_UNRELATED_THINGS`,
`SIZE_IN_NAME`). Bands, duplicates and truncated codes are counted separately.

The original text is preserved untouched in `description_raw` and a proposed
correction sits in `normalized_name`. **Nothing was corrected in place.**

Confirmed examples: `SOLT` (Salt), `STROW` (Straws), `DISPOSIBAL` ×4,
`SHOPER` (Shopper), `THIRMAPOL` (Thermopore), `AP[REN` (Apron, with a stray
bracket), `KTAKAT`, `MANGOLIAN`, `CUTLUS`, `GRABI` (Gravy), `BOWILD` (Boiled),
`PAMPLATE` (Pomfret), `LABNESE` (Lebanese), `CHESS` (Cheese), `MORACON`
(Moroccan), `SPAICIOL` (Special), `CAKEICE`, `PEASE` (Pieces).

**Systematic variants** — the same word spelled several ways across the file:
`PULAOW` / `PULLAOW` / `PULAO`; `DAAL` / `DALL` / `DAL`; `KEEMA` / `QEEMA`;
`PANIR` / `PANEER`; `SADI` / `SADA`; `MAKHNI` / `MUKHNI`; `SAMOSA` / `SAMOOSA`;
`CHAWMIN` / `CHOWMING`.

Also present: leading and double spaces, trailing commas and dashes, and commas
**inside** descriptions (`KADHI,PAKORA`, `SALT,(NAMAK)`,
`LADY WAITRESS,&,GENTS WAITERS`) — the last of which means any importer must
handle CSV quoting properly or it will silently shift columns.

### 5. Words that are not product names

`BEST EVER NAAN TANDOOR`, `PERFECT TANDOORI CHICKEN`, `SUPER TASTY DUM KA GOSHT`,
`WORLD FAMOUS HYD KEEMA`, `EASY SUMMER PRAWN`, `EASY TORI RECIPE`,
`DAHI FRY RECIPE`, `ACHARI ALOO RECIPY`, `MUTTON MASALA RECIPY`,
`GRILLED SHRIMP RECIPE`.

Marketing adjectives and the word "recipe" have been absorbed into product names.
Flagged, not stripped — the owner decides.

### 6. Possible duplicate items — 26 rows

Same item under two codes, often with different spellings. Five breads appear
twice with a **trailing dash** on one of the pair (`NAN MILKY` / `NAN MILKY -`,
`QULCHA` / `QULCHA -`, `SHEERMAL` / `SHEERMAL -`, `TAFTAN` / `TAFTAN -`,
`M BREAD` / `M BREAD -`) — the dash may encode a size or a branch and **needs
explaining before either row is imported**. `SHEERMAL LIVE` appears twice with
*identical* text at 926 and 927.

**No duplicates were merged.** They are grouped by `duplicate_group` for the
owner to resolve.

### 7. Reject / review candidates — 3 rows

| Code | Description | Why |
|---|---|---|
| 692 | `KASHIF FOOD DAILY EXPENCES` | an **expense**, not a sellable item |
| 759 | `SSGC CHARGESS,GARLIC FRY` | SSGC is the **gas utility** — a utility cost glued to a food item |
| 769 | `FAIR FOR HALEEM` | "Fare", i.e. **transport cost** |

Kept in staging with `recommended_action = REJECT_OR_REVIEW`. **Deleting them
would hide the fact that the client's item list is being used to record costs.**
That is worth a conversation.

Several more are ambiguous rather than rejectable: `CAP`, `TEASHART`, `SHART`
(likely staff uniform, filed in the starters band), `BONELESS` (boneless *what*),
`FRUIT`, `PIZZA FLAVOURS`, `RICE`, `KHOYA`, `PIYAAZ`.

---

## The non-food model — 67 rows, and it holds

The 2000-series is where the client already records everything that is not a
dish, and **all of it maps onto the existing Cost Block grammar**. No new pricing
model is needed.

| Source rows | Shape | Cost Block representation |
|---|---|---|
| `LADY WAITRESS,&,GENTS WAITERS`, `VIP WAITERS` | labour | **charge**, per unit — quantity is head count, store issues nothing |
| `DECORATION//ARRANGEMENT`, `DECORATION DARI&CHANDNI`, `DECORATION DASTARKHWAN` | decoration incl. **flowers** | **charge**, lump sum per event |
| `DELIVERY CHARGES` | delivery | **charge** |
| `TISSUE PAPER`, `TISSUE PAPER BOX`, `DISPOSIBAL` spoon/plate/cup/fork, `PRINTED SHOPER`, `FOIL PAPER`, `STROW`, `THIRMAPOL CUP`, `HED CAP NET`, `FACE MASK`, `AP[REN` | disposables | **material**, ratio 1 — charged *and* drawn from stock |
| `FANS`, `HUT`, `THALL STEEL/PLASTIC`, `2.5/4 TABLE`, `DISPENSOR`, `SALAD TROLLY` | equipment | **charge**, per unit (no rental-return semantics in V1) |
| `MUTTON`, `BEEF`, `CHICKEN`, `BEEF BONELESS`, `DESI CHICKEN`, `DESI GHEE`, `MILK`, `SOLT`, `ICE`, `OIL KANASTAR`, `BUTTER`, `BANANA`, `COCONUT WATTER` | raw material sold directly | **material**, ratio 1 via a sellable wrapper product |
| ~30 rows named `LIVE`, `STALL`, `COUNTER`, `BAR`, `FOUNTAIN` | live counters | **charge**, lump sum |

**One friction the UI does not teach:** a directly sold raw material needs a
*sellable wrapper product* whose single material block points at the stock
material at ratio 1, because Catering Materials are deliberately non-sellable.
Worth walking the owner through once — see the representative set.

---

## Recommended sequence

1. Owner marks **Y/N** for what Kashif actually sells this month in
   `kashif-active-menu-owner-input.csv`.
2. Owner fills unit, charge and material structure for the **fifteen
   representative items** first — they exercise every shape.
3. Set those up through the ordinary screens after the operator-completion
   release deploys; run one booking end to end.
4. Work through the rest of the **Y** list by hand.
5. Ask the client for a **clean re-export** — ideally with price and unit columns,
   and without the horizontal truncation — before any bulk import is attempted.
6. Build the staged importer (design already recorded in §G of the product
   completeness audit, `ef184b6`) only after 1–5.

---

## Safety

```text
APPLICATION_CODE_CHANGED=no
DATABASE_CHANGED=no
PRODUCTION_MUTATED=no
KHATRI_MUTATED=no
KASHIF_MUTATED=no
IMPORTER_BUILT=no
SEEDER_TOUCHED=no
PRODUCTS_INSERTED=no
PRICES_GUESSED=no
SOURCE_IMAGES_MODIFIED=no
```

The old Catering worktree (`pos-saas-catering`, `feat/catering-product-ux-v1`)
was not touched and remains at its frozen head. `public/menu` remains untracked
and unmodified.
