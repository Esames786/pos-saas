# Punch bar → stacked material row: kaise karein bina kuch tore

**Date:** 2026-09-08 · **Tenant:** `kashifkitchen` · **Status:** PLAN — koi code nahi laga
**Base:** `083395b` (chaar fixes abhi commit hueen, deploy baqi)

Owner ka mockup: item ek hi qatar me, aur uske andar **material breakdown neeche ki taraf barhta** hai — ek item ke do material, to doosra material pehle ke theek neeche, unhi columns ke andar. Aur checkbox khatam.

---

## 1. Sab se ahem baat: **ye sirf DAALNE ka tareeqa badal raha hai**

Owner ne khud yahi kaha, aur ye durust hai — aur isi wajah se ye kaam mehfooz hai:

| tabqa | badlega? |
|---|---|
| `catering_estimate_lines` + `..._line_cost_blocks` (DB) | ❌ nahi |
| `saveDraftLines`, block authorities, `computeAmount` | ❌ nahi |
| Payload ki shakl (`lines[i][materials][j][label\|kg\|rate\|cust]`) | ❌ nahi |
| Print, kitchen sheet, GL, stock | ❌ nahi |
| **Sirf punch bar ka DOM aur uska JS** | ✅ haan |

Jo cheez server tak jati hai wo aaj bhi **hidden inputs** hain. Naya layout unhi hidden inputs ko doosri jagah rakhta hai. Server ko farq maloom hi nahi hoga — aur yehi is tabdeeli ki sab se bari hifazat hai.

---

## 2. Checkbox hatana — aur uski jagah kya

Aaj OWN/PARTY ek **do-button wala switch** hai (`#punch-seg`), aur uske hisab se har material row par گاہک wala khaana dikhta/chhupta hai.

Owner ki tajweez behtar hai: **party ki ijazat pehle se item par likhi hui hai** (`catering_product_profiles.allow_party_supply`), to alag sawaal poochne ki zarurat hi nahi —

- `allow_party_supply = 1` → us material ki **Party** wali input khuli
- `allow_party_supply = 0` → wohi input **band (disabled)**, aur badge par likha `OWN ONLY`

Mockup me yehi dikh raha hai: `RM-CH-01 PARTY ALLOWED` par Party khula, `RM-RI-02 OWN ONLY` par band aur khaakistari.

**Kya hoga andar se:** `punch.mode` (`OWN`/`PARTY`) ki koi zarurat nahi rahegi. Aaj `punchSetMode()` mode badalte waqt customer shares **zero** karta hai (wo ek asli bug ka ilaj tha — `c259607`). Naye tareeqe me wo khatra hi khatam ho jata hai, kyunki har material ka apna faisla apne hi row par likha hai. `punchSetMode` hata denge, aur uski jagah ek satar: **jo material party ki ijazat nahi rakhta, uska Party khaana disabled aur hamesha 0.**

⚠️ Ek baat sambhalni hai: `computeAmount`/`billableQty` `customer_supplied_qty` par chalte hain. Disabled input browser **post nahi karta**, is liye us material ke liye `cust=0` ka hidden input saath bhejna hoga — warna server par purani qeemat reh jayegi.

---

## 3. Tab ka safar — seedhi lakeer me

Owner ka mutalba: item → qty → rate → **material 1 ka rate → required → own → party** → material 2 wahi tarteeb → … → instructions → note → save.

Aaj `punchSeq()` isi ka zimmedar hai (`PUNCH-TAB-ORDER-1` me abhi durust kiya gaya). Naye layout me sirf uski tarteeb badlegi:

```
qty → customer rate
    → har material: rate, required, own, party (party sirf jab ijazat ho)
    → kitchen instructions → additional note
```

Yaani **Enter ka safar wahi rahega jo aaj hai**, bas material rows beech me aa jayengi — jo owner ne khud maanga hai.

**Aur ek usool jo abhi seekha gaya:** DOM ki tarteeb aur `punchSeq` ki tarteeb **ek jaisi honi chahiye**, warna Tab aur Enter alag jagah le jate hain. Naye layout me dono ek saath likhi jayengi.

---

## 4. Shortcuts aur dropdown — bilkul jyun ke tyun

