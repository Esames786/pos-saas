# REMINDER-DEAL-NAME-ONLY-1 — reminder par deal ka sirf naam, aur naam ka font chhota

**Tareekh:** 2026-09-28
**Halat:** RESEARCH + PLAN — **koi code nahi badla, prod par kuch nahi chhua**
**Document:** sirf **REMINDER** (KOT aur receipt ko haath nahi lagta)

**Maalik ki do baatein:**
1. Reminder par **deal/combo ki tafseel na chhape — sirf deal ka naam**
2. Item ke naam ka font **chhota ho — utna hi jitna abhi `1 x ARABIC RICE` wali satarein hain**
   (maalik ne saaf kiya: wo satar sirf **misaal** thi, size ka namoona)

---

## 1. Pehli baat jo screenshot se nikli: ye parchiyan Tawakal ki hain hi nahi

Shikayat "Tawakal ki dono branch" ke sath aayi thi, magar parchi par `CASHIER: Floor T4 Counter`
aur `CASHIER: DTQ 3 Counter` likha hai — **ye Kashif Food ke counters hain.**

Reminder waqai kaun chhapta hai (7 din):

| tenant | reminder parchiyan |
|---|---|
| **kashiffood** | **4,267** |
| khatribiryani | 82 |
| **tawakalkashif** | **0** |
| kashifkitchen | (catering; font 12, pehle se `w1 h1`) |

**Tawakal reminder chhapta hi nahi.** Is tabdeeli ka asli asar **Kashif Food** par hai, aur
halka sa **Khatri** par. Ye baat pehle se jaan lena behtar hai — warna hum wahan badlaav karte
jahan shikayat aayi hi nahi thi, aur wahan na karte jahan asal me chhapti hai.

---

## 2. Dusri baat: font wala hissa **bina code** ho jata hai

`buildReminder()` me do scale hain:

```php
$rowBig = $this->scaleFor($layout['item_font_size'] ?? $layout['font_size']);   // item ka naam
$sub    = ['w' => 1, 'h' => $rowBig['h']];                                      // sub-satarein
```

Farq sirf **chaurai** ka hai — `$sub` ki width hamesha `1` hai.

Aur `scaleFor()` ki bands:

| px | scale |
|---|---|
| ≤ 14 | `w1 h1` |
| 15–17 | **`w1 h2`** |
| 18–20 | `w2 h2` ← **dugni chaurai** |
| 21+ | `w3 h3` |

Abhi chaaron tenants par: `font_size = 18`, `item_font_size = **NULL**` → item row `w2 h2`.

> **`item_font_size = 17` karte hi** item row `w1 h2` ho jata hai — aur `$sub` bhi `w1 h2` hai.
> **Bilkul wohi size.** Yani maalik ki dusri farmaish ek setting se poori hoti hai:
> **koi code nahi, koi deploy nahi, per-branch, aur ek second me wapas.**

Ye pehle hi qadam par kar ke dikha dena chahiye — mumkin hai maalik isi par raazi ho jaye aur
poore code change ki zaroorat hi na pade.

---

## 3. Jo waqai code maangta hai: deal ke components chhupana

`buildReminder()` me (≈ line 331-339):

```php
foreach ($topLevel as $line) {
    $out .= $this->reminderLine($line, ...);                              // deal ka naam
    foreach ($lines->where('parent_line_id', $line['line_id']) as $component) {
        $out .= $this->reminderLine($component, ..., $sub, '  - ');       // ← ye satarein
        foreach (($component['modifiers'] ?? []) as $modifier) { ... }    // ← in ke modifiers
        if (!empty($component['kitchen_note'])) { ... }                   // ← in ke notes
    }
    ...
}
```

Yani `1 CHULLU KEBAB BEEF` ke neeche `- 1 x ARABIC RICE`, `- 2 x BEEF SEEKH KABAB` waghera.

**Maalik chahte hain ye poora `foreach` band ho** — sirf deal ka naam rahe.

Ye theek wohi cheez hai jo **receipt par pehle se hoti hai** (`COMBO-RECEIPT-NAME-ONLY`,
`f191680`): receipt deal ka naam deta hai, components nahi. Reminder us ke saath mil jayega.
**KOT par components qaayam rahenge** — kitchen ko wo chahiye hi, aur maalik ne KOT ka zikr
nahi kiya.

