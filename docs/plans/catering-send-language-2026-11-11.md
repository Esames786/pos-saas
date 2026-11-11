# CATERING-SEND-LANGUAGE-1 — "Send to network" chuni hui zubaan me chhape (11 Nov 2026)

## Ask
Owner (Kashif Kitchen par, 11 Nov): "kitchen sheet bulk / single, event screen ke andar ya bahar — Urdu ya sirf
English select karo, send to network hamesha English + Urdu print bhejta hai. Quotation, proforma, invoices — sab
send to network check karke fix karo."

## Wajah (code + prod, read-only)
- Preview safhe (`lang=en|ur|both`) par "Send to network" ka form (`documents/partials/send-to-network`) sirf `kind`,
  `ids[]`, `printer_id`, `reprint` bhejta tha — **zubaan nahi**.
- `CateringBulkDocumentController::printToNetwork` → `CateringDocumentQueueService`:
  kitchen sheet + quotation = hamesha `CateringSetting::print_language_profile`; proforma = hamesha `'en'`.
- Final invoice ka apna raasta (`sendExtra['lang']`) — wo pehle se theek tha.
- Prod: KK `print_language_profile = "both"`; aakhri 12 network parche (kitchen sheet + quotation) sab "both" me chhape.

## Fix
- Partial: bulk wale form me `<input type="hidden" name="lang" value="{{ $lang }}">` (`isset($lang)` — address sheet
  ki koi zubaan nahi).
- Controller: `lang` nullable, `Rule::in(CateringSetting::PRINT_PROFILES)`; kitchen sheet / quotation / proforma ko deta hai.
- Service: `queueKitchenSheet`, `queueKitchenSheetForEvent`, `queueQuotation` ko aakhri `?string $lang = null`;
  `language($chosen)` — chuni hui zubaan, warna settings ki default (purana rawaiya).
- Zubaan na aaye: kitchen sheet / quotation = settings default, proforma = English (dono pehle jaise).
- **Nahi badla:** booking screen ka chhota "Print" dropdown (`partials/document-print`, thermal ke liye, zubaan ka khaana
  nahi) — Windows printer par settings ki default hi rehti hai; final invoice; address sheet; thermal raaste.

## Impact (system map)
- **Tables:** `print_jobs` (sirf `raw_payload` ka HTML zubaan badalta hai), `catering_settings` (sirf parha). Koi migration nahi.
- **Routes / screens:** `tenant.catering.documents.bulk-print` (POST, naya optional `lang`); jo safhe form dikhate hain:
  kitchen sheet (release + preview), quotation, proforma, bulk kitchen sheets, bulk quotations, address sheet.
  Permission / route naya nahi.
- **Tests:** naya `CateringSendLanguageMySqlTest`; saath `CateringSendToPrinterMySqlTest`, `CateringKitchenSheetPreviewMySqlTest`,
  `CateringViewRenderMySqlTest`, `CateringDocumentMetaMySqlTest`, `CateringProductionPrintMySqlTest`.

## Results
Worktree `pos-saas-kkvenue`, branch `fix/catering-send-language-20261111` (origin `89f823f3` se; prod `6f92144b`). Test DBs `_kkv`.
- `php -l` service / controller / test; partial compile + `php -l`: saaf. Render: test safhe controller se kholta hai
  (kitchen sheet preview, quotation, proforma, bulk kitchen sheets, bulk quotations).
- MySQL: SendLanguage 4 · SendToPrinter 19 · KitchenSheetPreview 25 · ViewRender 12 · DocumentMeta 7 · ProductionPrint 4 —
  **71 tests, 410 assertions, sab hare.** Zubaan pehchanne wala helper teeno nateeje (en / ur / both) de kar sabit hua.
- **Sabotage** (har ek ke baad file wapas, `cmp`): A form se `lang` hataya → "'ur' me khula safha 'ur' nahi bhejta" ·
  B controller `lang` nazarandaz → "'en' chuna magar printer par 'both' gaya" · C service purani default → wohi. Teeno laal.
- Deploy: abhi nahi — push aur deploy owner ke lafz par.

## Deploy (11 Nov 2026, ~11:37 UTC) — LIVE `2d5c7c9e`
- Owner: "kardo deploy". Before: canonical = origin = `89f823f3`; prod `6f92144b` — the only extra commit riding along was
  `89f823f3` (KOT-DEAL-HEADING-1 deploy record: docs + system map, no code).
- Merge `2d5c7c9e` → push → `origin == HEAD` alag qadam me → `bash deploy.sh` (9/9, tenants ok=13 skipped=0 — retaildemo
  ab theek). "Nothing to migrate". Prod `/tmp` saaf ho chuka tha: `perm_snapshot.php` + `kkv_smoke.php` dobara upload kiye.
- **Permissions:** `PERMISSIONS OK: no role lost anything, no user lost a role` (14 sets, grants barabar, gained: -).
- **Smoke** (pehle = baad, 13 tenants): `tb_diff=0.00`, `neg_on_disallowed=0`, `neg_on_allowed=0`, `department_negative=0`.
  Log: 11:36 ke baad 0 ERROR.
- **Live (kashifkitchen, EV-20261111-0595, printer #4, settings default "both"):** preview `ur` → form `lang=ur`, `en` →
  `lang=en`. `printToNetwork` ek tenant transaction ke andar, phir **rollBack**: kitchen sheet ur→ur, en→en; quotation en→en,
  ur→ur; proforma ur→ur. `print_jobs` 69 → 69 — koi kaghaz nahi chhapa. Login 200.
