# Edge next release — W-C (Team C) report: assets, self-hosted Nunito, boundary gates

Date: 2026-09-27 · Branch: `feat/edge-config-refresh-v1` (worktree `D:\laragon2\www\pos-saas-edge`) · No commit / push.
Scope: owner decision **A3** (approved) + §4 / §11 W-C / §15 of `edge-next-release-shared-pos-architecture.md`.

## 1. Result in one paragraph

The two Google Fonts `@import` lines are gone from `public/assets/css/style.css`. Nunito is now self-hosted: one
WOFF2 file plus the OFL 1.1 licence and a provenance README under `public/assets/fonts/nunito/`, and five
`@font-face` rules in the new `public/assets/css/fonts-local.css`. Poppins is dropped because nothing used it. The
Edge local asset controller has a second hardened root, `storage/app/public`, served at `GET /edge/local/storage/{path}`
(`edge.local.storage`). While adding it I found that on Windows a directory **junction** got past the existing
symlink walk (`is_link()` returns false for a junction). This is now closed for both roots. The release package builder
refuses untracked files under `public/` or `resources/`. Four Feature gates are extended, one new boundary gate is added,
and the MySQL cashier-shell test now fetches every stylesheet the page links.
**One step is still open. It is outside W-C's files, so I could not do it: the layouts must link `fonts-local.css`
(see §6.1). Until they do, Cloud and Edge render the fallback sans-serif instead of Nunito.**

## 2. Files changed / created (W-C ownership only)

| File | Change |
|---|---|
| `public/assets/css/style.css` | lines 3-4 (the two `@import url("https://fonts.googleapis.com/…")`) replaced by one comment line |
| `public/assets/css/fonts-local.css` | **new** — 5 × `@font-face` (Nunito 300/400/500/600/700, `font-display: swap`, latin `unicode-range`) |
| `public/assets/fonts/nunito/nunito-latin-wght-v32.woff2` | **new** — 39,128 bytes, SHA-256 `ba344451eab25b217a165363b1982048a5e5830a0daf36577973955a04cac793` |
| `public/assets/fonts/nunito/OFL.txt` | **new** — SIL Open Font License 1.1 text (from google/fonts `ofl/nunito/OFL.txt`) |
| `public/assets/fonts/nunito/README.md` | **new** — source URL, Google Fonts version, upstream commit, SHA-256, licence note |
| `app/Http/Controllers/Edge/EdgeLocalAssetController.php` | second root `storage/app/public` (`storage()`, `storageUrl()`, `storageAvailable()`, `STORAGE_ROUTE_NAME`); shared `serve()`/`resolve()`; image types only; sandbox CSP on storage responses; **Windows junction detection** on both roots |
| `routes/edge_runtime.php` | +1 route statement (+1 comment line) after the `assets` route (see §5) |
| `config/edge.php` | +1 allowlist line (see §5) |
| `tests/Feature/Edge/EdgeBranchServerRegistrationTest.php` | +1 URI census entry (see §5) |
| `app/Console/Commands/EdgeBuildPackageCommand.php` | untracked-file refusal in RELEASE mode + `--allow-untracked` dev escape |
| `tests/Feature/Edge/EdgeApplianceArtifactBoundaryTest.php` | **new** gate (6 tests) |
| `tests/Feature/Edge/EdgeBladeCompileGateTest.php` | +2 tests (tenant/pos/** and layouts/pos) |
| `tests/Feature/Edge/EdgeApplianceDependencyClosureTest.php` | +1 test (Blade class scan); walk extracted to `walk()` |
| `tests/Feature/Edge/EdgeLocalAssetRouteTest.php` | +6 tests (full layout list, fonts, storage route ×4) |
| `tests/MySql/EdgeCashierShellHttpMySqlTest.php` | +1 test (fetch + scan every linked stylesheet) |

Nothing under `C:\Users\Dell\BingooEdgeLab` was touched. No other font file was added.

## 3. Font: source, version, licence

- **The download worked.** This machine had network access. The files were fetched once and are local from now on.
- CSS source: `https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700&display=swap` (Chrome UA).
  For the `/* latin */` block, Google returns the **same** URL for all five weights: a single variable-weight
  (`wght` axis 200-1000) file, `https://fonts.gstatic.com/s/nunito/v32/XRXV3I6Li01BKofINeaB.woff2`.
- So one file is vendored, not five: `nunito-latin-wght-v32.woff2`. Five static copies would have been identical
  duplicates. The five `@font-face` rules in `fonts-local.css` copy Google's rules exactly (same weights, same
  `unicode-range`, same `font-display: swap`) and all point at that one file. **The browser gets the same bytes and
  the same rules as it did from Google, just from a local host.** That matters for the §13 pixel-parity run. This
  differs from the `nunito-{300..700}-latin.woff2` naming in §4.2 on purpose.
