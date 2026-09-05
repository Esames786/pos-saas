# KOT-SENT-POOL-2 — chal rahe bill par doosri helping kitchen tak nahi pahunchti thi

**Tareekh:** 2026-09-03
**Branch:** `feat/kot-sent-pool`
**Halat:** fix + guard green, **deploy nahi hua**
**Toota kab:** `22ad93e` (31 Aug, POS-CANCEL-TERMINAL-1)

---

## 1. Shikayat

Kashif Food, bill `HS-20260902191935-749`, counter T3:

- **00:19 PKT** — pehla `Singaporean Rice (Regular) (Midnight)` punch hua → KOT + reminder + receipt teenon chhape.
- **00:22 PKT** — wohi bill recall kar ke **doosra wohi deal** add hua. Cart 2 lines, total 1,100.
- **Na KOT nikla, na reminder.**

---

## 2. Wajah

Held order save hone par uski **saari lines mita kar dobara banti hain**. Is liye har nayi row ko batana parta hai ke us me se kitna kitchen ko pehle hi ja chuka hai. Do raaste hain:

- POS ne line ko **id** se pehchana → apni mehfooz sent-quantity leti hai (`$kotSentByLineId`).
- POS id nahi de saka → **pool** se leti hai, jo `product + variant + kind + combo` par bana hota hai (`KOT-SENT-POOL-1`).

Dono aadhe **ek doosre se baat nahi karte the**. Id wali line pool me se apna hissa **nikalti nahi thi**, is liye jab agli, be-naam line pool par pahunchti to poora `1` waheen para hota — aur wo **wohi 1 dobara** utha leti.

Nateeja: nayi line paidaishi hi `kot_sent_quantity = 1` — delta sifr — koi parchi nahi.