### ⚠️ Ek nateeja jo saaf bata dena chahiye

Component ke saath us ke **modifiers aur kitchen note bhi** chale jayenge (wo isi loop ke andar
hain). Yani agar kisi deal ke *component* par koi note likha ho — jaise "BBQ TOMATO: bina mirch"
— to wo reminder par nazar nahi aayega.

**Top-level item ke modifiers aur note qaayam rahenge** (wo alag loop me hain, line 340-347).
Maalik ne screenshot me sirf deal ke components par nishan lagaya tha, is liye baaqi sab
chhoRa ja raha hai — magar ye faisla un ke saamne rakhna zaroori hai.

---

## 4. Global ya per-branch?

Maalik ne kaha: *"jo bhi tenant reminder use kar raha hai"* — yani sab ke liye.

Phir bhi ye teen chalti hui businesses ki parchi hai. Do raaste:

| | |
|---|---|
| **(a) Global** — har tenant ke reminder se components ghayab | maalik ne yehi maanga. Chhota diff. Magar Khatri (82 parchi/hafta) ki parchi bhi badlegi, aur wahan se koi shikayat nahi aayi thi. |
| **(b) Naya per-branch switch** — jaise `show_heading_stars` | zyada mehfooz; jo chahe band kare. Magar ek aur toggle, aur maalik ne saaf kaha "sab ke liye". |

**Meri sifarish: (a) global** — kyunki maalik ne khud sab ke liye kaha, ye receipt ke bartaao
se hum-ahang karta hai (yani do documents ka ikhtilaf khatam hota hai), aur nateeja sirf ye hai
ke parchi **chhoti aur saaf** hoti hai — koi maloomat *ghalat* nahi hoti.

⚠️ Magar **Khatri ko bataye baghair nahi** — un ki 82 parchi/hafta bhi badlengi.

---

## 5. Guards (jo likhne hain)

1. **Reminder par deal ka sirf naam** — combo header chhape, us ke components **na** chhapen.
2. **Top-level ke modifiers aur note qaayam** — deal ke saath wo bhi na ur jayen (asal khatra:
   `foreach` galat jagah band karna).
3. **KOT be-harkat** — usi sale ka KOT ab bhi har component chhape. Ye sab se ahem guard hai:
   kitchen ka parcha chhota karna khana rok deta hai.
4. **Receipt be-harkat** — wo pehle se naam-only hai, waisa hi rahe.
5. **Cancelled lines wala hissa** — `CANCELLED:` section bhi isi `reminderLine()` se banta hai;
   wahan bhi combo components na chhapen, aur baaqi satarein qaayam rahen.
6. **Font:** `item_font_size = 17` par item row aur sub-row **ek hi scale** par aayen
   (`w1 h2`) — ye sirf setting ka pehra hai, code ka nahi.

---

## 6. Risk

| khatra | haqeeqat |
|---|---|
| kitchen ko kam maloomat mile | **KOT bilkul nahi chhua** — guard 3 us par pehra deta hai. Reminder counter ka recap hai, kitchen ka parcha nahi. |
| component ka note gum ho | sach hai, §3 me darj — maalik ke saamne rakha gaya hai |
| Khatri ki parchi bhi badle | sach hai, §4 me darj |
| Tawakal | reminder chhapta hi nahi (0) — koi asar nahi |
| paisa / stock | ye mehez chhapai ka matn hai — koi journal, koi stock, koi order field nahi |
| blade 500 | reminder ka blade bhi chhuega to compile + generated PHP lint (`d746abe` ka sabaq) |

**Palatna:** font — setting wapas `NULL`. Components — commit revert; koi migration nahi.

---

## 7. Tarteeb

1. **Pehle sirf setting** — Kashif Food (aur Khatri, agar wo chahen) ke reminder par
   `item_font_size = 17`. Koi deploy nahi. Parchi nikaal kar dikha dein.
2. Us ke baad **components wala code**, guards ke sath, phir maalik ki ijazat par deploy.

Is tarteeb ka faida: aadhi farmaish **aaj hi**, bina kisi khatre ke — aur baaqi aadhi par
faisla asli parchi dekh kar hoga, andaze par nahi.