- Version: Google Fonts gstatic **v32**. Upstream is `github.com/googlefonts/nunito`, commit `8c6a9bb9732545b9ed53f29ec5e1ab0ff53c4e6f`
  per google/fonts `ofl/nunito/METADATA.pb`.
- Licence: **SIL Open Font License 1.1**, "Copyright 2014 The Nunito Project Authors". The full text is in
  `public/assets/fonts/nunito/OFL.txt` and ships in the artifact next to the font. The OFL allows bundling with software.
- Subset: latin only, as instructed. Latin-ext, Cyrillic and Vietnamese characters fall back to `sans-serif`, the next
  family in the style.css stack. This is recorded in the README.
- The family name is exactly `"Nunito"`, so the four `font-family: "Nunito", sans-serif` rules in style.css still match.
- `url("../fonts/nunito/…")` is relative, so it resolves under both `/assets/css/` (Cloud) and `/edge/local/assets/css/` (Edge).

### Poppins — dropped (verified unused)

`grep -rni poppins public/assets/css resources/views` only finds the removed `@import` line. No CSS rule and no view uses
it. Two dormant vendor JS configs name `fontFamily: 'Poppins'`: `public/assets/js/jsvectormap.js` and
`public/assets/plugins/apexchart/chart-data.js`. No view loads either file, so I left them alone.

## 4. style.css diff

```diff
@@ -1,7 +1,6 @@
 @charset "UTF-8";
 /****** Utils ******/
-@import url("https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700&amp;display=swap");
-@import url("https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&amp;display=swap");
+/* Fonts: Nunito is self-hosted (assets/css/fonts-local.css, linked before this file) - no remote font import (owner decision A3). */
 .search-dropdown .search-info .customers li a img {
```

After the change, `style.css` has no `@import`, no `fonts.googleapis` / `fonts.gstatic`, and no remote `url()`. The only
`http://` left is the `xmlns="http://www.w3.org/2000/svg"` inside two `data:` SVG URIs, which the browser never fetches.

## 5. Exact route / allowlist / census lines added

`routes/edge_runtime.php` (inside the `edge/local` group, immediately after the `->name('assets');` statement):

```php
    // W-C (Edge next release §4.3) — product images from storage/app/public (png/jpg/jpeg/webp/svg only; same hardening; sandbox CSP; no session). Allowlist: `edge.local.storage`.
    Route::get('/storage/{path}', [\App\Http\Controllers\Edge\EdgeLocalAssetController::class, 'storage'])->where('path', '.*')->withoutMiddleware([\Illuminate\Session\Middleware\StartSession::class, \Illuminate\View\Middleware\ShareErrorsFromSession::class, \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])->name('storage');
```

`config/edge.php` `route_allowlist` (immediately after `'edge.local.assets',`):

```php
        'edge.local.storage',  // W-C: whitelisted storage/app/public image streamer (EdgeLocalAssetController::storage; images only, no session)
```

`tests/Feature/Edge/EdgeBranchServerRegistrationTest.php` URI census (immediately after `'edge/local/assets/{path}',`).
The census is in `tests/Feature/Edge`, not `tests/MySql`:

```php
            'edge/local/storage/{path}', // W-C: whitelisted storage/app/public image streamer (unauthenticated; EdgeLocalAssetRouteTest)
```

