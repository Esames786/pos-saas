# KITCHEN-SHEET-VENUE-ONCE-1 — kitchen sheet par jagah sirf EK baar (5 Nov 2026)

## Ask
Catering client ki note (5 Nov): "2 dafa address aata hai kitchen paper mei (venue wala aaye)".
Owner ne faisla manzoor kiya: **venue bhara ho to sirf venue chhapega, khali ho to graahak ka pata.**

## Wajah
Kitchen sheet ke paer (`foot-r`) par `venue` AUR `customer_address` dono chhapte the
(`kitchen-sheet-body.blade.php`). Operator aksar dono khanon me ek hi jagah likhta hai.
Prod par (read-only, 5 Nov): 20 Oct se 219 bookings, 31 me dono bhare, **19 me bilkul ek hi text**
("johar / johar", "GARDEN / GARDEN"); baqi 12 me pata venue ka hissa ya graahak ka ghar.

## Qaida
- `trim(venue) !== ''` → sirf venue (`<div class="venue">`).
- warna `trim(customer_address) !== ''` → pata (`<div class="addr">`).
- Sirf spaces wala venue khali gina jata hai.
- Graahak ka ghar ka pata gum nahi hota: driver ki **Address Sheet** (`address-sheet.blade.php`) use alag se
  chhapti hai (wahan pehle se `customer_address !== venue` par hi doosri satar aati hai).

## Impact (system map)
- **Tables:** `catering_events.venue`, `catering_events.customer_address`, `catering_production_releases.snapshot`
  — sirf PARHE jate hain. Koi migration nahi, koi data nahi badalta.
- **Screens / routes** (sab ek partial `documents/partials/kitchen-sheet-body` se):
  `tenant.catering.documents.kitchen-sheet` (release), `tenant.catering.documents.kitchen-sheet-preview`,
  `tenant.catering.documents.bulk-kitchen-sheets`, aur network print
  (`CateringDocumentQueueService` → `view('tenant.catering.documents.kitchen-sheet')`).
- **Nahi chhua:** quotation / estimate / final invoice / address sheet, routes, models, controllers, services,
  permissions.
- **Tests:** `CateringKitchenSheetPreviewMySqlTest` — purana `test_the_sheet_carries_the_customer_address`
  (venue ke saath bhi pata chhapne par zor) naye qaide se takrata tha; us ki jagah 5 test (pata jab venue
  khali · ek hi jagah ek baar · venue ho to ghar ka pata nahi · spaces = khali · release + bulk raasta, controller
  se). Saath chale: `CateringKitchenSheetDensityMySqlTest`, `CateringProductionPrintMySqlTest`,
  `CateringViewRenderMySqlTest`.

## Results

Worktree `pos-saas-kkvenue`, branch `fix/kitchen-sheet-venue-once-20261105` (prod `91dcd4f9` se). Test DBs `_kkv`.
- Blade compile + `php -l`: saaf. Render: test controller ke raaste se (preview, release, bulk).
- MySQL: Preview 23/23 · Density 11/11 · ProductionPrint 4/4 · ViewRender 12/12 — **50 tests, 282 assertions, sab hare.**
- **Sabotage:** purana Blade wapas lagaya → 5 naye test me se **4 laal** (ek jagah 2 baar: 2≠1 · ghar ka pata chhapa ·
  spaces wala venue · release ka parcha 2≠1). 5wan ("venue khali ho to pata") purane par bhi hara — wo purane sahi
  rawaiye ka pehra hai. Fix wapas (byte-ba-byte `cmp`), 5/5 phir hare.
- Deploy: abhi nahi — push aur deploy owner ke alag lafz par.
