# REPORT-NETWORK-BRIDGE-1 — network par bheji report NET SALES tak nahi pahunchti thi

Date: 2026-10-28 · Tenant jahan pakra gaya: khatribiryani (delivery counter) · Status: fix bana, test hua, deploy baqi

## Masla

Delivery counter ka cashier shift band karne ke baad Report Center se Categories report "Send to
Network" karta hai. Kaghaz GRAND TOTAL par khatam ho jata tha. Browser se Thermal print karo to
neeche do lines aati thin:

    Plus Delivery Charge      7,200
    = NET SALES              59,220

## Saboot (prod, sirf parh kar)

Khatri ke network report jobs (print_jobs.raw_payload): #104394 (27 Oct, delivery printer, GRAND
TOTAL 52,020), #104408 (27 Oct, dine-in printer), #103023 (26 Oct). Teeno me "GRAND TOTAL" hai,
"Delivery Charge" aur "NET SALES" nahi. Har payload `0a0a0a 1d564200` (3 line feed + cut) par khatam:
printer ne jo mila wo poora chhapa. Cutter ya paper ka masla nahi tha.

## Wajah

Report kaghaz tak do raaston se jati hai:

| Raasta | Code |
|---|---|
| Browser Print (Thermal / A4) | `resources/views/tenant/reports/center/print.blade.php` |
| Send to Network (Report Center + POS Quick Report) | `EscPosPayloadService::buildReport()` |

Categories / Items / Items by Category ke neeche "bridge" lines (REPORT-CHARGE-BRIDGE-2 21 Aug,
BRIDGE-DEALS-1 3 Sep, CHARGE-BREAKUP-1 5 Sep) sirf blade me lagi thin. Network builder 18 Aug ka hai
aur ye fixes us tak kabhi nahi pahunche. Controller `bridge` ka data bhejta tha, builder use parhta
hi nahi tha. Is liye har fix ke baad browser theek lagta tha aur network waisa hi rehta tha.

## Fix (owner: "bs yahe last line, pora print kharab nahi hona chahiye")

- `app/Support/SalesBridge.php`: blade ke `$bridgeRows` ke usool line ba line (Plus Deals → naam wale
  charges → Plus Other Charges → = NET SALES).
- `EscPosPayloadService::buildReport()`: Categories, Items, Items by Category ke GRAND TOTAL / TOTAL
  ke closing rule ke BAAD yahi lines. Sirf tab jab caller `bridge` bheje.
- Browser print (blade) ko haath nahi lagaya.
- Report Center aur POS Quick Report dono `bridge` bhejte hain, dono ka manual print ye lines pehle se
  dikhata hai. Ab dono ka network print bhi dikhayega.

## Tests

- `tests/Unit/Printing/EscPosReportBridgeTest.php` (5):
  - 27 Oct wala din: GRAND TOTAL → Plus Delivery Charge 7,200 → = NET SALES 59,220 → cut.
  - **Sirf jori gayi lines:** naye slip se bridge lines nikalo to purana slip byte ba byte, usi
    tarteeb me (Categories, Items, dono).
  - Bridge ka data na ho to kuch nahi chhapta; charges na hon to slip byte ba byte waisa.
  - Items me "Plus Deals" charges se pehle.
- `tests/MySql/ReportNetworkBridgeMySqlTest.php` (2): asli controller se, ek hi din ke bill (300 maal
  + 30 delivery) par — har section ke liye manual Thermal print aur network slip ki aakhri lines
  barabar (30 / 330); lines GRAND TOTAL ke neeche aur cut se pehle.

## Asar

| Cheez | Asar |
|---|---|
| `EscPosPayloadService::buildReport()` | 3 jagah lines judti hain; baqi har byte waisa |
| `print.blade.php` (manual print, nightly PDF) | Kuch nahi |
| Schema, routes, permissions, paisa, stock, GL | Kuch nahi |

## Abhi nahi chheda (alag kaam, owner ki marzi)

- Bari raqam par network slip me columns jud jate hain (`0133,480`): column ki chaurai naapte waqt
  Categories ka GRAND TOTAL nahi gina jata.
- "Send to Network" Deals ka data nahi bhejta: Deals tick karo to network slip par kuch nahi.
- "By Order Type" section network slip me hai hi nahi.
