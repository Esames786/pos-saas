# Release ke baad quotation badalna

Date: 2026-10-01 (Asia/Karachi)
Kis ke liye: malik, aur wo jo ye code aage sambhalega
Haalat: bana hua, test green, deploy baqi

---

## Pehle wo masla jo samne aaya

Malik ne booking `EV-20261001-0120` kholi aur poochha:

> "Main is point se quotation edit kyun nahi kar sakta, ya naya revision kyun
> nahi bana sakta?"

Wo booking kahin ja hi nahi sakti thi. **Teenon darwaze band the:**

| Kya karna chaaha | System ne kya kaha |
| --- | --- |
| Edit | "booking closed to commercial change" |
| Revise | "estimate is still a draft — edit it directly" |
| Invoice | "drafts cannot be invoiced" |

## Ye haalat bani kaise

Record se waqt ba waqt:

```
11:57:33   Q1 bheji gayi
12:14:48   production RELEASE hui       →  booking ka status = released
12:16:55   kisi ne REVISE daba diya     →  Q1 superseded, Q2 draft
```

Revise **release ke baad** chala.

Aur yahan asal kharabi thi: `revise()` sirf **quotation** ka status dekhta tha
(draft hai? superseded hai?) — **booking** ka kabhi nahi. Is liye wo chal gaya,
purani quotation ko superseded kar diya, aur ek naya draft bana diya.

Us ke baad:

- Booking `released` thi → **Edit** band, kyunke release ke baad sauda jam
  jata tha
- Quotation ab `draft` thi → **Revise** band, kyunke revise ka maqsad hi
  ghair-draft version ko aage barhana hai
- Mojooda quotation draft thi → **Invoice** band

**Har pehra apni jagah durust tha.** Mil kar unhon ne ek aisa kamra bana diya
jis ka darwaza nahi tha.

---

## Malik ka faisla

> Release ke baad bhi edit ho sake.

Wajah waajib hai: graahak aksar **usi din** item barha deta hai, aur us waqt
tak parcha nikal chuka hota hai. Purana qaida us haqeeqat se mel nahi khata
tha.

## Is faisle ki ek qeemat hai, aur wo chhupayi nahi ja sakti

Jo kaghaz bawarchi-khane ki deewar par laga hua hai, wo **purani** quotation ka
hai. Agar sauda badal jaye aur kaghaz wahi rahe:

> **bawarchi 10 KG pakayega, aur bill 12 KG ka banega** — aur kisi ko pata bhi
> nahi chalega.

Ye poore kaam ka sab se bara khatra hai. Is liye tabdeeli **nazar aati hai**
(neeche §4).

---

## Ab kya badla — paanch cheezein

### 1. Nayi tareef: "sauda abhi badal sakta hai?"

Pehle ek hi sawal tha — `isOpen()` — aur wo do alag kaam kar raha tha.

Ab do alag sawal hain:

| Sawal | Kya shaamil hai | Kaun poochhta hai |
| --- | --- | --- |
| `isOpen()` — **booking chal rahi hai?** | inquiry, draft, quoted, confirmed | **calendar** ("overdue", "needs attention") |
| `isCommerciallyOpen()` — **sauda badal sakta hai?** | upar wale **+ released** | quotation ka editor, revise, invoice ki hadd |

**`isOpen()` ko chaura karna ghalat hota.** Us me `released` daal dene se har
nikli hui booking calendar par "tawajjo chahiye" dikhane lagti — ek screen ka
jawab badal kar doosri screen ko ghalat kar dena.

### 2. `revise()` me wo pehra jo tha hi nahi

Ab booking ki haalat **pehle** dekhi jati hai. Band booking par Revise chal hi
nahi sakta, is liye wo band gali dobara ban nahi sakti.

### 3. Apni ijazat

Nayi permission: **`tenant.catering.estimates.edit-after-release`**

- **Owner ko milti hai**, baqi kisi ko nahi
- Har wo shaks jo quotation badal sakta hai, zaroori nahi ke **release ke baad**
  badalne ka bhi mujaz ho