Ye sab **chhue nahi jayenge**:

- `Ctrl+Enter` = row save · `Esc` = cancel · `Ctrl+S` = Save Estimate · `Ctrl+P` = Print
- `/` = item search
- Item dropdown ki poori aqal: exact code sab se upar, phir naam se shuru hone wale, phir baqi (`PRODUCT-SEARCH-RELEVANCE-1/2`)
- Ghair-mojood item enter na ho (`PUNCH-NO-FREE-TEXT-1`)
- Edit par row **apni jagah** replace ho (`PUNCH-EDIT-SWAP-1`)
- Row upar/neeche (`LINE-ORDER-1`), Recalculate ka warning (`RECALC-ASKS-TO-SAVE-1`)

Naya layout in ke **upar** banega, in ki jagah nahi.

---

## 5. Edit bhi naye layout par chalna chahiye

Abhi edit do raaston se hota hai:

- **un-saved row** → `row.replaceWith(punchRowHtml(...))` — apni jagah par
- **saved row** → hidden inputs par staging, aur `PUNCH-EDIT-SWAP-1` ke baad product bhi

Naye layout me `punchRowHtml()` ki jagah **`punchStackedRowHtml()`** aayegi (rowspan wala dhaancha), aur edit ka rasta wahi rahega — sirf HTML banane wala function badlega. Product swap ka fix pehle se maujood hai aur usi ko istemal karega.

---

## 6. Amal ka tareeqa — chhote qadam, har qadam par wapas mudne ka rasta

| qadam | kya | wapas mudna |
|---|---|---|
| 1 | `punchStackedRowHtml()` likhna — wahi hidden inputs, naya dhaancha | naya function, purana chhua nahi |
| 2 | Party ka faisla item ke flag par; checkbox hataana; `cust=0` ka hidden input | ek jagah |
| 3 | `punchSeq()` + DOM dono ki tarteeb naye layout par | ek function + markup |
| 4 | `punchRowHtml()` → `punchStackedRowHtml()` par switch | ek satar |
| 5 | Purana `#punch-seg` aur `punchSetMode()` hataana | aakhir me, jab sab chal jaye |

**Qadam 4 hi asal switch hai.** Us se pehle purana raasta chalta rahega, is liye har qadam alag se jaanchne ke qabil hai.

---

## 7. Kya toot sakta hai — aur pehle se kya pakda jayega

| khatra | kaise bachenge |
|---|---|
| Hidden inputs ka naam badal jaye → server chup-chaap ghalat parhe | Guard: rendered HTML me `lines[i][materials][j][label\|kg\|rate\|cust]` maujood hon |
| Disabled Party post na ho → purani qeemat reh jaye | Guard: `allow_party_supply=0` wale material par bhi `cust` ka hidden input mojood ho, qeemat 0 |
| Tab aur Enter alag chalne lagen | Guard: DOM tarteeb aur `punchSeq` tarteeb ka milaan (jaisa `PUNCH-TAB-ORDER-1` me hai) |
| Edit par row duplicate | Guard pehle se maujood (`PUNCH-EDIT-SWAP-1`) |
| Paisa badal jaye | Har qadam ke baad: ek line punch kar ke `amount`/`grand_total` ka before/after milaan; block authorities chhui hi nahi jaatin |

Aur wahi do aadatain jo is hafte mehngi sabit hueen:
- **har Blade change compile** karke dekhna
- **`view:cache` ke baad `view:clear`** — warna test purana HTML parh kar jhoota green de deta hai

---

## 8. Jo mai tajweez karta hoon

Owner ka layout behtar hai — khaas kar checkbox hatana, kyunki wo maloomat dobara poochta tha jo item par pehle se likhi hai.

**Magar ek qadam pehle:** abhi chaar fixes commit hui hain aur **deploy nahi hueen**. Pehle wo deploy ho kar live par jam jayen, phir ye kaam shuru ho — warna ek hi waqt me nayi punch bar aur naye fixes dono zere-tajruba honge, aur kuch bigda to ye bataana mushkil ho jayega ke kis ne bigada.

**Tajweez:** pehle deploy (chaar fixes), ek din live par chalne dein, phir ye layout qadam-ba-qadam.
