# Kashif Food — 3 September wali tareekh ki durusti (amal ka plan)

**Tareekh:** 2026-09-04 · **Tenant:** kashiffood (#348) · **Branch:** 1
**Halat:** PLAN — prod par abhi kuch nahi badla.
**Tehqeeq:** `kashif-sep3-business-date-leak-2026-09-04.md` (commit `7235943`)
**Snapshot:** 2026-09-04 **23:11 PKT** — figures barh rahe hain, §5 dekhein.

---

## 1. Owner ke do sawal, seedhe jawab

> **"Shift band karne se koi order gum to nahi ho jayega?"**

**Nahi.** Ye khatra maujood hi nahi. `ShiftService::unresolvedWork()` shift ko band hone se
**rok deta hai** jab tak uska koi held bill ya khuli table baqi ho — yani guard orders ko
**bachata** hai, mitaata nahi. Close sirf shift ki row par muhar lagata hai (`status`,
`closed_at`, `counted_cash`); kisi bill ko haath tak nahi lagata. Poora amal ek transaction me
hai: kuch ruk gaya to sab kuch waisa hi rehta hai jaisa tha.

> **"Tumhein yaad rahega kaunsa order 3 se hata kar 4 me daalna hai?"**

**Yaad rakhne ki zaroorat hi nahi.** Un bills ki pehchan **data me likhi hui** hai, meri yaadasht
me nahi. Do nishaniyan, dono pakki:

```
bill ki business_date = 2026-09-03   AUR   bill bana = 4 September ya baad me
```

Aur tasdeeq ke liye doosri nishani: in **60 me se har ek `dine_in`** hai aur har ek ka table
session `opened_shift_id = 24` rakhta hai — yani har ek T4 ki usi purani shift se nikla hai.
Koi ajnabi bill is jaal me nahi aata (probe se tasdeeq-shuda).

Ye usool waqt par munhasir nahi. Aaj chalayein ya kal — wohi bills nikalte hain.

---

## 2. Kya kya hilega (23:11 PKT ki ginti)

| # | Kya | Rows | Usool |
|---|---|---:|---|
| 1 | `sales_orders` | **60** (172,930) | `business_date='2026-09-03'` AND `created_at >= '2026-09-04'` |
| 2 | `sales_order_line_cancellations` | **16** | jo un hi bills se jude hain aur `business_date='2026-09-03'` |
| 3 | `restaurant_table_sessions` | **60** | `opened_shift_id=24` AND `opened_at >= '2026-09-04'` |
| 4 | `shifts` (#24 khud) | **1** | `business_date` 3 → 4 |

Sab ki nayi qeemat: **2026-09-04**.

### Jo NAHI chhua jayega

- **69 table sessions** jo shift #24 par hain magar **waqai 3 September ko khule** — ye 3 Sep ke
  hain aur wahin rahenge.
- **3 Sep ke 364 asli bills** (628,970) — inhein koi haath nahi lagta.
- **6 posted returns** (4,950) — sab asli 3 Sep bills ke khilaf hain, koi adjustment nahi.
- **Koi daily closing nahi** — 3 ya 4 Sep ka closing record bana hi nahi, is liye wahan kuch
  durust karne ko nahi.
- Paisa, qeemat, GL, stock, receipt — **kuch nahi badalta**. Sirf ye ke kaunsa din.

---

## 3. Nateeja

| | Abhi | Durusti ke baad |
|---|---:|---:|
| **3 Sep net sale** | 763,250 | **624,020** |
| **4 Sep net sale** | 432,745 | **572,255** *(aur barhega jab baqi bills settle hon)* |

---

## 4. Tareeqa — do hisse

### Hissa A — leak abhi rokna (live tables ko chhue bagair)

Sirf **shift #24 ki `business_date` aur uski aaj wali sessions** 4 Sep kar dene se, us lamhe ke
baad T4 par banne wala **har naya bill khud-ba-khud 4 September** me jayega. Iske liye:

- ❌ shift band karne ki zaroorat **nahi**
- ❌ kisi grahak se paisa maangne ki zaroorat **nahi**
- ✅ 7 khuli tables jaise chal rahi hain, chalti rahengi

Aur chunkeh chaaron cheezein **ek hi transaction** me, **usool ke zariye** (id ki list se nahi)
badalti hain, is liye beech me banne wala koi bill chhootta nahi: ya wo purane usool me aa kar
theek ho jata hai, ya uske baad banta hai jab shift pehle hi 4 Sep ho chuki hoti hai. **Darmiyan
me koi khali jagah nahi.**

### Hissa B — raat ko, service khatam hone par

1. T4 ke baqi held bills settle/cancel hon aur tables band hon (grahak apne waqt par)
2. **Close Branch** se chaaron shifts band — ab T4 rukawat nahi banega
3. Kal subah har counter apni **nayi** shift khole

> ⚠️ Hissa B se **pehle** Close Branch mat dabana — chaaron shifts ek transaction me band hoti
> hain; T4 par rukawat aate hi poori cheez wapas ho jayegi aur DTQ 2 / DTQ 3 bhi khuli reh jayengi.

---

## 5. ⚠️ Intezaar ki qeemat

| Waqt (PKT) | 3 Sep ka net sale |
|---|---:|
| 22:34 | 746,790 |
| 23:04 | 760,330 |
| 23:11 | 763,250 |

Har guzarte minute ke saath 3 September aur ghalat hota ja raha hai. **Hissa A jitna jaldi ho,
utna kam kaam baqi rahega** — aur wo live service ko bilkul nahi chhedta.

---

## 6. Tasdeeq (durusti ke baad foran)

1. Dono dinon ke `net_sales` — theek wohi jo §3 me likhe hain
2. **Dono ka jama pehle jaisa** hi rahe: `763,250 + 432,745` = `624,020 + 572,255` — ek rupya na
   bane, na gum ho
3. `business_date='2026-09-03'` par ab **koi bhi bill aisa na ho jo 4 Sep ko bana** — sifar
4. 3 Sep ke 364 asli bills ki ginti aur raqam **be-harkat**
5. Nayi shift par ek test bill → uski `business_date` **4 September**

## 7. Wapsi

Har badlav ki ulti simt maujood hai: wohi rows wapas `2026-09-03`. Isi liye **badlav se pehle
un 60 bill ids ki list file me mehfooz** ki jayegi, taake wapsi ko bhi usool par bharosa na
karna pare.

---

## 8. Jo asal me tootna chahiye — warna ye dobara hoga

System shift ko **agli tareekh me chalte rehne se rokta nahi**. Aaj ye 172,930 par pakda gaya;
kal kisi aur counter par ho sakta hai. Do me se koi bhi kaafi hai:

1. **Warning** — POS kholte waqt saaf batana: "ye shift 3 Sep ki hai, aaj 4 Sep hai."
2. **Rukawat** — jis shift ki `business_date` aaj se purani ho, us par naya bill banne hi na dena.

Mera mashwara: dono. Ye code ka kaam hai (test + deploy), aur isay is durusti ke baad alag se
karna chahiye.

## 9. Ek notice jo darj hona zaroori hai

`ShiftService` me likha hai ke `business_date` shift khulte waqt **jam** jaati hai aur *immutable*
hai. Hissa A us usool ko **jaan-boojh kar** tor raha hai — ek dafa, haath se, is liye ke jami hui
qeemat khud ghalat hai. Ye kisi code ka raasta nahi banega, aur isi doc me darj hai taake baad me
koi isay misaal na samjhe.

---

## 10. ⚠️ 1 baje shift band karne ke baad NAYI shift MAT kholna

Owner ka pehla khayal tha: "purani shift band kar ke wapsi foran nayi khol dete hain."
**1 baje ye ghalat hoga**, aur wajah wohi clock hai jisne ye masla paida kiya.

`TenantClock::businessDateForOpening()` **Karachi ki calendar date** leta hai. Raat 1 baje Karachi
me **5 September** ho chuka hoga, is liye us waqt kholi gayi shift par **`business_date = 2026-09-05`**
jam jayegi — aur 4 September ki service ke bache-khuche bills **5 September** me chale jayenge.
Yani aaj ka masla kal dobara, sirf ek din aage.

**Theek tareeqa:**

| Waqt | Kya karna hai |
|---|---|
| ~1 baje (5 Sep raat) | Sab bills settle, tables band, **Close Branch** — chaaron shifts band. **Koi nayi shift nahi kholni.** |
| Kal dopeher (5 Sep) | Rozana ki tarah nayi shifts kholna — us waqt Karachi me 5 Sep hai, to `business_date = 2026-09-05` — **bilkul durust** |

Yehi wajah hai ke shift ki tareekh khulte waqt jamti hai: dukan raat 12 baje ke baad tak chalti
hai, aur us poori raat ka kaam **usi din** me rehna chahiye jis din shift khuli thi. Nizam theek
hai — bas shift waqt par band honi chahiye.