Team B edited all three files at the same time. After their edits I re-checked that my three lines are still present,
and they are.

## 6. Open items for the coordinator (outside W-C ownership)

### 6.1 MERGE BLOCKER — link `fonts-local.css` in the layouts

Without this, removing the Google import means **both** Cloud and Edge render the fallback sans-serif instead of Nunito.
Add one line immediately **before** the `style.css` `<link>` in each layout that links style.css:

| Layout | Owner | Line to add |
|---|---|---|
| `resources/views/layouts/app.blade.php` (before line 21) | coordinator | `<link rel="stylesheet" href="{{ asset('assets/css/fonts-local.css') }}">` |
| `resources/views/layouts/auth.blade.php` (before line 15) | coordinator | `<link rel="stylesheet" href="{{ asset('assets/css/fonts-local.css') }}">` |
| `resources/views/layouts/pos.blade.php` (before the style.css link, line 31) | W-A | `<link rel="stylesheet" href="{{ $posRuntime->asset('assets/css/fonts-local.css') }}">` |

The gate `EdgeApplianceArtifactBoundaryTest::test_every_layout_that_links_style_css_links_fonts_local_css_right_before_it`
reports this as **INCOMPLETE**, naming each missing layout and the exact line. Once the lines are in, it becomes a hard
assertion: linked, before style.css, and adjacent. On Edge, `$posRuntime->asset()` sends the file through
`edge.local.assets`. The `.woff2` it references then loads through the same route, which `EdgeLocalAssetRouteTest` proves.

### 6.2 Finding — `EdgeLocalShiftController` (W-B, in progress) renders Cloud chrome on Edge

The new route-level gate `test_cloud_chrome_views_may_ship_but_no_edge_local_route_renders_them` **fails** on the current
working tree:

```
partials.header  <- layouts.app <- tenant.shifts.open  <- App\Http\Controllers\Edge\EdgeLocalShiftController
partials.sidebar <- layouts.app <- tenant.shifts.open  <- App\Http\Controllers\Edge\EdgeLocalShiftController
```

W-B's uncommitted `EdgeLocalShiftController` now returns `view('tenant.shifts.open' | 'tenant.shifts.close' | 'tenant.shifts.index' | 'tenant.shifts.show')`.
All of these `@extends('layouts.app')`, which includes `partials.header` and `partials.sidebar`. Per §1.1, those use
`app('tenant')->subscription`, central-admin fallbacks and EdgeDevice queries. The layout's view composer also imports
`App\Services\Saas\TenantSubscriptionAccessService`, which is **excluded from the artifact**. The appliance would render
Cloud chrome, or fail on class loading, on the shift pages. Fix: those views need the shared layout (`layouts.pos`, or a
shared shift layout with the runtime chrome slot) before Edge renders them. That is W-A/W-B work.
**The gate is right and I have not weakened it.**

### 6.3 Observation — Cloud runtime fallback inside a shared partial

`resources/views/tenant/pos/partials/table-board.blade.php:54` builds a URL with
`($posRuntime ?? app(\App\Support\Pos\CloudPosRuntimeFactory::class)->make())->route('posIndex')`. The class ships, and
the closure gate is green. But if an Edge endpoint renders this partial without passing `$posRuntime` (for example the
planned `pos.restaurant.board.html`), the Edge page gets a Cloud `/pos` URL. W-B should always pass `$posRuntime`.

## 7. Gates added / extended

