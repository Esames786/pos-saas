# Kashif Food — "*** kyun nahi aa raha" (research)

**Tareekh:** 2026-09-04 · **Tenant:** kashiffood (#348) · **Branch:** 1
**Halat:** RESEARCH — prod par kuch nahi badla. Sab read-only.
**Ta'alluq:** `HIDE-AMOUNTS-1` — commit `5408ba6`, prod par merge `b68e062`, **deployed**.

---

## 1. Kya poocha gaya

> "Ye view ke andar abhi bhi `***` nahi aa raha. Humne kal ye wala kaam kiya tha — last commit me
> dekho, yahan par abhi bhi data show ho raha hai."
> (Screenshots: `/shifts/24`, `/shifts/29`, `/shifts/29/close`)

Is ke **do alag jawab** hain: ek me nizam theek chal raha hai, doosre me ek asli surakh hai.

---

## 2. Feature ka usool

`App\Support\AmountVisibility` — **do switch, dono ka chalna zaroori**:

1. branch par `hide_amounts_from_operators` **ON** ho, **aur**
2. user ke paas `tenant.shifts.view-amounts` **na** ho

### Kashif ka data (prod se)

| | Halat |
|---|---|
| `branches.hide_amounts_from_operators` | **1 (ON)** ✅ |
| `tenant.shifts.view-amounts` kis ke paas | **sirf `Owner`** ✅ |

| User | Role | Paisa dikhega? |
|---|---|---|
| **Kashif Food** | Owner | **HAAN** |
| Delivery Desk | Delivery | nahi (`*****`) |
| DTQ 2 Counter | Dine In | nahi (`*****`) |
| DTQ 3 Counter | Dine In | nahi (`*****`) |
| Floor T4 Counter | Dine In (Restricted) | nahi (`*****`) |
| delivery_kf2 | Delivery | nahi (`*****`) |

---

## 3. Jawab (a) — aap ki screen par `***` na aana DURUST hai

Screenshots me sidebar par Operations, Catalog, Inventory, Purchasing, Finance… poora admin menu
hai — yani ye **Owner** ka account hai. Owner ke paas `tenant.shifts.view-amounts` hai, is liye
usay **asli figures dikhne hi chahiyen**. Feature ka maqsad counter staff se chhupana hai, malik se
nahi.

**Tasdeeq ka aasan tareeqa:** kisi counter account (jaise `counter2_kf`) se `/shifts/29/close`
kholein — wahan `*****` aayega.

---

## 4. Jawab (b) — magar ek ASLI surakh hai

`AmountVisibility` sirf **teen** jaghon par lagta hai (khud uske docblock me likha hai):
`closeForm`, `closeBranchForm`, aur dashboard tiles.

**Shift ka detail safha aur shifts ki list dono me masking hai hi nahi.**

| Safha | Route | Masking? | Kya khula hua hai |
|---|---|---|---|
| Close Shift | `tenant.shifts.close-form` | ✅ | — |
| Close Branch | `tenant.shifts.close-branch-form` | ✅ | — |
| Dashboard tiles | — | ✅ | — |
| **Shift detail** | `tenant.shifts.show` | ❌ **NAHI** | Opening Cash · Total Sales · Total Cash · Total Card · **Expected Cash** · Counted Cash · Cash Variance |
| **Shifts list** | `tenant.shifts.index` | ❌ **NAHI** | Opening Cash |

### Aur wahan pahunch kis kis ki hai

| User | `shifts.index` | `shifts.show` |
|---|---|---|
| Kashif Food (Owner) | HAAN | HAAN |
| **Delivery Desk** | **HAAN** | **HAAN** |
| **DTQ 2 Counter** | **HAAN** | **HAAN** |
| **DTQ 3 Counter** | **HAAN** | **HAAN** |
| **delivery_kf2** | **HAAN** | **HAAN** |
| Floor T4 Counter | nahi | nahi |

**Yani paanch me se chaar operators** Close Shift par `*****` dekhte hain, magar `/shifts/29`
khol kar **wohi figures poore ke poore** parh sakte hain — Expected Cash samet. Feature us raaste
par be-asar hai.

> Ye kaam khud-ba-khud nahi hua: `Floor T4 Counter` ko shifts ka access hai hi nahi, baqi chaar ko
> hai. Jis ne bhi ye permissions di thin, us waqt masking ka sawal hi nahi tha.

---

## 5. Kya karna chahiye

**Sab se saaf hal:** `show()` aur `index()` me wohi `AmountVisibility` lagana jo baqi teen jagah
lagta hai, aur blade me `$maySeeAmounts ? number_format(...) : '*****'` — bilkul `close.blade.php`
jaisa. Chhota kaam hai, magar **code** ka hai: test + deploy.

Do cheezein saath karni chahiyen:

1. `shifts/show.blade.php` — saaton money khaane (Opening Cash, Total Sales, Total Cash, Total
   Card, Expected Cash, Counted Cash, Cash Variance)
2. `shifts/index.blade.php` — Opening Cash ka column

**Guard bhi zaroori hai** — asli HTTP par, ek counter user ban kar: `/shifts/{id}` me `*****`
milna chahiye aur koi figure nahi. Warna ye chauthi jagah bhi chup-chaap drift kar jayegi, jaise
report ka preview aur printer kar gaye the.

### Ek chhota faisla owner ka

Kya counter staff ko `/shifts` aur `/shifts/{id}` tak pahunch honi bhi chahiye? Agar nahi, to
permission hata dena is se bhi mazboot hal hai (aur ye data ka kaam hai, code ka nahi). Magar
masking phir bhi lagni chahiye — kisi din wo permission kisi ko wapas mil gayi to surakh dobara
khul jayega.

---

## 6. Jo is tehqeeq me ghalat sabit hua

Pehli nazar me lagta tha ke shayad feature deploy hi nahi hua. **Aisa nahi hai** — `b68e062` prod
par maujood hai aur `app/Support/AmountVisibility.php` server par para hai. Nizam kaam kar raha
hai; sirf uska daira teen screens tak mehdood hai, aur chauthi screen (jo ye figures utne hi saaf
dikhati hai) us daire se bahar reh gayi.
