# Kashif — Old-Software ITEM Screen, on the new system (implementation plan)

**Date:** 2026-08-22 · **Status:** Phase 1 BUILT (awaiting deploy) · Phases 2–3 PROPOSED
**Reference:** client's screenshot of item 361 BIRYANI MASALA BEEF (legacy per-product setup)
**Rule:** koi major system cheez nahi hilegi — no DB migration for Phase 1, no service/pricing
change in ANY phase. Pricing ki authority hamesha Cost Blocks grammar hi rahegi.

## Legacy screen ke fields → hamare system ka sach

| Legacy field (361 screenshot) | Hamara equivalent | Phase |
|---|---|---|
| Order Rate 2650 (readonly) | Calculated rate = blocks ka per-unit total | **1 (BUILT)** |
| Making Chrg 1200 | Making block (`charge_role=making`) ka per-unit hissa | **1 (BUILT)** |
| Meat Rate 1450 | Sab se bara material block, apne NAAM ke saath (Beef Rate) | **1 (BUILT)** |
| Additional Rate 20 | Baqi per-unit blocks ka jor | **1 (BUILT)** |
| Unit KGS | Profile ka quote unit | **1 (BUILT)** |
| Catagary BEEF / 2 RICE | Product ki category (ab legacy 18 mein) | **1 (BUILT)** |
| Materials grid (BEEF WITH BONE, Rate% 1, Qty% 1) | Blocks table (already hai, wohi editing surface) | already |
| Urdu Name بریانی مصالحه بیف | `products.name_ur`? — column check chahiye | 3 |
| Kitchen: 1 KASHIF FOODS | `production_station` (routing already built) | already |
| Allow Party Meat [Cat Allow] | Customer-Supplied (per-booking, line par) — hamara zyada darust hai | already |
| Complimentry Item | NAHI hai — additive flag banega agar zaroorat sabit ho | 3 (optional) |
| Item Allow For Kitchen List | Release/instructions framework covers | already |
| Purchase Item + Rate%/Qty% link | Purchase→rate sync — ALAG design (client point #3), is plan se bahar | deferred |
| Qty In No | NAHI hai — additive column hoga agar owner ko chahiye | 3 (optional) |

## Phase 1 — Legacy strip, per-item screen par (BUILT, deploy pending)

**Kahan:** Catering Products → kisi bhi item → **Cost Blocks** screen (`cost-blocks/edit.blade.php`).

**Kya:** screen ke sar par legacy boxes ki patti — `ORDER RATE | MAKING CHRG |
{MATERIAL} RATE | ADDITIONAL RATE | UNIT | CATEGORY` — bilkul 361 screen ki tarteeb.
**LIVE hai:** neeche blocks table mein koi rate/ratio/role badalte hi patti foran update
hoti hai (usi `preview()` JS se jo pehle se Selling-rate compute karta tha — koi naya
hisaab engine nahi, koi server call nahi).

**Kya NahI badla:** editing wohi blocks table se; save wohi PUT endpoint; koi DB/service
touch nahi. Event rows wali patti (pichhla deploy) isi ki behen hai — dono ek grammar.

**Files:** 1 blade (`cost-blocks/edit.blade.php`). **Risk:** cosmetic-only.

## Phase 2 — wohi patti Catering Products listing par (chhota, optional)

Products listing (profiles index) ki har row par Order Rate column already hai;
chaaha to Making/primary-material ke chhote columns add ho sakte hain — read-only,
`CateringCostBlockService::rateFor` se. **Sirf agar client maange.**

## Phase 3 — Legacy ke reh gaye fields (sirf owner ke kehne par, additive)

1. **Urdu Name** item par: agar `products` mein `name_ur` nahi to additive nullable
   column + form field + print fallback (documents already Urdu-ready hain).
2. **Complimentry Item flag:** additive boolean; estimate line par rate 0 + label —
   pricing engine ko chhue baghair.
3. **Qty In No:** additive nullable column, sirf display.

In teeno ke liye alag chhota migration lagega (additive-only) — tabhi karenge jab
client inko actually use karna chahe, warna dead fields ban jate hain.

## Deploy gate

- Blade compile ✓ (view:cache green) · Catering UI suites green chahiye
- Ek hi deploy mein: Phase 1 strip + Karachi-timezone fixes (neeche)
- Khatri: zero touch; Kashif: koi data change nahi (sirf views)

## Saath ja raha hai isi deploy mein: Karachi timezone sweep

Kashif ki config pehle se sahi thi (branch tz = Asia/Karachi, storage UTC,
TenantClock chain user→branch→Karachi). Jo 8 jagah views TenantClock ke BAGHAIR
seedha UTC format kar rahi theen, sab fix:

- **Estimate print ki Date line** (client-facing — sab se ahem)
- Address sheet ka "printed at"
- Material Rate Book ki entry timestamps
- 5 date-input defaults (advance received, refund, effective-from, store issue,
  commercial rate) — Karachi adhi raat ke baad ab kal ki tareekh nahi aayegi
