# Receipt me ghalti ho jaye to kya karein

Date: 2026-10-01 (Asia/Karachi)
Kis ke liye: malik, aur wo jo ye code aage sambhalega
Haalat: bana hua, 10 test green, deploy baqi

---

## Sawal

> "Client keh raha hai us ne amount daalte hue ghalti kar di, ya reference
> likhte hue — wo kaise edit hoga?"

Aaj tak **koi raasta nahi tha**. Receipt par sirf *darj karo* tha. Ghalti ho
jaye to:

| Ghalti | Pehle kya karna parta tha |
| --- | --- |
| Rakam zyada daal di | Refund se farq wapas karo — aur ledger par ek refund baith jata jo hua hi nahi tha |
| Rakam kam daal di | Ek aur receipt daalo |
| Poori entry hi ghalat (duplicate, ya ghalat booking par) | **Kuch nahi** |

---

## Faisla: amount "edit" nahi hoti

Ye sab se ahem baat hai aur jaan-boojh kar hai.

Jab receipt darj hoti hai to **paisa us waqt hil chuka hota hai**: ek journal
entry ban chuki hoti hai aur cash/bank ka balance barh chuka hota hai. Us adad
ko chup chaap badal dena sab se bura hal hota — **kitabon me ek adad reh jata
aur screen par doosra**, aur koi nishan na hota ke kya hua tha.

Is liye do alag karwaiyan hain:

| Karwai | Kya karti hai | Paisa hilta hai? |
| --- | --- | --- |
| **Ulta karein** (Void) | Journal ulti hoti hai, cash/bank wapas, receipt par VOIDED ka nishan | Haan — wapas |
| **Reference theek karein** | Sirf slip number aur note | Nahi |

Rakam ghalat ho to: **ulta karo, phir sahi nayi darj karo.** Poora trail qayam
rehta hai — kya darj hua tha, kyun ulta kiya, aur kis ne.

Ye wohi tareeqa hai jo `ExpenseService::void()` is system me barson se chala
raha hai. Koi naya tareeqa ijaad nahi kiya gaya.

---

## Kahan milega

Dono jagah — malik ne pehle booking ki screen par maanga, phir Customer
Catering Balances ka screenshot bhej kar kaha *"is screen pe bhi"*:

1. **Booking → Receipts & Refunds** — har receipt ki satar par ✏️ aur 🚫
2. **Finance → Customer Catering Balances → graahak → Ledger** — wahi do
   nishan, aur kaam ke baad usi screen par wapsi

Parcha (modal) **ek hi partial** se aata hai aur service bhi ek hi hai. Do
jagah alag markup likhna aasan tha aur ghalat hota: kal ki tabdeeli ek jagah
hokar doosri jagah reh jati.

---

## Chaar hudood

### 1. Wajah lazmi hai

Chhe mahine baad *"ye 40,000 kahan gaye"* ka jawab sirf wahin milega. Wajah
receipt aur ledger dono par hamesha likhi rehti hai.

### 2. Invoice ke baad void nahi

Jaari shuda final invoice apne andar ye rakam **jama kar chuki** hoti hai, aur
wo document immutable hai. Receipt ulti kar dene se invoice ek aisi rakam ginti
rehti jo mojood hi nahi.

Us surat me sahi raasta **Refund** hai. Screen bhi us waqt Void ka button
**pesh hi nahi karti** — ek aisa button jo dabane par hamesha error de, screen
ka jhoot hai. (Reference phir bhi theek ho sakta hai: us se paisa nahi hilta.)

### 3. Do baar dabane se paisa do baar wapas nahi hota

Reversal idempotent hai — bilkul expense voucher ki tarah.

### 4. Ulti hui receipt mitti nahi

Wo record par rehti hai, `VOIDED` nishan aur wajah ke saath, booking ki screen
par bhi aur ledger par bhi. **Chhupa dena aasan tha aur ghalat hota:** screen
par ek khala reh jata aur koi na jaanta ke us din paisa aaya tha aur phir wapas
gaya.

---

## Is kaam ka sab se bara khatra

Advance ka paisa **nau jagah** gina jata hai — position, calendar, dashboard,
Customer Balances, invoice, refund ki hadd, aur baqi. Agar ulti hui rakam un me
se **ek jagah bhi** reh jati, to graahak ke zimme kam paisa dikhta aur kisi ko
pata na chalta.

Is liye filter har jagah alag alag nahi lagaya gaya. `CateringAdvance` par ek
**global scope** hai: ulti hui receipt har query se khud-ba-khud bahar hai.
Jisay wo chahiye use `withoutGlobalScope('notVoided')` likh kar **sarih tor
par** maangna parta hai, aur poore system me wo sirf **teen jagah** likha hai:

- `CateringFinancialPositionService::ledger()` — statement par VOIDED satar
- `CateringEventController::show()` — booking ki screen ka VOIDED hissa
- `CateringAdvanceService` — void aur reference khud, jinhein us receipt tak
  pahunchna hi hai jo ab chhupi hui hai

Teenon dikhane ya theek karne ke liye hain. **Kisi ginti me nahi.**

Ye pehra sabit bhi kiya gaya: scope hata kar test chalaya, 40,000 phir se ginti
me aa gaye, test laal hua. Phir bahal kar diya.

---

## Pehre (10 tests)

`tests/MySql/CateringAdvanceVoidMySqlTest.php`

1. Void se journal ulti hoti hai aur cash wapas
2. **Ulti rakam HAR ginti se nikal jati hai** — position, Customer Balances,
   aur seedha relation, teenon alag raaste se jaanche gaye
3. Magar ledger par nazar aati hai, wajah ke saath, aur hisaab me nahi jorti
4. Do baar void karne se paisa do baar wapas nahi hota
5. Invoice ke baad void mana
6. Reference badalne se koi journal entry nahi banti aur cash nahi hilti
7. Wajah ke baghair void mana
8. **Dono screens** button pesh karti hain
9. Invoice ke baad Void ka button wapas le liya jata hai (reference rehta hai)
10. Ulti hui receipt booking ki screen par nazar aati hai

---

## Ek baat jo khuli hai

Void ka ikhtiyar abhi usi ke paas hai jis ke paas `tenant.catering.advances.void`
ho — yani Owner. Agar amal me rozana ki ghalti kisi aur se theek karani ho to
ye permission alag se di ja sakti hai; abhi jaan-boojh kar nahi di gayi, kyunke
ye karwai paisa kitabon se nikalti hai.
