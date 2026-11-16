# KITCHEN-SHEET-VENUE-ONCE-1 + VENUE-NAME-ONLY-1 — venue = naam, parche par koi jagah do baar nahi (5 Nov 2026)

## Ask
Catering client ki note (5 Nov): "2 dafa address aata hai kitchen paper mei (venue wala aaye)".
Owner (5 Nov), do qadam me:
1. Pehle manzoor: "venue ho to sirf venue, warna pata".
2. Phir data dekh kar badla: "venue itna bara ke wo ek address jaisa lag raha hai … sirf one two word pe kar do"
   → sawal par chuna **"Form: venue me sirf naam"** — booking form ke Venue me sirf hall/jagah ka naam, poora pata
   Address me, aur kitchen sheet par **dono** (naam + pata). Purani bookings jaisi hain waisi.

## Wajah (prod, read-only, 5 Nov)
- Kitchen sheet ke paer (`foot-r`) par `venue` AUR `customer_address` dono chhapte the.
- 20 Oct se 219 bookings: 31 me dono bhare, **19 me bilkul ek hi text** ("johar / johar", "GARDEN / GARDEN").
- 1 Oct se 422 bookings: 77 me venue bhara, us me **32 me 5+ lafz** — asal me poora pata
  ("Universal Banquet (Zaver), 4K chorangi k baad, right side pe. Adjacent to Kifaya").

## Qaide
**Form (VENUE-NAME-ONLY-1)**
- `CateringEvent::VENUE_MAX_LENGTH = 30` (huroof, lafz nahi: "Candle Banquet Johar", "Al Mehmil Banquet Five Star"
  samate hain; pate jaise 50–83 huroof nahi).
- Venue input: `maxlength`, placeholder "Jaise: Universal Banquet", hint "Sirf hall ya jagah ka naam. Poora pata upar
  Address me likhein." Booking ka Address isi booking ka hai — graahak ki address book me nahi likha jata.
- Server (`CateringEventController::venueIsAName`): sirf NAYA ya BADLA hua venue parakhta hai. Purani booking ka lamba
  venue bina chhede wapas aaye to baqi editing nahi rukti (browser bhi maxlength sirf chhede hue khane par lagata hai).
**Kitchen sheet (KITCHEN-SHEET-VENUE-ONCE-1)**
- Dono chhapte hain: pehle venue, phir pata.
- Jo ek doosre me pehle se shamil ho (bari/chhoti likhai aur spaces chhor kar: barabar, ya ek ka text doosre ke andar)
  → sirf LAMBA wala. "Universal Banquet" + "Universal Banquet (Zaver), 4K…" → sirf pata; purana lamba venue +
  "gulistan e johar" → sirf venue; "maskan" + "gulshan" → dono.
- Sirf spaces wala khana khali.

## Impact (system map)
- **Tables:** `catering_events.venue` / `.customer_address` (likhai: sirf form validation badli, column nahi),
  `catering_production_releases.snapshot` (sirf parha). Koi migration, koi data change nahi.
- **Screens / routes:** booking form (`tenant.catering.events.create/store`, offcanvas `edit/update` — ek partial
  `events/partials/event-form-fields`); kitchen sheet `tenant.catering.documents.kitchen-sheet`, `…kitchen-sheet-preview`,
  `…bulk-kitchen-sheets` aur network print (`CateringDocumentQueueService` → wohi view) — ek partial
  `documents/partials/kitchen-sheet-body`.
- **Nahi chhua:** quotation / invoice / Address Sheet (driver), permissions, routes, services.
- **Tests:** naya `CateringVenueNameOnlyMySqlTest` (6); `CateringKitchenSheetPreviewMySqlTest` — purana
  `test_the_sheet_carries_the_customer_address` hata kar 7 (pata jab venue khali · barabar = ek satar · naam + alag pata
  dono, tarteeb · naam pate ke andar · pata purane venue ke andar · spaces = khali · release + bulk raasta).

## Results
Worktree `pos-saas-kkvenue`, branch `fix/kitchen-sheet-venue-once-20261105` (prod `91dcd4f9` se). Test DBs `_kkv`.
- `php -l` controller / model / tests; dono Blade compile + `php -l`: saaf. Render: tests controller ke raaste se
  (booking form `create()`, kitchen sheet preview, release, bulk).
- MySQL (12 files): VenueNameOnly 6 · KitchenSheetPreview 25 · Density 11 · ProductionPrint 4 · ViewRender 12 ·
  EventAjax 7 · PhoneAndLink 17 · EventActions 13 · RestoreInPlace 14 · DocumentMeta 7 · RedirectContract 4 ·
  BookingLookupHttp 15 — **135 tests, 582 assertions, sab hare** (PhoneAndLink ki 1 deprecation us ka purana
  `@dataProvider` docblock hai).
- **Sabotage** (har ek ke baad file wapas, `cmp` se byte-ba-byte):
  A venue rule hataya → 3 laal · B purani booking ki chhoot hatai → 1 laal (PAX ki editing ruki) · C `maxlength`
  hataya → 1 laal · D kitchen sheet prod wali → 5 laal · E kitchen sheet pehli "sirf venue" wali → 2 laal.
- Pehla commit `ff2d97ef` ("sirf venue") owner ke doosre faisle se badla — history me rakha hai.
- Deploy: abhi nahi — push aur deploy owner ke alag lafz par.

## Deploy (5 Nov 2026, ~14:08 UTC) — LIVE `8ea7270c`
- Owner: "push karo and deploy karo". Before: canonical = origin = prod = `91dcd4f9` (kisi aur ka kaam saath nahi gaya).
- Merge `8ea7270c` → push → `origin == HEAD` alag qadam me sabit → `bash deploy.sh` (9/9, tenants ok=12, `retaildemo`
  skip = purana "Tenant database configuration missing"). "Nothing to migrate". Backup nahi liya: koi migration / data
  change nahi; wapsi = `git revert` + deploy.
- **Permissions:** har tenant ke har role ki copy pehle aur baad — `PERMISSIONS OK: no role lost anything, no user lost
  a role` (grants har jagah barabar, gained: -).
- **Smoke** (pehle = baad, 12 tenants): `tb_diff=0.00`, `neg_on_disallowed=0`, `neg_on_allowed=0`, `department_negative=0`.
  Log: 14:07 ke baad 0 ERROR.
- **Live (kashifkitchen, read-only):** booking form venue input `maxlength="30"` + hint; kitchen sheet preview:
  EV-20261025-0405 / EV-20261014-0284 (venue = pata) → ek baar; EV-20261005-0174 → "maskan | gulshan";
  EV-20261014-0281 (pata venue ke andar) → sirf venue; EV-20261102-0497 → "self". Releases 19→19, bookings unchanged.
  `kashifkitchen.bingoopos.com/login` 200, `bingoopos.com` 200.
