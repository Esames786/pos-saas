# In-table editor — pehle FLOW MAP, phir plan

**Date:** 2026-09-09 · **Live base:** `a362c34` (+ drag/Required-Qty fix, suite me)
**Kis liye:** owner ka refactor spec — punch bar khatam, likhna table ke andar.
**Status:** MAP + PLAN — koi code nahi laga.

Owner ne saaf kaha: *"pehle current code ka flow map kare — `lines[]`, edit index,
`renumberLines()`, hidden/form serialization aur Save Estimate. Sirf screenshot dekh kar
naya isolated table na bana de."* Ye document wohi hai.

---

# HISSA A — AAJ KA FLOW MAP

## A1. Riyasat (state) kahan rehti hai — aur ye sab se ahem baat hai

**Koi JavaScript `lines[]` array MOJOOD HI NAHI.**

Ye maine sochne se pehle farz kar liya tha; ghalat tha. Asal soorat ye hai:

| riyasat | kahan | kaun likhta hai |
|---|---|---|
| **poori quotation** | **DOM** — `#lines-body > tr[data-row]` ke andar hidden inputs | Blade (saved rows) + JS (nayi rows) |
| **jo line abhi likhi ja rahi hai** | ek `punch` object (arzi) | `punchPick` banata hai, `punchReset` mita deta hai |
| **server par kya hai** | database | sirf `Save Estimate` par |

Yaani **DOM hi sach hai**. Form submit hone par browser wahi `lines[i][...]` bhejta hai jo
rows me maujood hain. Koi alag serializer nahi, koi `JSON.stringify` nahi.

**Is ka natija:** naya editor bhi DOM hi ko sach rakhe. Agar main ek JS array bana kar
usay sach bana doon aur wo array kisi wajah se na bhare, to operator ki bani hui rows
gum ho jayengi — aur ye is codebase me **pehle ho chuka hai** (ek sweep ne "khali" rows
samajh kar server-rendered rows DOM se uda di thin, aur agle save par database se bhi).

## A2. Ek line ki pehchan — teen alag cheezein

| cheez | shakl | kis liye |
|---|---|---|
| `data-row` | `s0`, `s1` … (saved) · `p7` (punched) | **kabhi nahi badalta** — DOM me row ki pehchan |
| `lines[i]` ka `i` | 0,1,2 … | **posted tarteeb** → `sort_order` → kaghaz ki tarteeb |
| `line_uuid` | server ka uuid | server par purani line se milana |

`renumberLines()` **sirf `i` badalta hai**, `data-row` nahin. Aur (kal raat se) agar koi
edit chal raha ho to `punch.editIdx` bhi dobara hal karta hai.

## A3. Functions — kaun kya karta hai

**Riyasat aur pehchan**
```
nextRowIndex()        DOM me sab se bara i + 1
lineGroup(row)        row + uske neeche sab kuch agli tr[data-row] tak
renumberLines()       lines[i] dobara likhta hai + punch.editIdx theek karta hai
```

**Punch (arzi line)**
```
punchPick(e)          item chunne par `punch` banata hai profiles[id] se
punchRenderMats()     #punch-mats ki table banata hai (Material/Rate/Required/Own/Party)
punchLineCalc(qty)    { amount, rate } — making = dishRate − Σ(ratio × origRate)
punchAgreedRate()     customer rate ya system rate
punchRateIntent()     'override' | 'calculated' | null
punchLive()           bar me live rate/amount
punchSeq()            Enter ka safar — sirf nazar aane wale, enabled khane
punchCommit()         nayi row banata hai   → punchStackedRowHtml()
punchCommitEdit()     mojood row dobara likhta hai (saved row par staging)
punchReset()          punch = null
```

**Rendering**
```
punchStackedRowHtml(idx, qty, calc)   nayi/edit hui row (14 khaane + material rows + detail)
punchDetailHtml(mats, qty, dishRate)  chhupa hua breakdown (making samet)
punchMatsFromRow(row, idx, qty)       SAVED row se materials wapas parhta hai
line-material-cells.blade.php         SAVED row ke 5 material khaane (server-side)
```

**Tarteeb**
```
.line-up / .line-down     lineGroup + insertBefore/After + renumberLines
dragstart/over/drop/end   wahi do — lineGroup + renumberLines
```

**Baqi**
```
recalc()                  #lines-body > tr[data-row] par ghoom kar subtotal
initEstimateBuilder()     har in-place reload ke baad dobara chalta hai
```

## A4. Ek line ka safar — jahan se chalta hai wahan tak

