# Partial Customer-Supplied Material — impact map + implementation plan

**Date:** 2026-08-23 · **Status:** PROPOSED — investigation complete, code fully traced
**Client ask:** ek hi line mein split — "Chicken Karahi: chicken 10 KG, 5 KG hamari
(customer se charge X) + 5 KG customer ki (charge 0)". Aaj ye sirf 2 lines bana kar
hota hai; ye plan ek line ke andar partial qty deta hai.
**Tenant:** kashifkitchen. Khatri par koi asar nahi (tenant-DB feature, Khatri catering use nahi karta).

---

## 0. Poora money/stock chain — jaisa AAJ code mein hai (parh kar tasdeeq-shuda)

```
[MASTER] catering_product_cost_blocks           ← is_customer_supplied YAHAN HAI HI NAHI ✓
   │ buildSnapshotsFor() — line banate waqt copy; is_customer_supplied = false hamesha
   ▼
[SNAPSHOT] catering_estimate_line_cost_blocks   ← authority per booking
   │  computeAmount()          → snapshot.amount        (customer charge)
   │  computeMaterialCost()    → snapshot.material_cost (hamari laagat)
   │  physicalRequirement()    → kitchen ka total draw  (kaun de is se AZAAD)
   │  ourStockRequirement()    → store kya nikale (supplied ? 0 : physical)
   ▼
repriceLocked(line)   perUnit = Σ amount/qty → line.calculated_rate; line.amount
   ▼
estimate.subtotal = SUM(lines.amount) → totals() → grand_total
   ▼
FINANCE: advances / refunds / final invoice / GL / position/balance
         ← ye sub sirf estimate totals + line.amount parhte hain.
         ← is_customer_supplied ka FINANCE MEIN KOI ZIKR NAHI (grep-proven).
```

**Is nuqte ka matlab:** partial-supply sirf UPAR ke 3 model methods + unke callers
badalta hai. Receipts, advances, refunds, GL posting, customer balance, trial
balance — **bilkul untouched code paths**, kyunki unka input (line.amount) usi
purane raste se aata hai. Guard: mojooda `test_the_trial_balance_stays_at_zero`
+ advance/refund suites bila-tabdeeli green rehne chahiye.

## 1. Data model (1 additive migration — sirf SNAPSHOT table)

```
catering_estimate_line_cost_blocks
  + customer_supplied_qty  DECIMAL(12,4) NULL   -- NULL = flag wala purana matlab
```
- `is_customer_supplied` (boolean) ka matlab WOHI rehta hai: **poora** customer ka.
  Purane snapshots/documents ka mafhoom zero migration ke badalta nahi.
- Partial sirf naya column: `customer_supplied_qty = 5` aur boolean false.
- MASTER block table par kuch nahi — kaun kitna laayega **per-booking** faisla hai,
  dish ki recipe nahi. (Aaj bhi master par ye flag nahi hai — design qaim.)

## 2. Model math — `CateringEstimateLineCostBlock` (paison ki asal jagah)

Naye derived helpers (ek hi qanoon, sab jagah yehi use hon):
```php
suppliedQty()  = is_customer_supplied ? physicalRequirement()
               : min((float) customer_supplied_qty, physicalRequirement())   // clamp
billableQty()  = max(0, physicalRequirement() − suppliedQty())
```
Badalne wale methods (har ek ka partial rule):
| Method | Aaj | Partial ke baad |
|---|---|---|
| `computeAmount()` | supplied→0; per_material_unit→qty×rate | per_material_unit → **billableQty × rate**; per_dish_unit material → rate × dishQty × (billable/physical); full-supplied → khud-ba-khud 0 (billable=0) |
| `computeMaterialCost()` | supplied→0; warna qty×book | **billableQty × book-rate** (hum sirf wohi kharidte hain jo hum dete hain) |
| `ourStockRequirement()` | supplied?0:physical | **billableQty** |
| `physicalRequirement()` | total | **UNCHANGED** — kitchen ko poora chahiye, ye is feature ki rooh hai |
| `isCustomerSupplied()` | boolean | UNCHANGED (sirf FULL ka matlab) |
| `followsCommercialBook()` | supplied→false | full-supplied→false (unchanged); **partial→TRUE** (balance bill hota hai to house-rate change offer honi chahiye) |

## 3. Har consumer, file-ba-file (grep-complete list)

