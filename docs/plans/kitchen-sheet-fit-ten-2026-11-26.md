# KITCHEN-SHEET-FIT-10-1 — 10 khane aur paer ek A5 par (26 Nov 2026)

## Malik ki baat
"Ye 10 ke baad neeche address next page pe chala jata hai … 10 item k bad address wala footer area agae."
Asli parcha (VALIMA, 10 khane) par paer — rawangi ka waqt, venue, pata — doosre safhe par gaya; staff ne
pehle safhe par haath se likha.

## Wajah (naap)
- Urdu dish ka naam 24px, line-height 1.7 → ek satar ≈ 40.8px ≈ 10.8 mm; row (padding + lakeer) ≈ 12.7 mm.
- Item khaana 42% (A5 par ≈ 55 mm). Teen lambe naam (soup, biryani masala chicken, halwa gajar …) do
  satron me toote → ≈ 23 mm har ek.
- 7 × 12.7 + 3 × 23 ≈ 159 mm; khanon ke liye jagah ≈ 160 mm → paer (≈ 12 mm) agle safhe par.

## Faisla — raasta A (malik: "teekh hai kardo")
| Cheez | Pehle | Ab |
|---|---|---|
| Urdu dish font | 24px | 24px (badla NAHI — 29 Sep ka faisla) |
| Dish ki satron ka faasla (`.item-ur` line-height) | 1.7 (`.ur` se) | **1.5** |
| Item khaana | 42% | **48%** |
| Instructions khaana | 19% | **13%** |

`.ur` (graahak ka naam waghaira) 1.7 par hi hai. Andaza: is booking par ≈ 25 mm bachat; aam ek-satar
khane ≈ 13 ek safhe par.

## Asar (system map se)
- Tables: koi nahi. Routes/controllers/services: koi nahi.
- Screens/raaste: kitchen sheet ka ek hi partial — tanha parcha, bulk kitchen sheets, Send to network
  (aur PRINT-PREPARE ki tayyar copy). Sab tenants jinke paas catering hai.
- Files: `partials/kitchen-sheet-style.blade.php`, `partials/kitchen-sheet-body.blade.php`.
- Tests: naya `CateringKitchenSheetFitTenMySqlTest` (3), `CateringKitchenSheetDensityMySqlTest` (11, docblock
  update; dompdf band 8→1 / 12→2 waisa hi pass).

## Saboot / jo abhi baqi hai
- MySQL tests hare; Blade compile + `php -l` saaf; render me comment leak 0.
- Laptop ke Chrome me **Jameel Noori Nastaleeq nahi** (Aldhabi/Urdu Typesetting par girta hai, jo patle
  hain) — wahan purana design bhi 1 safha deta hai, is liye laptop ki naap faisla-kun NAHI.
- Faisla-kun naap: KK PC ka font file laptop par la kar Chrome se purana/naya safha gin-na, ya deploy ke
  baad wohi booking ek dafa chhapna. Dekhna: (1) paer pehle safhe par, (2) do satron wale naam me ے/ں
  neeche wali satar se na takraayen. Takraayen to 1.55–1.6.

## Wapsi
Do qeematein wapas: `.item-ur` se `line-height: 1.5` hatao, chaurai 42/19. Koi data nahi badalta.

## Deploy — 26 Nov 2026 ~21:55 PKT (malik: "kardo deploy")
- Merge `dbf4c2cb` (feature `29aa84bc` + language-test pattern fix `29311563`) on production `040e91f4`; pushed,
  origin == HEAD; `bash deploy.sh` exit 0 (deploy.sh unchanged); prod HEAD `dbf4c2cb`.
- Tests before deploy: FitTen 3, Density 11, Preview 25, Language 11 (purana pattern 12 Nov se laal tha —
  `<!--printed-at-->` nishan; ab hara), VenueNameOnly 6, SendLanguage 4, RequirementsToggle 5, PrintPrepare 12 — sab hare.
- After deploy: PERMISSIONS OK (14 snapshots, lost 0, gained 0); smoke SAME 13/13 (tb_diff 0, neg 0, dept 0);
  log ERROR 10 → 10; kashifkitchen/khatribiryani login + bingoopos.com 200.
- Live render (KK release 22, Urdu, real controller): `.item-ur { font-size: 24px; font-weight: 600; line-height: 1.5; }`,
  widths 48/13, comment leak 0. PRINT-PREPARE ki purani tayyar copies ka fingerprint HTML se banta hai, is liye
  naya parcha khud nayi PDF banwata hai.
- Baqi: KK par wohi 10 khanon wali booking chhap kar photo — paer pehle safhe par? ے/ں takraate to nahi?
