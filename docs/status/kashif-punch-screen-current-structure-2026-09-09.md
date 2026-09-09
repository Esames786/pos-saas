# Maujooda dhaancha — Order Punch screen, jaisa AAJ chalta hai

**Date:** 2026-09-09 · **Live:** `a362c34` · **Screen:** `/catering/events/{id}`
**Kis liye:** owner ne kaha — *"pehle current structure ka prompt de do, kaise chalta hai;
taake main naye design ka prompt samjha sakoon."* Ye wohi bayan hai. Koi tajweez nahi,
sirf **jo hai**.

---

## 1. Screen do hisso me hai

```
┌─ PUNCH BAR ──────────────────────────────────────────────────── (table se BAHIR, ooper)
│  Item [select2]  Qty  [System rate / Customer rate / Line amount]
│  Kitchen instructions [multi] + Additional note        [Row save]  [Esc cancel]
│  ── punch-mats: Material · Rate · Required Qty · Own · Party ──   (item chunne par bharta hai)
└──────────────────────────────────────────────────────────────────
┌─ LINES TABLE (#lines-table) ─────────────────────────────────── (jo rows lag chuki hain)
│  Item │ Urdu │ Qty │ Unit │ System Rate │ Customer Rate │ Material │ Rate │ Required │ Own │ Party │ Amount │ Instructions │ ⣿ ↑ ↓ ✏ ✕
└──────────────────────────────────────────────────────────────────
                                        Quotation Total (Subtotal · Service · Fare · Disc · Net)
                                        [Save Estimate]
```

**Ahem farq:** aaj **likhna ooper punch bar me** hota hai, aur **table sirf dikhati** hai.
Table ki row me sirf Qty, Unit, Customer Rate aur Instructions chhu sakte hain — Material
ke khaane table me **parhne ke liye** hain, likhne ke liye nahi.

---

## 2. Ek line kaise lagti hai (aaj ka safar)

| # | operator kya karta hai | screen kya karti hai |
|---|---|---|
| 1 | Item box me code ya naam, Enter | product mil gaya to `punch` shuru; uske cost blocks se material ki fehrist ban jati hai (`punch-mats`) |
| 2 | Qty | System rate khud nikalta hai; har material ka **Required = qty × recipe ratio** |
| 3 | Customer rate (khali chhoro to system wala) | Line amount live badalta hai |
| 4 | Kitchen instructions + note | — |
| 5 | Har material par: Rate · Own · Party | Required = Own + Party; Party sirf tab jab **item** ijazat de |
| 6 | **Ctrl+Enter** (ya "Row save") | table me nayi row aa jati hai — **sirf screen par** |
| 7 | **Save Estimate** | ab jaa kar server par likha jata hai |

⚠️ **Qadam 6 kuch save nahi karta.** Row par likha hota hai *"not saved yet — Save
Estimate"*. Asal save qadam 7 hai. (Client ka wo purana masla isi farq ka tha.)

Row par ✏ dabao to wohi row wapas punch bar me chali jati hai (`EDIT — <naam>`), aur
Ctrl+Enter usi jagah par usay dobara likh deta hai — nayi row nahi banti.

---

## 3. Keyboard ka mu'ahida

```
Enter      agla khana
Ctrl+Enter row save
Esc        punch cancel
```

Enter ka safar (`punchSeq`) is tarteeb par chalta hai:

```
Qty → Customer rate → Instructions note → (har material) Rate → Own → Party → agla material
```

Safar **sirf wahan jata hai jo screen par mojood aur likhne ke qabil ho** — chhupa hua ya
disabled khana khud-ba-khud chhoot jata hai.

---

## 4. Table ke 14 khaane — har ek kahan se aata hai

| # | column | kahan se | table me likha ja sakta hai? |
|---|---|---|---|
| 1 | **Item** | line ka `item_name` | nahi (chevron ▸ breakdown kholta hai) |
| 2 | **Urdu Name** | `item_name_ur` (product se khud bhar jata hai) | haan — magar punch mode me **chhupa** hai |
| 3 | **Qty** | `quantity` | haan |
| 4 | **Unit** | `unit_id` | haan |
| 5 | **System Rate** | `calculated_rate` — blocks ka jawab | **nahi** (hisab hai) |
| 6 | **Customer Rate** | `rate` | haan — live, aur farq ho to "agreed rate" ban jata hai |
| 7 | **Material** | line ke **snapshot** block ka naam + unit | nahi |
| 8 | **Rate** | block ka `rate` | nahi (Cost Details me badalta hai) |
| 9 | **Required Qty** | `physicalRequirement()` = Own + Party | **nahi — ye ta'reef hai** |
| 10 | **Own** | `billableQty()` — jo hum bhejte aur charge karte hain | nahi |
| 11 | **Party** | `suppliedQty()` — jo customer laata hai; ijazat na ho to `—` | nahi |
| 12 | **Amount** | qty × customer rate | nahi |
| 13 | **Instructions** | managed list + free note (ek hi khana) | haan |
| 14 | *(actions)* | ⣿ drag · ↑ ↓ · ✏ edit · ✕ remove | — |

**Material ka stack:** pehla material line ki apni row me; **agar dish ke do material hon**
to doosra neeche apni row me, wohi paanch columns. Baqi sab khaane `rowspan` se poore stack
par phaile rehte hain. *Aaj kisi bhi dish ke do material nahi hain, is liye stack kahin
nazar nahi aata* — dhaancha mojood hai.

