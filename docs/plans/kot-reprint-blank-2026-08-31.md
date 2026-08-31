# KOT-REPRINT-BLANK-1 — bill badalne ke baad purana KOT khali chhapta hai

**Status:** RESEARCH — masla **local par dobara paida kar ke sabit** ho chuka. Fix abhi nahi kiya.
**Date:** 2026-08-31
**Live par aaj tak asar:** **0 waqiat** (Kashif Food + Khatri, dono par koi khali parchi nahi gayi)
**Proof:** `tests/MySql/KotSplitAndReprintWalkthroughMySqlTest.php` — chala kar dekhi ja sakti hai

---

## 1. Masla

Jo KOT chhap chuka ho, agar us ke **baad bill me kuch add/save** ho jaye, to us purane KOT ka
**reprint bilkul khali** nikalta hai — na koi item, na koi error. Kitchen ko sirf sarnama aur
khali qatar milti hai.

Reminder ko ye masla **nahi** hai.

## 2. Wajah

| | Kahan se lines uthata hai |
|---|---|
| **Reminder** | apne **frozen payload** se (`buildReminder()` — "renders exclusively from the immutable job payload") |
| **KOT** | **live database** se |

`EscPosPayloadService::kot()`:

```php
$lines = $lineIds->isNotEmpty()
    ? $sale->lines->whereIn('id', $lineIds)->values()   // payload me frozen IDs, lines LIVE
    : $sale->lines;
```

Aur held-sale save karte waqt POS **lines delete kar ke dobara banata hai** — nayi id ke saath.
To payload ke purane `line_ids` kisi mojooda line se match nahi karte, `whereIn` khali collection
deta hai, aur ticket khali chhap jata hai. **Fallback bhi nahi chalta** — wo sirf tab hai jab
`line_ids` bilkul khali ho, na ke jab match na kare.

Sirf `eventType === 'cancel'` wala raasta `line_snapshots` istemal karta hai.

## 3. Local par dobara paida kiya

`KotSplitAndReprintWalkthroughMySqlTest` asli order `HS-20260831104329-417` ki shakal banata hai
(Deal 1 = Biryani + Chatni Roll + Drink, saath me Chicken Tikka aur Mayo Garlic Fries), 5 KOT
nikalta hai, phir bill save kar ke wahi KOT dobara chhapta hai:

```
line ids before the save : 1, 2, 3, 4, 5, 6
line ids after the save  : 7, 8, 9, 10, 11, 12

===== THE SAME TICKETS, REPRINTED =====
  KF-P-T2    COUNTER (DTQ 2)        items on the reprint: *** NOTHING ***
  KF-P-BBQ   BBQ / GRILL STATION    items on the reprint: *** NOTHING ***
  KF-P-T2    COUNTER (DTQ 2)        items on the reprint: *** NOTHING ***
  KF-P-BBQ   BBQ / GRILL STATION    items on the reprint: *** NOTHING ***
  KF-P-FF    FAST FOOD STATION      items on the reprint: *** NOTHING ***

  reminder reprint STILL carries the deal
  => 5 of 5 kitchen tickets would print BLANK.
```

**Masla asli hai, farzi nahi.**

## 4. Live par kitna phaila hua hai

31 Aug ko naapa gaya:

| | Kashif Food | Khatri |
|---|---|---|
| us din ke KOT jobs | 81 | 119 |
| **abhi reprint karein to khali** | **45** | **61** |
| **asal me khali parchi gayi?** | **0** | **0** |

Aaj tak jo bhi reprint hua, bill badalne se **pehle** hua, is liye theek chhapa. Khatra mojood hai,
nuqsan abhi tak nahi hua.

## 5. Fix (chhota hai)

Us KOT ke payload me **poore snapshots pehle se maujood hain** — product name, qty, combo_id, sab:

```json
{"line_id":3575,"combo_id":6,"quantity":1,"line_kind":"component",
 "product_name":"Rice of Khaas","product_id":206, ...}
```

`kot()` me itna karna hai: jab frozen `line_ids` kisi live line se match na karen, **snapshot se
chhaap do** — bilkul wahi tareeqa jo `cancel` wala raasta pehle se istemal karta hai.

```php
$lines = $lineIds->isNotEmpty()
    ? $sale->lines->whereIn('id', $lineIds)->values()
    : $sale->lines;

// KOT-REPRINT-BLANK-1: frozen ids ab kisi line se match nahi karte (bill save hone par POS lines
// dobara banata hai). Ticket khali bhejne se behtar hai wahi cheez chhaapna jo asal me bheji gayi thi.
if ($lines->isEmpty() && ! empty($payload['line_snapshots'])) {
    $lines = collect($payload['line_snapshots'])->map(fn ($l) => (object) $l);
}
```

### Ehtiyat
- **Sirf khali surat me** snapshot par jayein — warna reprint asal parchi se alag ho sakti hai
- Snapshot me `unit_price`/`line_total` nahi hote, magar KOT me daam chhapta hi nahi — sirf qty aur naam
- `line_quantities` payload me alag se hai, wahi qty ka asal source hona chahiye

## 6. Test plan

`KotReprintBlankMySqlTest`:
- KOT chhapa → bill save (lines dobara bane) → **reprint me wohi items aayen** jo asal me gaye thay
- **snapshot par tab hi jayein jab live lines na milen** — jab milen to live hi chalen
- deal ka naam reprint par bhi aaye (`(Deal 1 (Serve 1))`)
- cancel wala raasta jaisa hai waisa hi rahe
- **Guard sabit karna:** fix hata kar dekhna ke test laal hota hai

Regression: Kot, Print, Reminder, Combo, Lifecycle suites.

## 7. Alag baat

Reminder ka reprint theek hai kyunki wo frozen payload se banta hai — aur `REMINDER-REPRINT-SNAPSHOT-1`
(aaj live) uske table/waiter ko live padhta hai taake mez badal jane par parchi sahi jagah bheje.
Ye us se mutasir nahi hota.