Do baatein jo yahan ahem thin:

**Jaanch EK jagah hai**, chhe controllers me nahi. Quotation chhe raaston se
badalti hai — edit, reprice, revise, restore-version, revert, line update — aur
chhe jagah ek hi jaanch likhne ka matlab hai ke ek na ek din koi jagah bhool
jayegi. **Yehi is poore masle ki shakal thi.**

**Permission migration me di gayi hai, aur ye zaroori tha.** `deploy.sh` Owner
ka grant master ke `route_catalogs` se banata hai — yani sirf un permissions ka
jin ke peeche koi route ho. Ye permission kisi route ki nahi, is liye deploy
use **dekh hi nahi sakta**. Naye tenant ke liye provisioner ki list me bhi daali
gayi hai. (Ye sabaq pehle se code me likha hua tha — ek purani migration isi
ghalti ko theek karne ke liye likhni pari thi.)

### 4. Farq nazar aata hai — yehi is ijazat ki shart hai

**Booking ki screen par**, Production ke sar par:

> ⚠ quotation revised after release

**Aur parche par khud:**

> **یہ پرچہ نکلنے کے بعد سودا بدلا ہے** — موجودہ کوٹیشن دیکھ لیں

Parche par likhna is liye zaroori hai ke **bawarchi screen nahi dekhta** — wo
deewar par laga kaghaz dekhta hai. Agar kaghaz khud na bole, to koi nahi
bolega.

Ginti version se nahi hoti, **release ke apne `catering_estimate_id`** se — yani
us quotation se jo us parche ne *waqai* istemaal ki thi.

### 5. Jo nahi badla

| Cheez | Haalat |
| --- | --- |
| **Invoice** | Phir bhi aakhri hadd. Us ke baad kuch nahi hilta. |
| `completed` / `closed` / `cancelled` | Band hi hain. Sirf `released` khola gaya. |
| **Cancel booking** ka button | Purane qaide par. Release ke baad booking cancel karna alag sawal hai — maal nikal chuka hota hai — aur usay chupke se kholna ghalat hota. |
| Paisa (advances, refunds, journals) | Bilkul nahi chhua. Sirf "kis cheez ka bill" badalta hai. |

---

## Us phansi hui booking ka kya hoga

`EV-20261001-0120` deploy ke saath **khud chhoot jayegi** — Edit aur Revise
dono khul jayenge. Koi data theek karne ki zarurat nahi; wo sirf band darwazon
ke peeche thi, kharab nahi hui thi.

Us ki Q2 abhi draft hai aur total wohi hai (22,500). Malik chahen to usay
badal lein, warna waise hi finalise kar ke invoice kar dein.

---

## Pehre (5 tests)

1. Released booking `isOpen()` **nahi** hai (calendar ka matlab na badle) magar
   `isCommerciallyOpen()` **hai**.
2. **Band gali dobara na bane** — release ke baad revise ho, phir draft badle,
   phir finalise ho, phir invoice bane. Teenon darwaze khule.
3. Jo release tabdeeli se **pehle** nikli, wo purani nishan-zada ho.
4. **Invoice ke baad** phir bhi kuch na hile.
5. `completed` booking ab bhi band rahe.

---

## Ek baat jo khuli hai

Ab jab release ke baad sauda badal sakta hai, to **naya parcha nikalne** ka
amal zyada aam ho jayega. Abhi har release ek naya `PR-` number banati hai aur
purani wahin rehti hai — yani record poora rehta hai, magar bawarchi-khane me
do kaghaz pahunch sakte hain.

Agar ye amal me masla bana, to agla qadam ye hoga: nayi release par purani ko
"superseded" nishan-zada karna, aur us ka parcha dobara chhapne par saaf keh
dena ke wo purani hai. Abhi ye **nahi** banaya — pehle dekhte hain ke zarurat
parti bhi hai ya nahi.
