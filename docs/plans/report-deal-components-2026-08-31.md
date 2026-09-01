# REPORT-DEAL-COMPONENTS-1 — deal ki ginti report me sach bole

**Status:** PLAN — abhi banaya nahi gaya
**Date:** 2026-08-31
**Raised by:** owner (Kashif Food), 30 aur 31 Aug ki reports par
**Asar:** Kashif Food **aur** Khatri dono — ek hi engine hai

---

## 1. Owner ne kya dekha

- ITEMS me **"Singaporean Rice Khass (2-3 Persons)" qty 10**, jabke deal us din **5 baar** bika
- Categories me **"Al-Faham Components" 17 qty, Rs 0.00** — ginti hai, paisa nahi
- ITEMS me **"Regular Drink" 39 qty, Rs 0.00** — lagta hai 39 drink muft dii gayin
- **"Chicken Baluchi Boti" 6 qty / Rs 1,250** — magar in me se **5 deal ke andar gaye thay**, sirf 1 asal me bika

---

## 2. Hota kya hai (aaj ka design)

Deal bikne par POS **do tarah ki lines** banata hai:

| line_kind | Kya | Paisa |
|---|---|---|
| `combo_header` | deal khud | **poora daam isi par** |
| `component` | deal ke andar ke items | **0.00** — hamesha |
| `standard` | aam item | apna daam |

31 Aug: standard 136 lines/Rs 77,720 · combo_header 4/Rs 17,675 · **component 14/Rs 0.00**

To **paisa kabhi double nahi ginta** — report ka har financial figure aaj bhi durust hai.

Magar `SalesReportEngine::linesBase()` **`line_kind` par koi filter nahi lagata**, aur
`byItem()` sirf `product_id` par group karta hai. Is liye components har us section me ghus
jaate hain jo lines par bana hai: `overview`, `byCategory`, `byItem`, `dimensionReport`,
`detailedQuery` — aur `orderTypeCombos` bhi, jo inhin ko dobara chalata hai.

### Khaas wali qty 10 ka asal sabab

```
combo_header  product_id=206  "Singaporean Rice Khass (2-3 Persons)"  qty 5  Rs 14,500
component     product_id=206  "Rice of Khaas"                          qty 5  Rs 0
                          ↑ deal ka header AUR uska apna rice component, ek hi product
```

`byItem` product_id par group karta hai → dono mil kar **10**, aur naam `MAX(product_name)`
se "Singaporean Rice Khass" chun liya jaata hai. Paisa phir bhi sahi (14,500), sirf ginti
do guni.

---

## 3. Asar naapa gaya — asli aankday

**Ahem tareen baat: components nikaalne se ek rupya nahi hilta.**

| | 30 Aug | 31 Aug |
|---|---|---|
| TOTAL qty jo report chhapta hai | **1,620** | **228** |
| ...components ke baghair (asal bika hua) | **1,322** | **196** |
| components akele | 298 (209 lines) | 32 (17 lines) |
| **paisa kitna hila** | **0.00** | **0.00** |

Yani qty **18% phooli hui** hai, paisa bilkul theek.

**Categories jo sab se zyada bigdi hain (30 Aug):**

| Category | Report kehta hai | Asal bika | Deal ke andar gaya |
|---|---|---|---|
| Bar-B-Que | 148 | **87** | 61 |
| Singaporean Rice | 430 | **372** | 58 |
| Beverages | 397 | **342** | 55 |
| Burgers | 75 | **54** | 21 |
| Fries | 28 | **15** | 13 |
| Bar-B-Que New Arrivals | 13 | **2** | 11 |

**Items jinme asal sale aur deal component mile hue hain (31 Aug):**

```
Singaporean Rice Khass (2-3 Persons)  10  =  5 bika  +  5 deal ke andar
Chicken Baluchi Boti                   6  =  1 bika  +  5 deal ke andar   Rs 1,250
Chicken Boti Boneless                  5  =  0 bika  +  5 deal ke andar   Rs 0
Regular Drink                          5  =  0 bika  +  5 deal ke andar   Rs 0
```

---

## 4. Tajweez

### (a) ITEMS aur CATEGORIES sirf asal sale dikhayein

`linesBase()` me ek nayi soorat: `component` lines **kharij**. Un ka paisa 0 hai, is liye
har financial number jyon ka tyon rahega — sirf qty sach bolne lagegi.

Ye Khaas wali double-ginti **khud ba khud theek kar deta hai**: header ki 5 bachegi,
component ki 5 nikal jayegi → **qty 5**, wahi jo asal me bika.

### (b) Ek naya section: "Deals ke andar gaye items"

Sirf `component` lines, **sirf qty**, koi paisa column nahi. Us se ye pata chalta rahega ke
kitchen se kitni Baluchi Boti nikli — jo aaj items list me chhupa hua hai.

```
DEALS KE ANDAR GAYE ITEMS
Item                       Qty
Chicken Baluchi Boti         5
Chicken Boti Boneless        5
Chicken Shahi Chattakh       5
Rice of Khaas                5
Regular Drink                5
```

### (c) Jo NAHI badlega

- **Koi financial figure nahi** — sold, returns, net, NET SALES, categories ka paisa
- `combo_header` apni poori raqam ke saath ITEMS me rahega (deal ka naam + daam)
- Detailed section (line-by-line) me components rahenge — wo raw record hai, wahan sab hona chahiye

---

## 5. Khatra aur ehtiyat

- ⚠️ **Khatri par bhi lagega** — ek hi engine hai. Deploy se pehle Khatri ki ek din ki
  report ka before/after nikaalna hoga aur owner ko dikhana: **paisa 0.00 hilega**, qty
  ghategi. Ye durusti hai, magar unhe pehle se bata dena chahiye.
- ⚠️ **Quick Report aur roz ki email report** wahi engine chalate hain — apne aap theek
  ho jayenge, koi alag kaam nahi.
- ⚠️ `orderTypeCombos` bhi `byItem`/`byCategory` chalata hai — us ka fix wirse me mil jayega.
- ⚠️ **Purani reports badal jayengi.** Agar kisi ne 30 Aug ki report print/email ki hai, ab
  wahi report dobara nikalne par qty kam aayegi (paisa wahi). Owner ko batana zaroori hai.

## 6. Test plan

`ReportDealComponentsMySqlTest`:
- ek deal + ek standalone sale ka wahi product → item row **sirf asal bika hua** dikhaye
- **paisa har surat me wahi rahe** — fix se pehle aur baad ka total barabar (yehi sab se ahem)
- header aur component ek hi product par hon (Khaas wali soorat) → qty **do guni na ho**
- "deals ke andar" section me sirf components aayen, aur us me koi paisa column na ho
- detailed section me components **ab bhi** mojood hon
- Guard sabit karna: fix hata kar dekhna ke test laal hota hai

Regression: Report + Dashboard + Catering suites; Khatri ka ek din ka before/after.

## 7. Alag masla (isse mat milaayein)

`#161 Singaporean Rice (Khass)` aur `#206 Rice of Khaas` — do product, dono POS se chhupe
hue. Deal ka header #206 par baitha hai jo uska apna component bhi hai. Upar wala fix report
ko theek kar deta hai **is data ko chhue baghair**, magar catalogue ki safai alag se karni
chahiye — wo owner ke faisle ka muntazir hai (retire vs delete).