Bill 1435 ka DB isi ki gawahi deta hai: dono component lines par `kot_sent_quantity = 1`, magar `kot_batches` me sirf **ek** line (batch #2133, qty 1).

Yehi wo bimari hai jise POOL-1 khatam karne aaya tha, aur wo sab se aam shakl me bach gayi: **purani line id ke saath wapas aati hai, nayi helping bina id ke.**

### Ye sirf deals ka masla nahi tha

Guard likhne ke baad pata chala ke **aam item par bhi wohi hota tha**. Fix hataa kar chalane par
`test_a_plain_item_added_to_a_running_bill_prints` bhi red hua — 2 punch hui, kitchen sirf **1** gayi.

Jab bhi chal rahe bill par **usi product ki doosri ALAG line** banti hai, chahe deal ho ya aam item,
wo parchi nahi jaati thi. Live scan me chaar hi bill mile aur chaaron deal wale — is liye ke aam
item par POS mojooda line ki **quantity barha deta hai** (jis ki id maujood hoti hai), nayi line
nahi banata. Nayi line zyada tar deal ki soorat me banti hai. Bimari aam thi, us tak pahunchne ka
raasta kam.

---

## 3. Fix

`HeldSaleController::store()` — pool aur id, dono ek hi bahi-khata dekhein:

1. Pool banate waqt har line ka **key aur contribution yaad** rakho (`$kotSentPoolByLineId`).
2. Lines banane se **pehle**, har us line ka poora purana sent-quantity pool se **ghata do** jise POS ne id se naam diya.

Ghatana lines banane se pehle hota hai, is liye POS lines kis tarteeb me bhejta hai is par kuchh nahi kharabta.

**Poora sent-quantity ghataya jata hai, sirf bacha hua hissa nahi.** Jo miqdar line ne chhori, wo upar hi cancel ho chuki hoti hai (`recordLineCancellations`), aur cancel-shuda khana pool me para nahi chhorna chahiye ke koi nayi line use wirasat me le le.

Pool ab `kot_sent = true` **ya** `kot_sent_quantity > 0` dono se banta hai — jo line aadhi bhej di gayi ho uska `kot_sent` false hota hai, aur pehle wo pool se bahar reh jati thi.

Har mojooda line pool se **sirf ek baar** ghatti hai (`$drainedLineIds`). Agar POS ek hi line ko do rows me tor kar bheje (dono par wohi id), to do baar ghatane se kisi **doosri** line ka hissa bhi pool se nikal jata.

---

## 4. Kahan kahan asar

| File | Kya |
|---|---|
| `app/Http/Controllers/Tenant/HeldSaleController.php` | pool ka draw-down (37 lines) |
| `tests/MySql/KotSentPoolMySqlTest.php` | naya guard, 8 test |

**Migration nahi. Route nahi. Permission nahi.** Doosre write-paths dekhe gaye:

- `SalesOrderController` — sirf id se leta hai, nayi line ko hamesha 0. Pool hai hi nahi → mehfooz.
- `EdgeLocalPosService` — wohi, id-only → mehfooz.
- `SplitBillController` — parent se `min(sent, splitQty)` leta hai, ye **durust** hai (neeche dekhein).

---

## 5. Saboot

### Guard 4/4 — aur fix hataane par kaat-ta hai

Fix nikal kar chalaya to **3 test red**, baqi **5 waise ke waise pass** — yani fix ne rozmarra ke
kaam ko chhua hi nahi:

| Test | Fix ke baghair | Fix ke saath |
|---|---|---|
| Doosri helping (deal) kitchen jaye | ✘ 2.0 sent jabke 1 bheji gayi | ✔ |
| Teesri helping bhi jaye | ✘ 3 me se 1 pakti | ✔ |
| **Aam item doosri baar add** | ✘ 2 me se 1 pakti | ✔ |
| Sent item ki quantity barhana (2 → 3) → sirf farq ka 1 | ✔ | ✔ |
| Plain bill par pehli deal add | ✔ | ✔ |
| Wohi product akela + deal ke andar (BUG-014) | ✔ | ✔ |
| Bina kuchh add kiye dobara save → kuchh na chhape | ✔ | ✔ |
| Bheja hua khana bina wajah bill se na nikle | ✔ | ✔ |

Test asal endpoint chalate hain — pehle `POST /held-sales`, phir `POST /printing/jobs/kot/{sale}` — bilkul waise jaise counter karta hai. Doosre save par KOT jaan boojh kar rok kar dekha jata hai ke **save ne khud kya faisla kiya**; KOT chhapne ke baad to nayi line ka sent hona bilkul durust hai.

Chautha test us bahar wali zamanat ko pin karta hai jis par ye fix tikka hua hai: **jo khana kitchen ja chuka ho wo chupke se bill se nahi nikal sakta** — bina id ke wapas bhejna "sent food hataana" parha jata hai aur wajah ke baghair radd hota hai. Isi liye poora purana sent-quantity pool se ghatana mehfooz hai: koi cheez baad me aa kar ye dawa nahi kar sakti ke wo wohi bheja hua khana hai.

### Live data par kitna hua

| Bill | Tareekh | Item | Bill hui | Kitchen gayi |
|---|---|---|---:|---:|
| HS-20260830180502-816 | 30 Aug | Singaporean Rice (Regular) | 5 | 4 |
| HS-20260831191726-114 | 31 Aug | Singaporean Rice (Regular) | 2 | 1 |
| HS-20260902180714-729 | 2 Sep | Singaporean Rice (Regular) | 3 | 2 |
| HS-20260902191935-749 | 2 Sep | Singaporean Rice (Regular) | 2 | 1 |

**Khatri bilkul saaf.** Chaaron ek hi shape: chal rahe bill par wohi deal doosri baar.

`SO-20260902131928-646` (Nuggets) pehle scan me aaya tha — wo **bug nahi**. Table session 459 par order #1214 ne 12:43 baje Nuggets kitchen bhej diya tha; #1222 us ka split-bill child hai jo sent-quantity parent se le kar aata hai, is liye us ne dobara nahi chhapa. Durust. Scan per-order compare karta hai is liye us ne ise bhi flag kar diya tha.

### Paise par asar — koi nahi

`kot_sent_quantity` sirf ye tay karta hai ke parchi jaye ya nahi. Wo `quantity`, `unit_price`, `line_total`, `subtotal`, `grand_total`, payments ya ledger me se kisi ko haath nahi lagata. Chaaron bill live check kiye — har ek ka grand total apni lines se poora milta hai aur har ek **poora wasool** ho chuka hai. Nuqsan sirf operational tha: **bill zyada ka bana, khana kam paka.**

---

## 6. Repair

Chaaron bill ab paid aur band hain — dobara chhapne ko kuchh nahi. Koi data fix nahi chahiye.

Aage ke liye: agar wohi surat dobara bane to counter ko `Reprint KOT` maujood hai; ye fix us naubat ko aane nahi deta.

---

## 7. DEPLOYMENT RISK — chalti hui service par ye kya chhoo-ta hai

Ye POS ki sab se hassaas screen hai. Deploy us waqt hoga jab counter khula ho, mez par bill khule hon aur shift chal rahi ho — is liye har khatra alag alag likha ja raha hai, khulasa nahi.

### 7.1 Live halat (2026-09-03, 00:49 PKT par naapi gayi)

| | Kashif Food | Khatri |
|---|---:|---:|
| Khule bill (held/draft) | **6** | 0 |
| Khuli shifts | 4 | 2 |
| Queued print jobs | 0 | 0 |
| Aakhri sale | 00:41 PKT | 00:41 PKT |

**Chhe khule bill me se kisi par bhi gap nahi** — har ek ka `claimed_sent` = `kitchen_gaya`. Yani is waqt koi bill aisa nahi jo deploy ke baad ajeeb harkat kare.

### 7.2 Deploy me jaa kya raha hai

| | |
|---|---|
| Code files | **1** — `HeldSaleController.php` (37 lines) |
| Migration | **koi nahi** (prod par pending bhi koi nahi) |
| Naya route / permission | **koi nahi** |
| Schema / column | **koi nahi** |
| Data mutation | **koi nahi** — deploy kisi bill ko haath nahi lagata |
| Config / .env / cron | **koi nahi** |
| `deploy.sh` khud badla? | **nahi** → ek hi run kaafi hai |

Badla hua code **sirf us waqt chalta hai jab `held_sale_id` maujood ho** — yani recall kar ke save karna (Add Round / Continue). **Naya bill hold karna, Review & Pay, Quick Sale, Direct Pay, split bill, return — in me se koi is line se nahi guzarta.**

### 7.3 `deploy.sh` chalte waqt asal khatra kahan hai

| Step | Kya karta hai | Chalti service par asar |
|---|---|---|
| [1] git pull | files badal deta hai | jo request pehle se chal rahi hai wo apni purani (opcached) copy par mukammal hoti hai |
| [5] tenant migrations | is baar **kuchh nahi**, pending sifr | — |
| [6] permission cache flush | per-tenant rows | is change me koi nayi permission nahi, is liye be-asar |
| **[7] `optimize:clear` + rebuild** | **yahan ek chhoti khirki hai** | is dauran aane wali request bina cache ke chalti hai — **thodi susti, kharabi nahi**. Rebuild `www-data` ke taur par hota hai (Khatri 28 Aug wala outage isi liye ab nahi hota) |
| [9] `systemctl reload php8.2-fpm` | **reload, restart nahi** | graceful: chalti hui request poori hone diye jati hai, naye workers naya code uthate hain. `restart` hota to request kat sakti thi — `reload` me nahi |

**Sab se buri surat:** ain us second par POS ne Hold dabaya aur worker recycle ho gaya → POS ko error milta hai, operator dobara Hold dabata hai. **Aadha likha hua kuchh nahi bachta**, kyunke poora save ek hi DB transaction me hai (`DB::transaction`, shift row-lock ke saath). Bill ya poora bane ga ya bilkul nahi.

### 7.4 Har surat me kya hoga (deploy ke ain waqt)

| Surat | Deploy ke baad |
|---|---|
| Cashier ka cart khula hai, abhi save nahi kiya | agla Hold **naye code** par chalega → nayi helping theek se chhap jayegi. Behtar, kharab nahi |
| Bill khula hai, lines theek stamped hain (aaj ke chhe bill) | **koi farq nahi**. Har line id se pehchani jayegi, apni wohi mehfooz miqdar legi |
| Bill khula hai aur us par ghalat stamp maujood hai | stamp jyun ka tyun rahega — na theek hoga, na aur bigrega. Wo line phir bhi nahi chhapegi. **Abhi aisa ek bhi bill nahi hai** |
| Adhoori request deploy ke beech me | transaction rollback → bill waisa hi jaisa pehle tha |
| Purana POS page browser me khula hai | payload ki shakl bilkul nahi badli — purana page naye code se theek baat karta hai. **Cashier ko Ctrl+F5 ki zaroorat nahi** (ye backend-only change hai) |
| Edge / offline branch | `EdgeLocalPosService` alag raasta hai aur us me pool hai hi nahi → be-asar |

### 7.5 Jo ye fix **nahi** karta (saaf saaf)

- **Purana nuqsan khud theek nahi karta.** Jis bill par pehle se ghalat stamp hai, wo waisa hi rahega. Chaaron mutasira bill paid aur band ho chuke hain, is liye theek karne ko kuchh bacha nahi. Agar aage koi khula bill mila to counter `Reprint KOT` se parchi nikaal sakta hai.
- **Duplicate id wali surat pehle jaisi hai.** Agar POS ek hi line id do rows par bhejay, to dono ko poori sent-quantity milti thi — ye pehle bhi tha aur ab bhi hai. Sirf itna kiya gaya ke pool us line se **ek hi baar** ghata (`$drainedLineIds`), warna doosri line ka hissa bhi saath chala jata.
- **Nuggets wali surat (split bill)** — wo bug thi hi nahi, use haath nahi lagaya gaya.

### 7.6 Wapas palatna (rollback)

Ek file, ek commit. `git revert <sha>` + `bash deploy.sh` — koi migration wapas nahi karni, koi data nahi palatna. Rollback ke baad system bilkul aaj wali halat par aa jata hai (bug samet).

### 7.7 Kab deploy karna behtar hai

Kaam **rush ke bahar** karna chahiye — mashwara: **subah, jab shift band ho aur koi bill khula na ho** (abhi 00:49 PKT par Kashif ke 6 bill khule hain aur 4 shifts chal rahi hain). Kharabi ka koi rasta nazar nahi aata, lekin ain dinner rush me FPM reload karne ka koi faida bhi nahi.

Deploy ke foran baad check karne wali cheezein:
1. `git rev-parse --short HEAD` = naya sha
2. Ek asli bill par: deal punch → KOT chhapi? → recall kar ke wohi deal dobara → **doosri KOT chhapni chahiye**
3. Scan dobara chalao — koi naya gap nahi aana chahiye
4. `storage/logs/laravel-<aaj>.log` me koi naya error nahi
