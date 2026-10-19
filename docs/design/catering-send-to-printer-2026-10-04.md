# Catering documents seedha printer par (A4 / A5)

Date: 2026-10-04 (Asia/Karachi)
Kis ke liye: malik, aur wo jo ye code aage sambhalega
Haalat: **marhala 1 zer-e-tameer** (safai ho chuki — neeche "Jo ho chuka")

---

## Asal masla

Malik:

> "Client confuse ho raha hai, bar bar A4 / A5 select karna parta hai."

Shikayat "network printing" ki nahi hai. Shikayat ye hai ke **har print par kagaz
ka size haath se chunna parta hai.**

Hota kyun hai: Chrome har printer ke liye **aakhri manual setting yaad rakhta
hai**, aur wo document ki apni marzi par bhaari parti hai. Ek baar kisi ne us
printer par A4 chun liya, to agli baar kitchen sheet bhi A4 par khulegi — chahe
document saaf keh raha ho `@page { size: A5 portrait }`.

Kashif Kitchen me teen kaghaz chhapte hain, aur un ke size alag hain:

| Document | Kagaz | Kahan tay hai |
| --- | --- | --- |
| Kitchen Sheet | **A5** | `catering_settings.kitchen_sheet_paper` = `a5_portrait` |
| Quotation | **A4** | `catering_settings.quotation_paper` = `a4_portrait` |
| Address Sheet | **A4** | code me tay (koi setting nahi) |

Ek hi printer par dono kaam karne se har baar palatna parta hai.

### Ek sasta hal mojood hai, aur malik ne use rad kiya

Dono printers ko baant dena (ek A5 par, doosra A4 par, Windows me default set
kar ke) isi shikayat ko bina kisi code ke khatam kar deta. Malik ne kaha ke
nahi, **agent wala hal chahiye** — kyunke wo dialog hi nahi chahte.

Ye yahan is liye likha hai ke chhe mahine baad ye sawal na uthe ke "sasta
raasta dekha tha ya nahi" — dekha tha, aur faisla malik ka hai.

---

## Jo ho chuka (4 Oct, prod par)

Kashif Kitchen me POS ka saaz-o-saman para hua tha jo kabhi istemaal hi nahi
hua. Malik ki ijazat se hataya gaya — har qadam shartein jaanch kar, aur
mitne se pehle JSON snapshot `/tmp` me:

| Cheez | Pehle | Ab | Kyun |
| --- | --- | --- | --- |
| Thermal printers | 3 (`192.168.1.101-103`) | **0** | asli hardware A4 laser hai, aur subnet bhi alag (`192.168.100.x`). 0 job kabhi |
| Catering printer mappings | 8 | **0** | printer hi nahi the |
| POS terminals | 4 (Counter 1-4) | **0** | 0 shift kabhi, 0 POS payment, `sales` table mojood hi nahi — jabke 161 bookings |

Catering ke poore code me `terminal_id`, `ShiftService`, `requireOpenShift` ka
**ek bhi zikr nahi** — is liye terminals ka hatna kisi catering kaam ko nahi
chhoota.

---

## Faisle (malik ke, 4 Oct)

| Sawal | Faisla | Wajah |
| --- | --- | --- |
| Alag agent banayen? | **Nahi — wohi agent barhayenge** | alag agent ka matlab pairing/polling/retry/lane-lock ka poora code DOBARA. Do copies ka anjam isi project me do baar dekh chuke hain (punch grid ka Own, status ka badge): ek jagah badalta hai, doosri reh jati hai |
| Release Production par kagaz khud nikle? | **Nahi — operator dabayega** | khud-ba-khud me ghalat release par bhi kagaz nikal jata hai |
| Mapping wali screen mita dein? | **Nahi — khud chhup jaye** | wo har tenant ka code hai; mita dene se kal koi aur tenant ka raasta band ho jata. Jab koi network/thermal printer na ho to menu se gayab — wohi tareeqa jo `Queue to printer` dropdown pehle se istemaal karta hai |

