# Kashif Order Punch — keyboard datatable on the live event screen (implementation plan)

**Date:** 2026-08-23 · **Status:** PROPOSED — mockup client-approved
**Mockup (approved flow):** https://claude.ai/code/artifact/6acb375d-8f9e-4576-be2a-8b9030d59962
**Rule:** koi naya pricing path nahi — punch ka har qadam wohi existing authorities
(saveDraftLines / overrideMaterialQuantity / setChargedRate / setCustomerSupplied /
overrideQuotedRate) call karta hai. Rate Impact, prints, finance, kitchen sheets —
sab usi snapshot ko parhte rahenge jo ye authorities likhti hain, is liye
**by construction** kuch nahi tootta; neeche har consumer ki jaanch phir bhi likhi hai.

---

## A. Product-catalog flags — Complimentary + Allow Party (item ke saath, order ke waqt nahi)

### Schema (1 additive migration)
```
catering_product_profiles
  + allow_party_supply   BOOLEAN NOT NULL DEFAULT TRUE   -- legacy "Allow Party Meat [Cat Allow]"
  + is_complimentary     BOOLEAN NOT NULL DEFAULT FALSE  -- legacy "Complimentry Item"
```

**Defaults hi no-breakage ki zamanat hain:**
- `allow_party_supply = TRUE` default → **aaj ka live behavior hu-ba-hu qaim** (abhi har
  material par customer-supply allowed hai). Owner jis item par nahi chahta, wahan OFF karega.
  Agar default FALSE rakhte to deploy ke din se live UI ke controls ghayab ho jate — wohi
  breakage jo user ne mana kiya hai.
- `is_complimentary = FALSE` default → koi mojooda item khud-ba-khud muft nahi hota.

### UI
Catering Products (profiles) screen par har item ke edit mein 2 toggles, legacy ke naam:
"Allow Party Meat" / "Complimentry Item". (Cost Blocks screen par read-only chip.)

### Asar — sirf line BANATE waqt, wohi authorities
- **Complimentary ON:** naya line add hote hi `overrideQuotedRate(line, 0, "Complimentary item")`
  khud lagta hai (wohi ek override authority; row par wohi اعزازی badge + print flag jo
  pehle se bana hua hai). Kisi khaas booking par "Charge it instead" se wapis — already live.
- **Allow Party OFF:** us item ke materials par گاہک-column disabled (UI) **aur**
  `setCustomerSupplied` server-side refuse (naya guard clause) — message:
  "is item par party supply allowed nahi — Catering Products se on karein".
  **Grandfathering:** jo purani bookings pehle se split rakhti hain unka data untouched;
  guard sirf NAYI set/change par chalta hai. Snapshots kabhi retro-edit nahi hote.

### Har consumer ki jaanch (kya kharab ho sakta tha, kyun nahi hoga)
| Consumer | Asar |
|---|---|
| `computeAmount/ourStockRequirement/materialCost` | ZERO — flags sirf create-time behavior hain, math nahi chhoote |
| Prints (estimate body / release) | ZERO — snapshot parhte hain; complimentary flag pehle se rate-0 par chhapta hai |
| Kitchen sheet / store issue | ZERO — `ourStockRequirement` wohi |
| **Rate Impact** | ZERO — wo snapshots + `commercial_rate_source` parhta hai; flags us tak jate hi nahi |
| Making Adjustment | ZERO — charge_role par chalta hai |
| History/Revert snapshots | flags PROFILE par hain (line par nahi) → snapshot schema unchanged; revert ke re-apply mein complimentary re-trigger NAHI hota (snapshot ka rate/reason hi wapas lagta hai — sahi yehi hai) |
| Seeder/bootstrap | `firstOrNew` profiles — naye columns default se aayenge; rerun no-op |
| Demo events (0025/0026) | data untouched (grandfathered) |

## B. Keyboard punch — existing event screen par ek FRONT-END layer

**Naya screen NAHI banta.** Wohi `events/show` ka draft builder; uske "Add Item" ki jagah
punch-bar (approved mockup wali qatar):

```
item code/naam → Enter → Qty → Enter → [OWN | PARTY] (O/P) → Enter
→ material #1 AUTO-FILL (naam, recipe KG, latest rate) → theek karo → Enter
→ material #2 … → "aur material?" (optional) → khali Enter = ROW SAVE
گاہک alag row nahi — usi material ki line mein گاہک-KG column (0 rate)
```

### Backend mapping — sab pehle se LIVE hai
| Punch ka khana | Endpoint (mojooda) |
|---|---|
| Row save (line create) | `PUT /catering/estimates/{id}` (saveDraftLines — auto-save on pick already shipped) |
| Kitchen KG | `PUT /catering/line-cost-blocks/{id}` (overrideMaterialQuantity) |
| Rate (latest→edited) | `PUT /catering/line-cost-blocks/{id}/rate` (setChargedRate → SOURCE_MANUAL) |
| گاہک KG | `PUT /catering/line-cost-blocks/{id}/customer-supplied` (partial/full/clear + naya allow_party guard) |
| Extra material attach | Phase-2 (neeche) — pehle release mein sirf recipe-linked materials |
| Complimentary | flag-driven auto (A) + mojooda "Complimentary?" button |

**Tarteeb:** row save pehle (snapshots ban jate hain), phir JS unhi snapshots par
2–3 chhoti calls (qty/rate/گاہک) usi no-reload pipeline se — bilkul waise jaise operator
aaj Cost Details mein karta. Atomic composite endpoint sirf tab banayenge agar round-trips
sust mehsoos hon (Phase-2; wohi revertTo-pattern: pipeline-only, koi naya writer nahi).