```
select2 pick
   └─ punchPick(): punch = { productId, name, nameUr, unitId, unitCode,
                             dishRate, blocks, party, mats[], editRow, editIdx, ... }
        └─ mats[] profiles[id].mats se: { label, name, ratio, rate, unit }
                                        + own:null, cust:0, ownTouched:false, origRate

qty / rates / own / party  → punch.mats par likha jata hai
   └─ punchLineCalc(qty) → system rate
   └─ punchAgreedRate()  → customer rate

Ctrl+Enter
   └─ punchCommit()  → nayi row  → $('#lines-body').append(punchStackedRowHtml(...))
      punchCommitEdit() → wohi row → replaceWith(...)   [un-saved]
                        → hidden inputs par staging     [saved]

Save Estimate
   └─ poora form POST → saveDraftLines()
```

## A5. POST ka mu'ahida — **jo badalna nahi**

```
lines[i][line_uuid] [product_id] [item_name] [item_name_ur]
        [quantity] [unit_id] [rate]
        [rate_action] [rate_override_reason]
        [instructions] [instruction_ids][]
        [materials][j][label] [kg] [rate] [cust]
```

`kg` = **Own + Party** (kitchen ka total), `cust` = Party ka hissa. `cust` **hamesha**
bheja jata hai, 0 bhi — disabled input post nahi hota aur ghair-mojood khana server ki
purani qeemat chhod deta.

Ye alfaz **teen jagah** likhe jate hain: `punchRowHtml` (ab murda), `punchStackedRowHtml`,
aur `punchCommitEdit` ka saved-row branch. Guard teenon ko ginta hai.

## A6. Server kya karta hai — `saveDraftLines()`

- **Reconcile, wipe nahi** — `line_uuid` se milata hai; jo nahi bheji gayi wo hatti hai
- **`sort_order = $index`** — posted tarteeb
- **product badla = nayi dish** — purana breakdown poora hata kar naya banta hai
- **Editable hai ya nahi, ye faisla transaction ke ANDAR** — warna do log ek doosre ka
  kaam mita dete hain
- Har material `CateringLineCostBlockService` ke wohi authorities se guzarta hai jo Cost
  Details panel istemal karta hai

## A7. Product ka payload — `profiles[productId]`

```
blocks       bool     blocks se qeemat banti hai?
rate         number   SYSTEM rate per unit
mats[]       { label, name, ratio, rate, unit }
party        bool     ← PRODUCT level (profile.allow_party_supply)
unit_id / unit_code / minimum_qty / pricing_mode / name_ur
instructions string   ← product ka apna note (AAJ KOI ISE PARHTA NAHI)
```

---

# HISSA B — DO KHALA JO SPEC AUR DATA KE DARMIYAN HAIN

Ye do baatein spec me hain magar **data me nahi**. Main inhein bana nahi sakta bina wo
cheez chhue jo spec ne mana ki hai (backend model), is liye saaf likh raha hoon.

### B1. `party_allowed` **per material** — aaj MOJOOD NAHI

Spec §7: *"party_allowed is MATERIAL SPECIFIC."*

Aaj ye **product** par hai — `catering_product_profiles.allow_party_supply`. Ek dish ke
sab material ek hi jawab lete hain. Mockup me Chicken **PARTY ALLOWED** aur Rice **OWN
ONLY** — ye aaj mumkin nahi.

Iske liye `catering_product_cost_blocks` par ek naya column, ek migration, aur Catering
Products screen par ek switch chahiye. **Ye backend model ki tabdeeli hai.**

**Main filhal ye karunga:** UI bilkul per-material shakl me banega (har material ka apna
Own/Party khana, apna disabled hona), magar jawab product wale flag se aayega. Jis din
column banega, sirf ek satar badlegi. **Faisla aap ka.**

### B2. "Product-linked" Kitchen Instructions — aadha mojood hai

- **Kitchen Instructions** aaj **tenant-wide** fehrist hai (`catering_instructions`),
  product-linked nahi. Mockup ka badge "PRODUCT LINKED" is par durust nahi.
- **Additional Note** ke liye product ka apna field **mojood hai** —
  `catering_product_profiles.instructions` — aur wo `profileMap` me screen tak **aata
  bhi hai**, magar **koi ise parhta nahi**. Ise naye Note khane ki default qeemat bana
  dena aasaan aur durust hai.

---

# HISSA C — REFACTOR KA PLAN

## C1. Naya dhaancha — 13 columns

```
<tr>  Item(2) │ Qty(2) │ System Rate(2) │ Customer Rate(2) │ Material Breakdown(colspan 5)
      │ Kitchen Instructions(2) │ Additional Note(2) │ Amount(2) │ Action(2)
<tr>  Material │ Rate │ Required Qty │ Own │ Party
```