---

## 5. Row jo POST karti hai — **ye hissa sab se hassas hai**

Chahe row punch bar ne banai ho ya server ne, POST bilkul ek jaisa hai:

```
lines[i][line_uuid]              (purani row ki pehchan; nayi row par khali)
lines[i][product_id]
lines[i][item_name]              lines[i][item_name_ur]
lines[i][quantity]               lines[i][unit_id]
lines[i][rate]
lines[i][rate_action]            = override | calculated   (sirf jab rate badla ho)
lines[i][rate_override_reason]
lines[i][instructions]           lines[i][instruction_ids][]
lines[i][materials][j][label]    [kg]      ← Own + Party, yaani kitchen ka total
                    [rate]       [cust]    ← Party ka hissa (0 bhi bheja jata hai)
```

**`i` ki tarteeb hi quotation ki tarteeb hai.** Server `sort_order = i` likhta hai aur har
document `orderBy('sort_order')` par chhapta hai. Isi liye row hilana = **kaghaz par
tarteeb badalna**, aur isi liye ↑↓ aur drag dono ke baad indexes dobara likhe jate hain.

---

## 6. Server kya karta hai

`saveDraftLines()` — **milata hai, mitata nahi.** Purani lines `line_uuid` se pehchani
jati hain; jo bheji gayi hain wo update hoti hain, jo nahi bheji gayin wo hatti hain.

Teen baatein jo yaad rakhni hain:

1. **Line ka apna snapshot hi sach hai.** Har line apne cost blocks ki **copy** rakhti hai
   (`catering_estimate_line_cost_blocks`). Product master baad me badal jaye, purani
   quotation nahi badalti.
2. **Product badla = nayi dish.** Row ka product badlo to uska purana breakdown **poora
   hata kar** naye dish se dobara banta hai.
3. **Sab kuch ek lock ke andar.** Editable hai ya nahi, ye faisla transaction ke **andar**
   hota hai — warna do log ek waqt me likhein to ek doosre ka kaam mita dete hain.

---

## 7. Teen "authorities" — teen jagah jahan hindsa tay hota hai

| kya | kahan | qaida |
|---|---|---|
| **Customer rate** | row ka Customer Rate box | system se farq = "agreed rate" + wajah; barabar likho to wapas system par |
| **Material ka rate** | Cost Details panel | sirf **is booking** ke liye; dish ka apna block nahi hilta |
| **Own / Party** | Cost Details panel (aur punch bar) | Own + Party = kitchen ka total; Party sirf jab item ijazat de |

Gosht ka hisab: `per_material_unit` par **billableQty × rate** — yaani 28 KG dish par 42 KG
beef daalo to charge 42 par lagta hai, 28 par nahi.

---

## 8. Jo table me JAAN BOOJH KAR nahi hai

- **Making charge** — background me hai, alag column nahi (Cost Details me dikhta hai)
- **Hamari lagat / margin** — customer ke saamne wali table me kabhi nahi
- **Lump-sum charges** — per-unit rate ke andar kabhi nahi
- **Free-text item** — product ke baghair line ab banti hi nahi

---

## 9. Jo bhi naya design aaye, ye chaar cheezein qayam rehni chahiyen

1. **POST ka shakl** — upar §5 wale khaane, wohi naam. Server, costing aur documents ko
   pata bhi nahi chalna chahiye ke row kis screen ne banai.
2. **Rows ki tarteeb = kaghaz ki tarteeb** — hilne ke baad indexes dobara likhna.
3. **Line ka snapshot** — product master se kabhi dobara na parhna.
4. **Save ka farq** — row banna aur estimate save hona do alag cheezein hain, aur screen
   par ye farq nazar aana chahiye.

Baqi sab — kahan likha jata hai, kitne columns, kaunsa button kahan — **badla ja sakta hai.**

---

## 10. Naye design ke liye jo faisle aap ke hain

Aap ke bheje hue mockup ko dekh kar, ye wo sawal hain jin ka jawab mujhe chahiye:

1. **Likhna table ke andar** — yaani punch bar khatam, aur nayi/edit hone wali line **table
   ki apni row** me bhari jaye, aakhir me **Save Row**. Durust?
2. **Item ka dropdown row ke andar** — 706 — Chicken Biryani Special wali shakl. Search
   wahin, usi box me?
3. **Instructions do alag columns** — "Kitchen Instructions" aur "Additional Note" (abhi ek
   hi khana hai). Aur "PRODUCT LINKED" ka badge — matlab product se khud bhara hua?
4. **Material par SKU aur badge** — `RM-CH-01` + `PARTY ALLOWED` / `OWN ONLY`. (Kal raat
   maine badge hata kar unit likh diya tha — wapas laun?)
5. **Unit aur Urdu ke columns** — aap ke mockup me nahi hain. Unit "per KG" ki soorat me
   System Rate ke neeche hai. Urdu ka khana bilkul hata dein?
6. **"Material Breakdown" ka grouped header** — paanch columns ke ooper ek chhata. Haan?
7. **Neeche wali satar** — `Material rows: 2 · Supply validation: OK · Instruction & Note:
   Product-linked`. Ye har waqt dikhe ya sirf likhte waqt?
8. **Save Row ke baad** — row usi jagah rah kar padh-ने wali ban jaye, aur neeche ek nayi
   khali entry row khul jaye?