| File | Aaj kya karta hai | Kya badlega |
|---|---|---|
| `CateringLineCostBlockService::setCustomerSupplied` | flag set → refreshSnapshotAmount → repriceLocked | + optional `customer_supplied_qty` accept (0..physical clamp, material-only guard wohi); flag true ⇒ qty NULL (full) |
| `CateringLineCostController::customerSupplied` | boolean validate | + `customer_supplied_qty: nullable|numeric|min:0` |
| `repriceLocked` | Σ amount/qty | **NO CHANGE** (amount pehle hi sahi aa raha hoga) |
| `CateringRequirementService` (kitchen sheet) | physical + ours dono | **NO CHANGE** — ours khud billableQty ban jayega; sheet par partial khud sahi chhapega |
| `CateringProductionReleaseService` line 86 | "CUSTOMER SUPPLIES: Beef" | partial text: "CUSTOMER SUPPLIES: Beef 5 KG (of 10 KG)" |
| `CateringCostBlockService` readiness (246–321) | rate<=0 && !supplied → blocker | **NO CHANGE** (partial pe balance billable hai, blocker theek hai) |
| `CateringCommercialRateImpactService` (250, 356, 373) | preview = physical × newRate; supplied skip | preview = **billableQty × newRate** (warna partial par overstate); skip sirf full-supplied |
| `CateringStoreIssueController` / `CateringMaterialIssueService` | requirement se | **NO CHANGE** (ourStockRequirement se chalte hain) |
| Estimate print `estimate-body` provider column | 2 states | 3rd state: `ہم 5 KG · گاہک 5 KG` (split, dono zabanein) |
| `line-cost-details` (Cost Details panel) | checkbox (full) | checkbox wohi + uske saath chhota qty box: "customer laayega [ __ ] KG / poora" — post usi `[data-act]` pipeline se |
| Legacy strip (row + item screen) | supplied → contribution 0 | partial → contribution billable hisse ki; `گاہک دے گا (5 KG)` tag |
| Finance: `CateringAdvanceService` / `RefundService` / `FinalInvoiceService` / `FinancialPositionService` / GL | totals parhte hain | **ZERO CHANGE — parhna bhi nahi parega** |
| Complimentary (line-level 0 override) | line ka rate zero | independent — koi takraav nahi (wo line-rate hai, ye block-qty) |

## 4. Edge cases (faisla likha hua)

1. **supplied > physical:** save par clamp + validation error message; math mein bhi min() — dohri hifazat.
2. **Dish qty badle** (`syncMaterialQuantities`): physical ratio se barh jata hai;
   `customer_supplied_qty` JAHAN HAI WAHIN rehta hai (operator ka explicit bayan) —
   billable khud barh jata hai. Panel par dikhega "customer 5 KG of 12 KG" — sach.
3. **Reset material qty:** supplied qty qaim rehti hai (alag faisla hai); alag
   "customer wapas kuch nahi laayega" = checkbox uncheck + qty clear.
4. **Sent estimate:** wohi lock ladder — `editableSnapshot` draft ke bahar refuse
   karta hai. History kabhi nahi badalti. **NO CHANGE.**
5. **Rounding:** amount round(2), qty round(4) — wohi mojooda convention.

## 5. Tests (naye guards + jo bila-tabdeeli green rehne chahiye)

Naye (CateringLineBlockMySql / DocumentTruth / RequirementSheet):
- Partial math triple: 10 KG physical, 5 supplied → charge = 5×rate, ours = 5,
  cost = 5×book; line/estimate totals uske mutabiq.
- Full flag purana behavior byte-ba-byte (charge 0, ours 0, cost 0).
- Kitchen sheet: physical 10 / ours 5 dono columns.
- Release instructions: "CUSTOMER SUPPLIES: Beef 5 KG (of 10 KG)".
- Estimate print: `ہم 5 KG · گاہک 5 KG` dono zabanein.
- Rate impact: partial block ko offer + preview billable par.
- Validation: supplied > physical reject/clamp.

Bila-tabdeeli green (regression proof): trial-balance-zero, advance/refund/invoice
suites, requirement sheet full-supply tests, lifecycle 568-suite.

## 6. Kaam ka andaza + tarteeb

1 migration → model helpers + 4 method edits → service/controller → 4 views/prints
→ rate-impact 2 sputs → tests → poori catering suite → deploy (additive migration,
Kashif data untouched — naya column NULL = purana matlab) → Khatri read-only smoke.
**≈ 1 focused din.** Koi naya pricing path nahi — wohi snapshot authority ka extension.

## 7. Deploy gate

- Catering suite poori green (568+) + naye guards
- `bash deploy.sh` (additive migration sab tenants par, NULL-default → har purana
  snapshot ka matlab wohi)
- Kashif: koi data-fix zaroori nahi; Khatri: read-only smoke, tb_diff=0