### Tab / Shift+Tab / shortcuts
- **Tab aage, Shift+Tab peeche** — native browser order; punch-bar ke inputs DOM-order
  mein rakhe jayenge taake koi custom trap na ho. Enter = "agla logical khana" (mockup wala).
- **Visible shortcut strip** builder ke neeche (mockup jaisi kbd-legend):
  `/` item search · `Enter` agla · `Shift+Tab` peeche · `Ctrl+S` **Save Estimate** ·
  `Ctrl+P` **Print Bill (customer preview)** · `Esc` cancel row
- `Ctrl+S` → `#estimate-form` submit (preventDefault browser-save); `Ctrl+P` → document
  preview route new-tab (preventDefault print-dialog). Dono handlers always-on script block mein.

### Search by legacy code (361)
`/ajax/products` pehle se SKU + naam + **barcode** match karta hai. xlsx import legacy code
ko product BARCODE row ki tarah save karega → `361` likhte hi item mil jayega,
**lookup code mein zero change**. (Reused codes: dono items list honge, operator chunega.)

## C. Rate Impact — kuch disturb nahi hota (proof)

- Punch mein rate change = `setChargedRate` → `commercial_rate_source = manual` —
  **yehi aaj ka qanoon hai**: haath ka rate house-book follow karna band. Rate Impact
  aise blocks ko offer nahi karta (STATE mechanism unchanged).
- Book-following blocks jo punch mein sirf qty/گاہک badalte hain → follow karte rehte hain;
  preview billable par (pichhle sprint mein fix ho chuka, guard mojood).
- Gate: `CateringCommercialRateImpactMySqlTest` + `Hardening` + `Isolation` suites
  bila-tabdeeli green — yehi "disturb nahi hua" ka saboot hoga.

## D. Tests + deploy gate

1. Flags: profile toggles render/save; complimentary auto-zero on NEW line (old lines
   untouched); allow_party OFF → setCustomerSupplied refuse + UI hidden; defaults =
   existing-behavior regression (full CustomerSupplied suite bila-tabdeeli green).
2. Punch UI guards (OperatorUi): punch-bar markup, shortcut strip, stepper attributes.
3. Rate-impact suites untouched-green. 4. Full catering suite. 5. `bash deploy.sh`
   (1 additive migration; naye route nahi is phase mein) → Khatri read-only smoke.
**Andaza: flags ~ aadha din · punch UI ~ 1 din · polish/shortcuts ~ aadha din.**

## E. Is plan se BAHAR (alag tracks)
- Extra/free material attach beyond recipe (mockup mein hai) — Phase-2, kyunki isay
  snapshot mein ad-hoc block INSERT chahiye (chhota model addition, alag review).
- Composite punch endpoint — sirf zaroorat par.

---

# F. LEGACY IMPORT PIPELINE — BUILT & LOCALLY VERIFIED (2026-08-23/24)

`public/old_software.xlsx` = old software ka POORA database (184 sheets). Pipeline:

```
old_software.xlsx
  → php scripts/extract-legacy-xlsx.php        (deterministic staging)
  → docs/data/legacy-{categories,kitchens,order-items,customers,item-materials,orders,order-lines}.csv
  → php artisan catering:import-legacy kashifkitchen --orders=all --yes --confirm=kashifkitchen
```
**GO-LIVE:** client ki TAAZA xlsx ko `public/old_software.xlsx` par rakh kar yehi 2
commands — pipeline khud latest data nikal kar system mein daal degi (har qadam
idempotent; GL/stock fingerprint-guarded; Khatri allowlist se bahar).

### xlsx findings (jo user ne poochha tha)
- **`tbl_OrderItem` (909):** asli primary id (361…), seedha `OrderCatagaryId` link,
  Unit, **OrderRate = MeatRate + ServiceRate (909/909 par EXACT)** — ServiceRate hi
  Making hai; `CatAllow` (Allow Party!), `KitchenId` + `KitchenAllow`. UrduDesc
  corrupted encoding — reliably recover nahi hota (skip; Urdu naam translations se).
- **`tbl_OrderCatagary` (18):** SequenceNo (100/200/…) = **print ordering ki authority**
  — categories pehle se isi tarteeb par hain (sort 501–518), print-by-category is
  sort par sort karega (§B punch ke saath UI/print mein lagega).
- **Customers:** koi master table nahi — 24k orders se derive kiya (phone → latest
  naam/address): **4,848 asli customers**. Event screen par POS-style picker
  (phone/naam search → select ya naya type = auto-create) §B ke saath banega.
- **`tbl_orderdetail`:** har line par `cat` (PARTY/CAT!), meat/rice/additional/daigs,
  rate, net — punch-grid ka saboot + import mein instructions text ban gaya.
- **`tbl_OrderItemFar` (792):** item→raw-material links (ratios) — Phase-2 (asli
  material blocks banane ko) staged.

### Import results (LOCAL kashifkitchen — prod untouched)
- products: **794/909 matched** (115 miss = reconciliation mein drop hue codes —
  list command report karta hai), **696 client-ke-apne-rate par re-rate**
  (361 → calculated **2650** exact = Meat 1450 + Making 1200), **416 par Party OFF**
  (CatAllow=N), kitchens → production_station, legacy id → **barcode** (search "361" works).
- customers: 4,848 · orders: **24,424 events + 68,461 lines** (future bookings Jan-2027
  tak; past = completed/accepted, future = draft; **paisa import NAHI hota** — money
  history purani kitabon mein rehti hai, marker har event par likha hai).
- Flags migration (§A) isi batch mein: `allow_party_supply` (default TRUE) +
  `is_complimentary` (default FALSE) — CatAllow ne pehli asli values de din.
- **PROD DEPLOY ABHI NAHI** — user pehle local par test karega. Go-live par: prod
  test-data cleanup (mere demo events) + fresh xlsx + yehi pipeline.
