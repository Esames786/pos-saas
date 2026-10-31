# Product advanced settings release — 31 October 2026

Owner authorized deployment, then supplied the established deploy.sh procedure. Follow that procedure; no extra cache-reset experiments and no live test transactions.

## Candidate and verification

- Original code: `64a50d77`; original review: `365bd274`.
- Integrated production through `073238de` / docs `d5e2a479`: candidate `b6f96e36`.
- Related MySQL run on that clean revision: **28 passed, 610 assertions**, 36m 18.208s, isolated `_pp28reg` databases. This is a focused regression, not the full application suite.
- Real Chrome on that revision: **34/34**, desktop 1366x768 and phone 390x844. Initial local-server attempts failed before any checks (wrong working directory, then the login navigation exceeded the local timeout). Corrected the test server directory and increased only test-script timeouts; no application fix was needed.
- Production independently advanced to `5bdaac80` (Shift Handover). Preserved it by a clean merge: **`50b8526a`**. Its added feature constant/list entry and shift files do not overlap this product change. Product application files and tests are byte-identical to the 28-test revision.
- Final merged revision `50b8526a`: PHP syntax passed; real Chrome repeated **34/34**, no page JavaScript errors. The 28-test backend set was not repeated after this unrelated production merge. Evidence is in `docs/reviews/product-parity-release-2026-10-31/`.
- System map regenerated at `50b8526a`: 236 tables, 438 foreign keys, 223 inferred links, 745 screens, matching the existing counts. No route/table/migration/dependency/permission change in this release.

## Production preflight

- Before this release there are 13 tenant entries but **11 configured tenant databases**. `inventorydemo` and `retaildemo` have no database configuration/schema. This predates this deployment; do not reset or provision them as part of this task.
- Read-only preflight: master and all 11 existing tenant schemas have **0 pending migrations**. All 11 have `tb_diff=0.00`, no disallowed negative stock and no negative department stock.
- Product GET probe: **27 pages HTTP 200**, 11 tenants, existing owners. Advanced Recipe/BOM/Cost Block controls absent before deploy, as expected. The two missing demos are explicitly skipped.
- The owner said taking another backup was not necessary. Existing **historical** backup `storage/app/private/backups/20261030_020002` was checked: master + 13 complete SQL dumps; all 11 schemas that currently exist have a dump. This is **not a fresh deployment-time backup**. The old coverage script reports two gaps because the two current tenant configurations are missing; that output was retained and investigated, not described as a clean 13-tenant check.
- Fresh permissions/configuration/reconciliation snapshots use `/tmp/product-parity-release-20261031/`, leaving the other session's `/tmp/*before.json` untouched. The existing helper scripts were copied there and their snapshot paths scoped to this release.

## Deployment procedure

Use the existing deploy.sh, with exact origin/server revision checks and a clean tracked tree. Every artisan invocation must run as www-data. The existing vendor directory is root-owned, so Composer installs/autoload generation run as usual with scripts deferred; its existing post-autoload hook runs under www-data. PHP calls in deploy.sh are wrapped to run as www-data. The normal script's permission/cache/ownership/FPM steps are retained. No extra cache flush, manual rate save, backfill, reset or feature activation.

After deployment: repeat smoke, permissions/configuration comparisons, table reconciliation and the same 27 Product GETs. Missing demo configurations remain a separately recorded existing issue. Fix forward if a problem is found; do not restore an old database backup over subsequent sales.

## Deployment result

Pending. This record is prepared before push/deploy; a separate record commit will add the actual deployed revision and after-check results.