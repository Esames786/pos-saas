# Quick Report ab apni branch tak — aur modal me branch ka picker

**Tareekh:** 2026-09-07
**Commit:** `6c22fc7` (worktree `feat/items-by-category`) → merge `9d5da75` → prod LIVE
**Tenant jahan pakra gaya:** `tawakalkashif` (Tawakal + The Kashif Foods)

---

## 1. Masla kaise samne aaya

Owner ne khud `counter_tb@bingoopos.com` (Tawakkal Counter) se login kiya aur POS ki
Quick Report kholi. Us parchi par **Singaporean Rice** aa raha tha.

Singaporean Rice Tawakkal Biryani ka product **nahi** hai — wo **The Kashif Foods**
(branch #1) ki category ka maal hai. Yani ek branch ka cashier doosri branch ka
kaarobar parh raha tha: uska Orders, uski qty, uska paisa.

## 2. Wajah — code me likhi hui thi

`PosQuickReportController` ke docblock me saaf lafzon me ye faisla darj tha:

> ye report jaan-boojh kar **unscoped** hai; permission hi poori hifazat hai.

Wo baat **us waqt** ghalat nahi thi. Jab ye report bani (QUICK-REPORT-SEND-1,
`2454d7c`, 27 Aug) har live tenant **ek hi branch** ka tha — Khatri, Kashif Food,
Kashif Kitchen. Ek branch wale tenant par "poora tenant" aur "meri branch" bilkul
ek hi cheez hoti hai, is liye us faisle ka koi asar nahi tha aur kisi ko dikha bhi nahi.

`tawakalkashif` pehla tenant hai jo **do branch** par chalta hai. Wahan wo faisla
foran ghalat ho gaya.

Is liye us docblock ko bhi durust karna zaroori tha, warna agla parhne wala usay
**iraada** samajh kar chhor deta — bilkul jaise maine pehle chhora tha.

## 3. Do darwaze the — dono band karne parte the

### Darwaza 1: report khud poora tenant parhti thi

`context()` filters bana kar `SalesReportEngine` ko deta hai. Us me `branch_ids`
**bheja hi nahi jaata tha**, to engine har branch ka data samet leta tha.

Ab branch `UserDataScope::branchIds()` se aati hai. Us helper ka usool poore system
me wohi ek hai (`canOperateTerminal` bhi isi shakl par chalta hai):

> **KHALI assignment = koi rukawat nahi.**

Yehi wajah hai ke ye tabdeeli purane tenants ko **chhoo bhi nahi** sakti — jis user
ke paas koi branch assign nahi, uske liye jawab pehle jaisa hi rehta hai. Aur jinke
paas assign hai, unki assign list poori active branch list ke barabar hai.

### Darwaza 2: modal se ghair-branch ki id bhej kar hadd paar karna

Naya picker `branch_ids` bhejta hai — yani browser ek qeemat bhej raha hai. Sirf
front-end par list chhoti kar dena hifazat **nahi** hoti; kisi bhi client se ek
doosri branch ki id bheji ja sakti hi thi.

Is liye backend par `allowedBranchIds()`:

```php
private function allowedBranchIds(Request $request): array
{
    $allowed = app(UserDataScope::class)->branchIds(auth('tenant')->user())
        ?: Branch::where('status', 'active')->pluck('id')->all();

    $asked  = array_values(array_filter(array_map('intval', (array) $request->input('branch_ids', []))));
    $picked = array_values(array_intersect($asked, $allowed));

    return $picked ?: $allowed;
}
```

Ghaur ki baat: maanga hua chunaav **KAAT-A** jaata hai (intersect), **rad nahi** kiya
jaata. 422 dena aasan tha, magar galat: cashier ne kuch ghalat nahi kiya — usay apni
branch ka sahi jawab milna chahiye, error nahi. Aur `?: $allowed` ka matlab: kuch na
maanga (ya sirf ghair-branch maanga) to apni sab branchein.

## 4. Modal ka picker

Owner ne isi ke saath maanga:

> "quck report k andar he defualt branch selection option mojod ho agar user ko ak
> assign hai to sird howe selected ho"

`POSController` ab `quickReportBranches` deta hai — **sirf** us user ki branchein,
aur wo bhi tab jab uske paas `tenant.pos.quick-report-send` ho.

Blade ka usool:

- ek se **zyada** branch → "All my branches" ka option sab se oopar
- **ek hi** branch → wo ek option, `selected`, aur "All my branches" **bilkul nahi**
  — chunne ko kuch hai hi nahi, wo option sirf shak paida karta

Date ka column 5 se 4 hua taake picker ki jagah bane; koi naya row/section nahi.

⚠️ `branch_ids` **do** jagah serialise hota hai — `toForm()` (email/network POST) aur
`toQuery()` (print ka GET). Pehli koshish me maine sirf ek me lagaya tha; guard ne
pakra. Aage koi naya field aaye to dono dekhna.

## 5. Kis kis par asar para — ginn kar dekha

Deploy se **pehle** chaaron live tenants par jawab ginn kar dekha, aur baad me prod
par phir se:

| tenant | active branches | user | assigned | report scope | asar |
|---|---|---|---|---|---|
| kashiffood | [1] | owner_kf | [1] | [1] | **koi nahi** |
| khatribiryani | [1] | owner_kb | [1] | [1] | **koi nahi** |
| kashifkitchen | [1] | owner | [1] | [1] | **koi nahi** |
| tawakalkashif | [1,2] | owner_tk | [1,2] | [1,2] | **koi nahi** |
| tawakalkashif | [1,2] | counter_kf | [1] | **[1]** | tang hui |
| tawakalkashif | [1,2] | counter_tb | [2] | **[2]** | tang hui |

Sirf wo do counters tang hote hain — jo maqsad hi tha.

⚠️ Ginne ka tareeqa: ek hi process me chaar tenant switch karke `$user->can()`
poochna **jhoot** bolta hai — Spatie ka per-request `array` cache pehle tenant ki
permission table par jam jaata hai, to baad ke tenants ke counter roles "nahi hai"
dikhte hain. Pehli bar mujhe yehi hua. `branchIds()` cache nahi parhta, is liye branch
wala hissa sahi tha. Har tenant ke `can()` ke liye **alag process** chalao.

## 6. Live tasdeeq (prod, asli HTTP request, asli login)

`/pos/quick-report/print` par teen alag session se:

| | counter_tb | counter_kf | jama | owner_tk |
|---|---|---|---|---|
| Orders | 34 | 51 | **85** | 85 ✓ |
| Sold Qty | 67 | 131 | **198** | 198 ✓ |
| Items Sold | 12,300 | 42,030 | **54,330** | 54,330 ✓ |
| Delivery Charge | 0 | 1,100 | **1,100** | 1,100 ✓ |
| Net Sales | 12,300 | 43,130 | **55,430** | 55,430 ✓ |
| Cash Collected | 12,300 | 33,940 | **46,240** | 46,240 ✓ |

Do counters ka jama owner ke jawab ke **barabar** hai — yani kuch gum nahi hua aur
kuch do baar nahi gina. Scoping ne data kaata, todha nahi.

`Singaporean Rice` — counter_tb ki parchi par **0 baar**, owner ki parchi par mojood.
Yani asal shikayat khatam, aur owner ka poora nazara qaayam.

Picker jaisa maanga tha:

```
counter_tb : Tawakkal Biryani (selected)          — ek option, "All" nahi
counter_kf : The Kashif Foods (selected)          — ek option, "All" nahi
owner_tk   : All my branches / Tawakkal / Kashif  — teen option
khatri     : ek option    |    kashiffood : ek option
```

Purane tenants ki report bhi asli safhe se chala kar dekhi: Khatri 95 orders /
81,130, Kashif Food 100 / 148,210 — dono 200, dono ek-option picker ke saath.

## 7. Guards

Chaar naye guard, sab asli controller par (query dobara likh kar nahi):

- apni branch se bahar ki category parchi par aani NAHI chahiye
- maangi hui ghair-branch ka data nahi milna chahiye (clamp, 422 nahi)
- jis ke paas koi branch assign nahi, uska jawab pehle jaisa
- modal me picker ka usool (ek branch = ek option, "All" nahi)

**RED hote dekha:** scoping wala hissa `git stash` kiya to pehle do guard fauran
toot gaye (`Tests: 2, Failures: 2`). Yani guard sach me kaat-ta hai.

Report / Quick / POS / dashboard filter: **377/377**.

## 8. Jo NAHI badla

- Report Center — bilkul achhoot. Ye tabdeeli sirf `PosQuickReportController` me hai.
- `SalesReportEngine::POPULATION` ki qeemat — us se pehle bhi nahi cherhi thi.
- Design — na koi naya section, na koi naya row; sirf modal ki ek satar me picker.
- Koi migration, koi naya route, koi nayi permission. Is liye role-grant ka wo purana
  jaal (deploy sirf Owner ko deta hai) is bar nahi lagta.

## 9. Yaad rakhne wali baat

Ye kharabi "code galat likha gaya" nahi thi — **ek durust faisla jo doosri branch
aane par galat ho gaya**. Jitne bhi jagah ye jumla likha ho ke "ye jaan-boojh kar
unscoped hai kyunke tenant ek branch ka hai", wo sab `tawakalkashif` jaise
multi-branch tenant par phir se parhne layak hain.
