# Invoice se ZYADA paisa lena — abhi kya hota hai, aur agar chahiye to kaise

**Date:** 2026-09-09 · **Tenant:** `kashifkitchen` · **Status:** RESEARCH — koi code nahi laga

Owner ka sawal: *"invoice 10,000 hai magar hum 20,000 le lete hain — mujhe nahi lagta system
me is ka option hai? ye bhi dekh lena, MD bana lena ke kaise manage hoga, ledger kis head me
jayega, payoff kaise hoga aur kahan se."*

---

## 1. Chhota jawab

**Option hai hi nahi — aur ye bhoolne se nahi, jaan boojh kar hai.**

Ye pabandi **model ki satah par** lagi hai (`CateringAdvance::creating`), yaani screen, API,
command — **har raasta** isi qaide se guzarta hai. Jo paigham aata hai:

> *"Advance of 20,000.00 exceeds the outstanding balance of 10,000.00 for EV-… Taking more
> would leave the business holding money it has not billed for — raise the quotation first if
> the customer is paying for more."*

Aur agar booking par pehle se customer ka credit khara ho:

> *"…is already carrying X of credit owed to the customer, so no further payment can be taken.
> Refund the credit first, or raise the quotation to cover it."*

Code me likhi hui wajah bhi maujood hai: **receipt us paise ke liye ghalat auzaar hai jiska
business ne bill hi nahi kiya.** Agar customer zyada de raha hai, to pehle **quotation** ye
baat kahe.

---

## 2. Paisa aaj kis head me jata hai

| kab | Debit | Credit |
|---|---|---|
| **Advance** (invoice se pehle) | mapped Cash/Bank (na ho to **1500 Undeposited Funds**) | **2300 Customer Advances** |
| **Final invoice jaari** | **1300 Accounts Receivable** | **4160 Catering Revenue** (+ 4200 discount) |
| **Advance invoice par lagana** | **2300** | **1300** — *invoice ki apni qeemat tak, us se zyada kabhi nahi* |
| **Settlement** (invoice ke baad payment) | mapped Cash/Bank | **1300 Accounts Receivable** |
| **Refund** (paisa wapas) | **2300** | mapped Cash/Bank — **naam liye baghair paisa bahar nahi ja sakta** |

Do baatein jo is naqshe me ahem hain:

1. **2300 ek liability hai** — us par khari raqam wo hai jo business par **customer ka udhaar**
   hai, aamdani nahi. Advance lene se **kamai nahi hoti**; kamai tab hoti hai jab invoice
   jaari ho (4160).
2. **Refund hamesha 2300 se** hota hai. Code me wajah likhi hai: credit sirf wahin baith
   sakta hai, kyunki receipt outstanding se zyada le hi nahi sakti aur invoice apni qeemat se
   zyada laga nahi sakti. Agar kabhi 2300 ke bajaye 1300 me credit mila, wo **kahin aur ka
   bug** hai.

---

## 3. To "customer credit" phir aata kahan se hai?

System me `customer_credit` **maujood hai** (`CateringFinancialPositionService`), aur us par
Refund bhi ho sakta hai. Magar wo **zyada wasooli se nahi** banta — wo tab banta hai jab
**bill ghat jaye** us ke baad jitna paisa aa chuka hai:

```
customer_credit = max(net_received − billed, 0)
balance_due     = max(billed − net_received, 0)      // dono me se ek hi ghair-sifar hoga
refundable      = customer_credit                     // sirf wahi paisa jo kisi bill par nahi laga
```

Yaani: 20,000 aa chuke thay 20,000 ke bill par, phir invoice ghat kar 15,000 ka bana → 5,000
customer credit → refund se wapas.

**Payoff ka raasta yehi hai:** booking par **Refund**, jo `refundable` se zyada kabhi nahi
hota, aur jo `Dr 2300 / Cr cash-bank` post karta hai. Refund par **cash/bank account ka naam
lazmi** hai — bina naam ke paisa bahar jaane se system inkar karta hai.

---

## 4. Agar owner waqai zyada lena chahen — teen raaste

### Raasta A — quotation barha do (system yehi chahta hai)

Quotation/invoice 20,000 ka karo, phir 20,000 lo.

- Kamai 20,000 (4160), AR 20,000, receipt 20,000 — **paisa aur aamdani barabar**
- **Koi code nahi badalta**, koi migration nahi
- Kaghaz par bhi sach: customer ke paas jo quotation hai wo 20,000 kehti hai
- **Nuqsan:** agar zyada raqam kisi khidmat ki nahi, sirf "peshgi/amanat" hai, to use
  aamdani likhna **ghalat** hoga

### Raasta B — extra ko AGLI booking ka advance banao

Naya event banao aur wo raqam us par advance ki tarah lo.

- Ledger durust: 2300 par us booking ke naam
- **Koi code nahi badalta**
- **Nuqsan:** agli booking abhi tay na ho to event banana masnooi lagta hai

### Raasta C — zyada wasooli ko soch samajh kar ijazat do *(code chahiye)*

Model ka guard narm karo taake extra raqam **2300 par customer credit** ban kar baithe.

| kya karna parega | tafseel |
|---|---|
| Guard me ek **jaan boojh kar** wala raasta | sirf "over-collection" ki alag ijazat + **wajah likhna lazmi**, warna wahi purana inkar |
| Advance ko do hisso me post karna | bill jitna → jaisa abhi; extra → **Cr 2300** (head pehle se durust hai) |
| Screen par saaf dikhana | "Customer credit held: 10,000" — taake ye kabhi aamdani na samjha jaye |
| Refund ka raasta | **pehle se maujood hai** aur `refundable` se bandha hua hai |
| Ek booking se doosri par credit le jana | **aaj bilkul nahi hai** — ye alag aur naya kaam hai |
| Permission | naya kaam = naya permission, har role par alag se dena parega |

⚠️ **Aur ek baat jo chhupani nahi chahiye:** 2300 par khari raqam **business par qarz** hai.
Us par jitna paisa jama hota jayega, kaghaz par nazar to aayega magar wo **aap ka nahi**.
Aaj ka guard isi liye kehta hai *"business ko wo paisa nahi rakhna chahiye jiska us ne bill
nahi kiya"* — ye ek **hisab-kitab ka mauqif** hai, koi technical hadd nahi.

---

## 5. Meri tajweez

**Raasta A** — jab customer zyada de raha ho to quotation usi waqt barha do. Ye aaj kaam
karta hai, ledger sach bolta hai, aur customer ke paas jo kaghaz hai wo bhi wohi kehta hai.

**Raasta C** tab banaya jaye jab ye soorat **baar baar** pesh aaye aur owner ye tay kar den ke
extra raqam ko **amanat** samjha jaye, aamdani nahi. Us din:

1. pehle guard ka narm raasta + wajah lazmi
2. phir screen par "credit held" ka saaf khana
3. refund pehle se hai — kuch nahi karna
4. ek booking se doosri par credit le jane ka kaam **alag** hoga

---

## 6. Kya ab bhi maloom nahi

- **Kya extra raqam par kabhi tax/receipt ka masla hai?** — Kashif Kitchen par tax abhi 0 hai,
  magar agar kabhi laga to "amanat" par tax nahi banta aur "aamdani" par banta hai. Ye farq
  raasta A aur C ke darmiyan ka asal faisla hai.
- **Aaj tak aisa hua kitni bar?** — live par abhi tak koi refund aur koi customer credit
  nahi hai (journals 0), is liye ye soorat abhi **kabhi pesh nahi aayi**. Ye tajweez ko badalta
  hai: naya code banane se pehle ek-do dafa raasta A aazma kar dekha jaye.