**Agent ki salahiyat ka pehra.** Agent apni salahiyat batayega ("main document
print kar sakta hoon") aur server **sirf usi** agent ko document job dega.
Warna ek purana agent kahin laga diya jaye to wo aisi job utha lega jo kar hi
nahi sakta — aur kagaz kabhi nahi niklega, bina kisi ko pata chale.

**Naye `windows` printers mapping wali screen par NAHI aayenge.** Mapping
thermal/station ka kaam hai; document printing ka us se taluq nahi. Warna wohi
ghalat-fehmi dobara ban jayegi jo abhi saaf ki hai.

---

## Browser ki hadd — jo badli nahi ja sakti

> Koi website kisi printer ko khud nahi chun sakti, na kagaz ka size set kar
> sakti hai, na chup-chaap chhap sakti hai. `window.print()` **hamesha** dialog
> kholta hai.

Ye security ki hadd hai, koi kami nahi. Is liye "dabao aur kagaz nikal aaye"
sirf **agent** se mumkin hai — wo office ke PC par chalta hai, website nahi.

---

## Jo pehle se mojood hai

- `print_jobs` + LAN agent ka poora transport. `sendToNetworkPrinter(ip, port,
  payload)` TCP khol kar **kacche bytes** likhta hai — zabaan se be-niyaz.
- `PrintJobFactory` me `logical_key` idempotency: ek job do baar nahi banti.
- `CateringDocumentPrintService` — quotation/invoice ko printer par bhejne wali
  service pehle se hai (abhi sirf ESC/POS thermal, aur Urdu ko **mana** karti hai).
- UI ka dropdown bhi mojood: `tenant/catering/partials/document-print.blade.php`,
  aur printer na hone par wo khud ko disable kar leta hai.
- `print_jobs.document_type` **pehle se `VARCHAR(30)`** hai (08-03 ki migration
  me chaura hua) — naya type daalne ke liye migration **nahi** chahiye.
- `print_jobs.payload` JSON hai — link aur kagaz ka size wahan ja sakte hain.

---

## Jo banana hai — chaar cheezein

### 1. Printer ki nayi qisam: `windows`

Abhi `printers.printer_type` ENUM hai: `network | usb | browser`. Aur
`paper_size` ENUM: `58mm | 80mm | A4`.

Chahiye: **`windows`** aur **`A5`**, aur ek khaana jis me Windows ka printer ka
naam likha ho (IP:port ki jagah).

**`windows` kyun, seedha port 9100 kyun nahi:** office ka HP M127fn
**host-based** printer hai — wo rendering driver se karwata hai. Us ke 9100 par
kaccha PCL/PostScript bhejna bharosemand nahi. Windows ke spooler se sab theek
chhapta hai, aur A4/A5 ka chunao driver khud sambhal leta hai. (P2055dn PS/PCL
samajh leta, magar do printers ke liye do alag raaste rakhna bemani hai.)

### 2. Job ka naya payload

ESC/POS bytes ki jagah JSON: **document ka link + printer ka naam + kagaz**.

### 3. Render AGENT ke Chrome se — server par nahi

Ye is poore kaam ka sab se ahem faisla hai, aur wajah code me pehle se likhi hai:

> *"dompdf has no complex-script shaping engine, so Urdu comes…"*
> — `CateringDocumentController`

Server par PDF banayi to **Urdu toot jayegi**. Agent ke PC par Chrome headless
(`--print-to-pdf`) se banegi to output **bilkul wohi** hoga jo aaj screen par
dikhta hai — Urdu samet, aur PARTY/OWN ka kala badge bhi (us ke liye
`--print-background` lazmi hai, warna wo safed chhapega).

Yani renderer wohi rahega jo aaj durust hai. Koi naya renderer daakhil nahi
kiya ja raha.

### 4. Kagaz APP tay karegi, operator nahi

**Yehi asal shikayat ka hal hai.** Job ke saath kagaz ka size jata hai —
kitchen sheet par A5, quotation aur address sheet par A4 — aur agent usi par
chhapta hai. Operator sirf **printer** chunta hai; A4/A5 kabhi nahi.

---

## Teen marhale

| | Kaam | Kaun |
| --- | --- | --- |
| **1** | Server: printer ki qisam + kagaz, job type, queue service, teen documents par button, tests | main |
| **2** | Agent: naya job type — Chrome render + Windows print; installer dobara banana | code main; `.exe` banana aur aazmana Windows par |
| **3** | Office PC par install + pairing + dono printers add + asli print | malik |

Marhala 1 ke baad sab kuch screen par nazar aane lagega — printer add ho jayega,
button aa jayega, job qatar me banegi — bas chhapna marhala 3 par hoga.

---

## Restaurant par koi asar nahi — teen wajah

1. **Alag database.** Printers, agents, jobs, mappings sab per-tenant hain.
   Kashif Kitchen ki DB alag hai; wahan kuch karne se `kashiffood`,
   `khatribiryani`, `tawakalkashif` tak pahunch hi nahi.
2. **Agent khud update nahi hota.** Screen par "Latest version 2.5.0 … previous
   builds 2.4.0, 2.3.1" likha hai aur installer **haath se** chalana parta hai.
   Restaurant ke agents apni version par rahenge.
3. **Code additive hoga.** Naya `printer_type`, naya `document_type`, naya
   payload. Purana ESC/POS raasta **byte ke byte** waisa hi — us par pehre
   pehle se mojood hain aur wo hare rahenge.

---

## Khatre, jo chhupaye nahi ja rahe

- **Agent ke PC par Chrome lazmi hai.** Na ho to kuch nahi chhapega. (Office PC
  par hai — usi se ye screenshots liye gaye.)
- **PC ka ON rehna shart hai.** Abhi kagaz tab nikalta hai jab koi dabata hai;
  agent ke baad kagaz tab nikalta hai jab PC chal raha ho. Ye ek nayi nazuk
  cheez hai jo pehle nahi thi.
- **Chrome ka version badalne se chhapai badal sakti hai.** Aaj bhi yehi khatra
  hai (renderer wahi hai), magar tab ye ek PC par jam jayega.
- **Agent ka naya code restaurant ke agent ka bhi code hai.** Install na karen
  to unhe kuch nahi hota — magar jis din koi restaurant par naya installer
  chalayega, naya code wahan bhi pahunchega. Is liye purana raasta chhoona
  mana hai, aur uske pehre lazmi hain.

---

## Malik ko kya karna hoga (marhala 3)

1. Office ke PC par dono HP printers Windows me add hon (`Add printer` → network)
2. `Printing › Print Agents` → **Create Agent** → 6-digit code
3. Wahi PC par `BingooPrintAgent-Setup.exe` chala kar code daalna
4. `Printing › Printers` me dono printers add karna — qisam **Windows**, aur
   Windows wala naam bilkul waisa hi jaisa Windows me likha hai
5. Ek test print

---

## Ek baat jo abhi tay nahi

Kya kitchen sheet **khud-ba-khud** nikle jab Release Production dabe — ya
operator har baar "Send to printer" dabaye? Pehla zyada aaram deta hai, magar
ghalat release par kagaz bhi khud nikal jata hai. Abhi tajweez **dabane wali**
hai; khud-ba-khud wala agle qadam par chhora ja raha hai.
