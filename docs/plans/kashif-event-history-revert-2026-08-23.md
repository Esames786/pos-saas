# Event Change History + Revert — research + implementation plan

**Date:** 2026-08-23 · **Status:** PROPOSED — code research complete
**Client ask (clarified):** current event ki EK unified history — "kya kya change
kiya, overall" (header + items + rates + sab) — aur us list ke KISI BHI point par
current event ki halat WAPAS le aana (operator ke liye ye "overwrite" mehsoos ho,
chahe andar se naya version bane).
**Jawab: haan, ho sakta hai — aur aadha nizam pehle se bana hua hai.**

**Operator ko kya dikhega (khulasa):** Event screen par "History" → ek hi
timeline, har entry par tareekh/waqt (Karachi), kis ne, aur khulasa
("PAX 100→200", "Chicken Karahi 10→15 KG", "Beef customer-supplied kiya",
"Quotation finalized Q2") — aur har entry ke saath **"Is halat par wapas jao"**
button. Click → confirm → current event bilkul usi halat par (header + items +
rates), aur ye wapsi khud history mein likhi jati hai.

---

## 0. Research: aaj system kya yaad rakhta hai (code-proven)

| Cheez | History AAJ | Kahan |
|---|---|---|
| **Quotation (lines, rates, blocks, totals)** | ✅ **POORI** — `revise()` v(N+1) draft banata hai, lines + cost-block snapshots copy; purani version `superseded` + IMMUTABLE + print par "SUPERSEDED" watermark | `CateringEstimateService::revise`, `catering_estimates.version_no` |
| **Paisa (advance/refund/invoice)** | ✅ immutable ledger — "What happened" table par pehle se | `CateringFinancialPositionService::ledger` |
| **Rate changes (house book)** | ✅ audit table | `catering_commercial_rate_applications` |
| **Making bulk changes** | ✅ audit rows | Making Adjustment audit |
| **Draft ke ANDAR edits** (same version mein baar-baar save) | ❌ nahi — draft working-copy hai, `saveDraftLines` lines replace karta hai | design hi yehi hai |
| **Event HEADER** (customer, date, venue, PAX, type, time) | ❌ nahi — update overwrite karta hai | `CateringEventController::update` |

**Matlab:** "history + revert" ka sab se qeemti hissa (quotation ka mukammal
mali record) already mojood hai — usay sirf DIKHANA aur us par WAPSI ka rasta
banana hai. Naya sirf event-header ki history hai.

---

## 1. Design principle — history KABHI overwrite nahi hoti

Revert ka matlab purani row par wapas likhna NAHI. Revert = **naya version jo
purani halat ke barabar ho**. Paper-trail hamesha aage barhta hai:
`Q1 (superseded) → Q2 (superseded) → Q3 = Q1 ki copy (current draft, "restored from Q1" note ke saath)`.
Yehi usool header-history par bhi: revert khud ek naya history entry banata hai.

**Paisa REVERT SE HAMESHA BAHAR:** advances/refunds/invoices/GL kabhi revert nahi
honge — wo haqeeqat mein ho chuke hain. (Ghalat advance ka ilaj refund hai, jo
already bana hua hai.) Production releases (kitchen ko nikla saman) bhi bahar —
physical history hai. Modal mein ye sab DIKHENGE, magar read-only.

---

## 2. Phase A — History Modal (koi schema change NAHI)

Event screen par "History" button → modal (usi no-reload workspace pattern par):

1. **Quotation versions:** Q1..Qn — har ek ki date, status (draft/sent/superseded),
   grand total, banane wala; har version par "View / Print" (routes pehle se har
   version ko render karte hain) aur superseded par **"Restore this version"** (Phase B).
2. **Money timeline:** wohi ledger jo abhi "What happened" mein hai (read-only).
3. **Header history:** Phase C ke baad is modal mein add hoga.

Files: 1 modal partial + controller ka show() (data already load hota hai ya 1 query).
**Risk: zero — sirf display.**

## 3. Phase B — Quotation Restore (chhota service extension)

Naya `restoreVersion(CateringEstimate $old)`:
- Sirf `superseded` version se allowed (current se restore be-maani).
- Wohi clone code jo `revise()` mein hai (lines + block snapshots + totals + terms)
  — share karenge, duplicate nahi.
- Current version (draft ho ya sent) → `superseded` mark; restored copy = naya
  current **draft** (operator dekh kar khud Finalize kare — andha auto-send kabhi nahi).
- Notes par: "Restored from Q{n} on {date} by {user}".
- Sab `CateringDocumentLock` ladder ke andar (wohi race-protection jo revise mein hai).
- Confirmed/production-ready event par: restore ALLOWED magar warning modal —
  "booking confirmed hai; nayi quotation dobara bhejni hogi". Release ho chuka
  saman is se wapas nahi hota (Phase 1 usool).