**Urdu ka column jata hai** — qeemat hidden input me Item ke khane me rahegi (payload
be-harkat). **Unit ka column jata hai** — select Qty ke neeche compact aa jayega (kyunki
unit **editable hai aur payload me hai**), aur System Rate ke neeche `per KG` sirf padhne
ke liye.

## C2. Ek riyasat, teen renderer

```
lineStateFromRow(row)      DOM  → { product, qty, unit, rates, mats[], instr, note }
renderReadBlock(idx, st)   riyasat → padhi jane wali rows (+ hidden inputs)
renderEditBlock(idx, st)   riyasat → likhi jane wali rows (koi hidden input NAHI)
renderMaterialCells(m, editable)   paanch khaane — dono renderer isi ko bulate hain
```

`punchLineCalc`, `punchAgreedRate`, `punchRateIntent`, `punchMatsFromRow`, `lineGroup`,
`renumberLines` **jyun ke tyun** — koi naya hisab nahi.

## C3. Sab se ahem hifazat: edit ke dauran line gum na ho

Edit block ke **nazar aane wale khane be-naam** honge (post nahi hote). Line ke **purane
hidden inputs usi block me, be-harkat** rahenge.

Faida:
- edit ke beech me `Save Estimate` daba diya jaye to line **apni pichli haalat** me chali
  jayegi — gum nahi hogi
- **Cancel** = sirf hidden inputs se read block dobara banana
- **Save Row** = hidden inputs dobara likhna, phir read block

Nayi line ke block par koi hidden input nahi — jab tak Save Row na ho, wo quotation ka
hissa hai hi nahi. Yehi "Row Save ≠ Estimate Save" ka asal matlab hai.

## C4. Tab/Enter ka safar

Spec §20 ka safar **DOM ki tarteeb se mel nahi khata**: Instructions/Note/Amount/Action
pehli material row me `rowspan` ke saath baithte hain, is liye qudrati Tab material #2 se
**pehle** Save Row par pahunch jayega.

Is liye `entrySeq()` — ek hi fehrist, jise **Tab aur Enter dono** istemal karenge (Tab
block ke andar rok kar). Read-only aur disabled khane khud chhoot jayenge — wahi filter
jo kal raat laga tha.

```
Item → Qty → Customer Rate
     → (mat1) Rate → Own → Party
     → (mat2) Rate → Own → Party …
     → Kitchen Instructions → Additional Note → Save Row
```

⚠️ Ye **aaj se mukhtalif** hai: aaj note materials se **pehle** aata hai. `PUNCH-TAB-ORDER-1`
ka guard ye purani tarteeb pin karta hai — usay badalna parega, aur wajah likhni paregi.

## C5. Own + Party = Required ki tasdeeq

Required **khana nahi** — `Own + Party` ka natija hai. Spec halka sabz/laal maangta hai.
Main Required ko **hisab** rakhunga (Own ya Party badle to khud badle), aur laal sirf tab
jab qeemat manfi ho ya recipe se farq ho — **shor nahi**.

## C6. Tarteeb — jo abhi theek hua hai, wo na tootey

- drag par `preventDefault` ka guard **wapas nahi aayega**
- entry block ka apna `data-row` **nahi** hoga (naya line) → `renumberLines()` aur
  `recalc()` use ginenge hi nahi
- edit ke waqt block usi row ki jagah rahega, apne `data-row` ke saath → drag/arrows
  usay poora uthate rahenge

## C7. Qadam (har qadam apna commit + guard, aur guard ko tor kar dekha jayega)

| # | qadam | kyun is tarteeb me |
|---|---|---|
| 1 | `lineStateFromRow` + `renderMaterialCells` (koi shakl nahi badalti) | bunyaad, be-khatar |
| 2 | header 13 columns + read block (Urdu/Unit hidden, Note ka naya column) | ek hi commit, warna table tootegi |
| 3 | entry block + Save Row + `entrySeq` (punch bar abhi zinda) | naya raasta, purana chalta rahe |
| 4 | edit in place + Cancel | |
| 5 | punch bar hataana + `punchSetMode`/`#punch-seg` ka malba | |

**Qadam 2 ke baad ek deploy** — aur us par counter ka ek din — phir 3–5.

## C8. Guards (spec §23)

- 0 / 1 / 2 / 3 material wali dish: rowspan, Required, Own/Party, disabled Party
- table **square**: har row ka `apne khaane + rowspan se aane wale` = 13
- payload: `lines[i][...]` ke naam **giney** — pehle aur baad me barabar
- entry block **post nahi karta**; edit block **purani qeemat** post karta hai
- tarteeb: drag + arrows ke baad `lines[i]` durust
- `Save Estimate` → reload → tarteeb wohi

Har fix ek baar hata kar guard **RED** dekha jayega.
