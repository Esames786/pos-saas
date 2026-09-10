# Quotation ka PDF — aur ek hadd jo chhupai nahi gayi

**Date:** 2026-09-09 · **Branch:** `feat/catering-stacked-material-row-20260909`
**Plan:** `docs/plans/kashif-punch-and-pricing-2026-09-08.md` §6

---

## 1. Kya bana

Quotation aur Final Invoice ab **file** ki soorat me bhi milte hain:

```
/catering/documents/estimate/{id}?lang=en&format=pdf
/catering/documents/final-invoice/{id}?lang=en&format=pdf
```

Screen par button `A4 Estimate · اردو · Both` ke saath **PDF** ban gaya hai.

## 2. Naya route JAAN BOOJH KAR nahi banaya

Plan me likha tha `GET .../pdf` — ek naya route. **Wo nahi banaya**, aur ye
tabdeeli soch kar ki gayi hai.

Naya route = naya permission. `deploy.sh` sirf **Owner** ko deta hai; Manager,
Delivery aur har custom role apna permission ek per-tenant seeder se lete hain
**jo deploy chalata hi nahi**. Yaani har naye route par ye khatra hai ke kisi
role ke liye button mojood ho aur dabate hi 403 aaye — ya us se bhi bura, kisi
ko wo cheez mil jaye jo nahi milni thi.

`?format=pdf` **usi route par** hai jo pehle se gated hai. Jo shakhs ye document
screen par parh sakta hai, wo ise file ki soorat me bhi parh sakta hai — ye
zahir hai. **Ek ijazat, do nahi**, aur do ke ikhtilaf ka koi mauqa nahi.

Guard is baat ko pakadta hai: koi bhi route jiske naam me `pdf` ho, mana hai.

## 3. Urdu ka PDF — inkar, koshish nahi

**dompdf Nastaliq shape nahi kar sakta.** Urdu us me alag alag haroof ban kar,
ulti simt me chhapega — aisa safha jo *chhapa hua lagta hai* aur parha nahi
ja sakta. Aur ye baat operator ko tab pata chalti jab kaghaz client ke haath me
ja chuka hota.

Is liye `lang=ur` ya `lang=both` par **PDF banta hi nahi** — ek saaf safha aata
hai jo batata hai ke kyun, aur wo raasta likhta hai jo waqai chalta hai:

> Urdu document kholein aur browser ka apna **Print → Save as PDF** istemal karein
> — wo asal font istemal karta hai.

Ye ghar ka apna usool hai, naya nahi. Thermal printing par pehle se yehi jawab
hai: *"Saying no here is the honest outcome; a page of mojibake would look like
the feature worked."*

## 4. Layout — ek hi document, do zabanein

Document apna header, do meta box aur dastkhat ki line **flexbox** se banata hai.
**dompdf me flexbox hai hi nahi** — bina kuch kiye har jodi neeche upar chipak
jati aur safha screen wale se bilkul mukhtalif dikhta.

Is liye ek **override sheet** bani: `documents/partials/pdf-overrides.blade.php`.
Wo sirf tab lagti hai jab PDF ban raha ho (`$pdf`), aur wahi layout **CSS 2.1
tables** me dobara kehti hai — wo zaban jo dompdf theek parhta hai.

**Ahem:** purane rules **hataye nahi gaye**, sirf dabaye gaye hain. Is liye
browser ka document **jaisa tha waisa hai** — jo kaghaz mahino se client ko ja
raha hai, us me ek harf ka farq nahi.

**Ek partial, do documents.** Quotation aur Final Invoice dono isi ko include
karte hain — do copies hotin to pehli hi tabdeeli par alag ho jatin.

Ek cheez aur: `margin-left: auto` (jis se totals ka khaana door wali janib jata
hai) dompdf hal nahi karta, is liye wahan asal figure likha gaya — `54%`.

## 5. Kya nahi badla

- **Content ek harf bhi nahi.** Wahi Blade, wahi hisab, wahi alfaz. PDF aur
  kaghaz do aise document nahi ban sakte jo aapas me ikhtilaf karen.
- **Koi migration nahi, koi permission nahi, koi naya route nahi.**
- **Paisa aur khaata:** PDF banane se kuch nahi likha jata — guard journals,
  stock, grand_total aur `updated_at` chaaron ginn kar sabit karta hai.
- **Bulk quotations** par asar nahi: wo `estimate-style` include karti hai,
  override sheet nahi, aur wahan `$pdf` hai hi nahi.

## 6. Guard

Naya: `tests/MySql/CateringDocumentPdfMySqlTest.php`

| test | kya sabit karta hai |
|---|---|
| asli PDF file | `%PDF-` se shuru, 5 KB se bara, `application/pdf`, naam me booking ka number |
| Urdu ka inkar | `ur` aur `both` dono par 422, PDF nahi, aur safhe par wo raasta likha ho jo chalta hai |
| screen wala document be-harkat | `format` ke baghair wahi purana HTML, override sheet ka nishan tak nahi |
| dompdf ki zaban | table-display rules mojood, **aur** purane flex rules bhi — override hai, rewrite nahi |
| na route na permission | `pdf` naam ka koi route mojood nahi; document route pehle se `route.permission` ke peeche |
| kuch nahi likhta | journals, stock, total, updated_at — chaaron waise ke waise |