Files: service method + 1 route/permission (`tenant.catering.estimates.restore` —
naya route = non-owner roles ko additive grant, deploy note) + modal button + guards.
**Risk: kam — clone logic tested hai; naya sirf "kis se clone" hai.**

## 4. Phase C — Unified checkpoints: HAR change ki entry + kisi bhi par wapsi
## (clarified ask ka asal dil — 1 additive table)

```
catering_event_revisions            -- append-only; kabhi update/delete nahi
  id, catering_event_id, changed_by_user_id, changed_at,
  action VARCHAR        -- event_updated | lines_saved | finalized | restored | ...
  change_summary VARCHAR-- insani zuban: "PAX 100→200", "Chicken Karahi 10→15 KG"
  snapshot JSON         -- event ki POORI halat us lamhe:
                        --  header: customer/phone/address/type/date/time/venue/pax/branch
                        --  lines[]: product_id, item_name(+ur), qty, unit, rate,
                        --           override reason, instructions,
                        --           per-material: event_qty, customer_supplied
                        --  charges: service/other(label)/discount/tax, terms/notes
```

**Kab likhi jati hai:** event create/update, har draft **Save** (saveDraftLines ke
baad — yani intra-draft changes bhi, jo aaj bilkul gum ho jate hain), Finalize,
Customer Accepted, restore/revert khud. Har jagah controller/service mein explicit
call — koi observer magic nahi (repo style).

**change_summary kaise banta hai:** pichhli revision ke snapshot se diff — PHP mein
compare (fields + lines by product/name): "Chicken Karahi 10→15 KG; Beef →
customer-supplied; PAX 100→200". Modal list mein yehi parhne layak line dikhegi.

**Wapsi ("Is halat par wapas jao") — operator ke liye overwrite, andar se naya version:**
1. Header fields snapshot se apply (normal update path).
2. Agar current quotation DRAFT hai → lines snapshot se **wohi existing pipeline**
   se dobara banti hain: `saveDraftLines` → phir har line par material-qty override /
   customer-supplied / quoted-rate override wohi authorities se. Koi bypass nahi,
   is liye pricing/lock/permission qanoon sab lagta hai.
3. Agar current quotation SENT/finalized hai → pehle Phase B rasta: current
   superseded + naya draft, phir (2). Operator ko farq mehsoos nahi hota —
   "wapas chala gaya"; paper-trail salamat rehta hai.
4. Wapsi khud ek nayi revision hai: "Reverted to 21 Aug 3:15 PM state".

**Paisa aur nikla hua saman is wapsi se KABHI nahi badalta** (section 1 usool) —
modal in entries ko dikhata hai magar unka revert button nahi hota.

**Risk: darmiyana** — table additive hai, magar revert lines ko dobara likhta hai;
isi liye revert existing pipelines SE HI guzarta hai (naya writer nahi banta) aur
guards ise byte-level prove karte hain (section 6).

## 5. Kya deliberately NAHI banega

- Draft ke andar har keystroke ki history (working copy hai; version banti hai
  Finalize par — warna shor mein asal versions doob jayengi).
- Finance/production/print-jobs ka revert (upar wajah).
- Header history ka retroactive backfill (purane events ki pehli entry unki AAJ
  ki halat hogi — imandari se "history starts 2026-08-2x" label).

## 6. Tests

- Restore: Q1 sent → revise Q2 → Q2 sent → restore Q1 ⇒ Q3 draft == Q1 (lines,
  block snapshots, totals, quoted-rate overrides byte-level), Q2 superseded,
  Q1 UNTOUCHED; race: do concurrent restore → ek jeete (lock ladder guard).
- Header: update par revision row; revert par fields wapas + nayi revision row;
  history kabhi delete/update nahi hoti (append-only guard).
- **Checkpoint round-trip:** event banao → lines + overrides + customer-supplied
  set karo (checkpoint A) → sab badal do → A par revert ⇒ header + har line ki
  qty/rate/override/supplied/instructions BILKUL A jaisi (deep-compare), aur
  revert ki apni revision likhi gayi; A ki row untouched.
- Diff summary: "10→15 KG" jaisi lines sahi banti hain (added/removed/changed).
- Money untouched: restore ke baad advances/refunds/position/trial-balance
  bilkul wahi (existing suites + ek explicit assertion).
- Modal render guards (versions list, superseded par Restore button, current par nahi).

## 7. Andaza + tarteeb

Phase A (modal) ~ aadha din → Phase B (restore) ~ aadha din + tests →
Phase C (header history + revert) ~ aadha-pauna din. **Kul ≈ 2 focused din.**
Deploy: Phase C par 1 additive migration; naya route permission → non-owner roles
additive grant + `system:clear-tenant-permission-cache` (standing rule).
Khatri: zero touch (catering module Khatri use nahi karta); Kashif data untouched.