1. **EdgeBladeCompileGateTest** (+2): `resources/views/tenant/pos/**` (recursive, every depth: index, js/pos-runtime,
   partials/*) compiles and passes `php -l`. `layouts/pos.blade.php` does too; that test skips with a message while the
   file is absent. W-A has since created the file, so it now runs and passes.
2. **EdgeApplianceDependencyClosureTest** (+1): scans `tenant/pos/**` and `layouts/pos.blade.php` for `\App\…::` (static
   calls, constants, `::class` in `app(…)`, `@can(\App\…::CONST)`), `@inject`/`app('App\…')`, and `use App\…;`. Every
   referenced class must exist, its file must be in the artifact plan (not excluded), and so must its constructor closure.
   None may reach Saas/Catering/Manufacturing/Purchasing/Central. **Classes found today:**
   - `App\Models\Tenant\User` (index — `User::ORDER_TYPES`)
   - `App\Models\Tenant\VoidReason` (index — `VoidReason::where(…)`)
   - `App\Services\Security\UserDataScope` (index — `@can(UserDataScope::CHANGE_TERMINAL_PERMISSION)`)
   - `App\Support\TenantClock` (index, table-bill-preview — `app(TenantClock::class)`)
   - `App\Support\Pos\CloudPosRuntimeFactory` (table-board — see §6.3)

   `App\Models\Tenant\Printer` is **not** referenced statically by the shared views today, so it is not in the list.
3. **EdgeApplianceArtifactBoundaryTest** (new, 6 tests):
   - fonts-local.css, style.css and the three nunito files are in the plan, and the nunito directory holds exactly
     those three files. The file is a real WOFF2, the README carries its SHA-256, and OFL.txt is the OFL 1.1 text.
   - style.css as shipped: no `@import`, no Google host, no Poppins, and it still names `"Nunito"`.
   - every CSS linked by `layouts/app` and `layouts/pos` (via `asset()` / `$posRuntime->asset()` /
     `EdgeLocalAssetController::url()`), plus fonts-local.css, ships and has no `@import` or remote `url()`.
   - layouts that link style.css link fonts-local.css right before it (INCOMPLETE until §6.1 is done).
   - route level: every `edge.local.*` route's controller (and its App parents) is scanned for the views it renders,
     and the Blade closure is expanded through `@extends/@include*/@each/@component/<x-…>`. That closure must never
     reach `partials.header` / `partials.sidebar`. No Edge-side class may even name `partials.header`,
     `partials.sidebar`, `layouts.app` or the Cloud chrome slot `tenant.pos.partials.pos-chrome-cloud`, because the
     layout includes the chrome by a runtime variable. Header/sidebar can still ship inside `resources/`. **Currently
     fails on W-B's in-progress shift controller (§6.2).**
   - the release package builder refuses untracked files under public/resources (uses `Process::fake`, builds nothing).
     The refusal is fail-closed when git cannot answer. With no untracked files the release moves on to its next
     requirement. `--allow-untracked` warns instead.
4. **EdgeLocalAssetRouteTest** (+6):
   - the full layouts/app (+ layouts/pos) list returns 200 with the whitelisted type: style, fonts-local, the Nunito
     WOFF2, bootstrap css/js, datetimepicker css, animate, select2 css/js, daterangepicker css/js, tabler, fontawesome
     ×2, a11y-custom, jquery, feather, slimscroll, moment, sweetalert, script.js, theme-script.js, favicon, apple-touch-icon.
   - style.css served through the route has no `@import` or remote url. Each fonts-local `url()` is resolved relative
     to `/edge/local/assets/css/` and fetched: 200 `font/woff2`, `wOF2` magic, weights `[300,400,500,600,700]`.
   - storage: png/jpg/JPEG/webp/svg served with the right type, `nosniff`, sandbox CSP, ETag and 304, and no session cookie.
   - storage refusals: the real `.gitignore` dot-file, dot-files with an image extension, txt/html/php/css/ico,
     every traversal form (raw, `%2F`, `%2e%2e`, `%5C`), drive-absolute, absolute, empty or dot segment, NUL smuggling,
     a real png outside the root, and a missing file.
   - symlink/junction: POSIX symlinks where the host allows them. On Windows, a junction to a directory **outside** the
     root, and one to a directory **inside** the root, are both refused. The inside case is exactly the gap that
     `is_link()` missed.
   - `edge.local.storage` is on the shipped allowlist and resolves to `edge/local/storage/{path}`.
5. **EdgeCashierShellHttpMySqlTest** (+1, `test_every_linked_stylesheet_is_served_by_the_app_and_carries_no_import_or_remote_url`):
   every `<link rel="stylesheet">` on `GET /edge/local/pos` must be same-origin and fetched through the app: 200,
   `text/css`, no `@import`, no remote `url()`, no `fonts.googleapis`. If fonts-local.css is linked, each of its WOFF2
   targets must also be 200 `font/woff2`. It is a separate method next to the existing no-external-URL test, so the
   old page-specific assertions (which W-B's page switch changes) do not mask it.

## 8. Controller hardening detail

- `resolve($path, $rootDir, $types)` is shared by both roots, so the grammar is identical. Segments must match
  `^[A-Za-z0-9][A-Za-z0-9._-]*$`. No NUL, `\`, `:`, empty segment, dot segment or dot-file; at most 255 bytes;
  extension whitelist per root. There is no link on any segment. The real path must stay under `realpath(root)`.
- **New for both roots:** `isWindowsReparsePoint()`. On Windows, `readlink()` returns the path itself for an ordinary
  file or directory, and returns the target for a junction or link. A different answer (compared case-insensitively)
  is refused. The real-path check already caught junctions pointing *outside* the root. A junction pointing to
  another directory *inside* the root used to be served; it is now a 404.
- Storage types: `png, jpg, jpeg, webp, svg`. Every storage response carries
  `Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox` and `nosniff`,
  so an uploaded SVG opened directly cannot run script in the appliance origin.
- The storage route is unauthenticated and starts no session, like `assets`. Product photos are public-disk content
  on the Cloud too. It is read-only (GET).

## 9. Release builder

`edge:build-package` now runs `git status --porcelain --untracked-files=all -- public resources`. The existing
`--untracked-files=no` dirty probe deliberately never saw untracked files.

- **RELEASE mode** with any `??` entry: refused. It lists up to 20 paths and suggests commit/remove, or
  `--allow-untracked` for a DEV package. If git itself fails, the probe reports that as a finding, so a release refuses.
- `--allow-untracked` makes the build a DEV build, like `--allow-dirty` does. Untracked files then produce a warning,
  and the build is stamped dirty (`artifact_version …-dirty`, `source_dirty=true`).
- `--allow-dirty` dev builds (the existing tests and the LAB flow) behave the same, plus the warning and the dirty stamp.

## 10. Test runs

All runs used PHP 8.3.16 on the shared working tree, with the other teams' in-progress changes present.

| Run | Result |
|---|---|
| W-C gates on their own: `EdgeLocalAssetRouteTest` + `EdgeBladeCompileGateTest` + `EdgeApplianceDependencyClosureTest` | **OK, 18 tests, 458 assertions**, all green |
| `EdgeApplianceArtifactBoundaryTest` | 6 tests: 4 pass, 1 **incomplete** (§6.1, layouts not yet linking fonts-local.css), 1 **fails** (§6.2, W-B shift pages render `layouts.app` on Edge) |
| `EdgeBranchServerRegistrationTest` (census incl. `edge/local/storage/{path}`) | green |
| `vendor/bin/phpunit tests/Feature/Edge` (full) | **162 tests, 36,089 assertions: 1 failure, 1 incomplete**. The only failure is the §6.2 finding. |
| MySQL `EdgeCashierShellHttpMySqlTest` on the isolated t1 DBs | **10 tests, 161 assertions: 8 pass, 2 fail**. The new W-C test `test_every_linked_stylesheet_is_served_by_the_app_and_carries_no_import_or_remote_url` **passes**. The 2 failures are not caused by W-C (see below). |

The 2 MySQL failures are `test_branch_and_terminal_context_dialog_and_change_gate` and
`test_return_and_quick_report_buttons_follow_the_online_permission`. Both assert that a user with **no** permission does
not get `#pos-context-change-btn` or `canSalesReturn`. On the current tree the page renders both as granted (for example
`"canChangeTerminal":true`, `"canSalesReturn":true`). That is permission-model behaviour: W-E has uncommitted edits to
`TenantProvisioner` and the permission catalogue, and W-B has edits to `EdgeLocalPosController`. None of the files W-C
touched affects it.
