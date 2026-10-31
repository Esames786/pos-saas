# Unified product advanced settings — 28 October 2026

Status: deployed on 31 October as `b78d8dba`. See [release and production verification](product-parity-release-2026-10-31.md). The original 29 October implementation evidence below is retained.

Base: `f1a64bb8`. Isolated worktree: `pos-saas-product-parity`, branch `feat/product-parity-20261028`.

Implementation commit: `64a50d77`. At the original 29 October checkpoint this was local-only; the later production release is recorded in the linked release document.

## Scope

The unified form already contains Catering Profile controls (27 October release). This change completes the advanced fields used by the current recipe, BOM and cost-block editors:

- Recipe: name, active status, document/recipe numbers, revision, review date, notes, overhead; ingredient purchase-pack cost override and applicable order types.
- BOM: number, name, version, status, effective date and notes; material line notes.
- Cost blocks: rate basis, manual/house-book source and Making classification. Linking uses the existing writer and audit; an already linked rate remains protected. Changing a linked material/unit requires unlinking first.
- English-only explanatory labels in the changed product tabs; Urdu product data and print layouts are retained.

Advanced controls are collapsed by default, keep their submitted values while collapsed and open on errors. Section permissions, plan gates and product-screen switch still apply. A changed section uses the existing writer in the product transaction; unchanged sections write nothing. Advanced fields now participate in stale-edit detection.

This does not remove old routes, specialized-role access, other recipe/BOM versions, production actions, reports or the separate house-rate application workflow. No historical transaction conversion, migration, tenant switch or role grant is included. A page opened before this change may require reloading before a changed section can be saved because its fingerprint now covers additional fields.

## Verification record

Local tests use `pos_test_master_pp28`, `pos_test_tenant_pp28`, a separate `_pp28reg` master/tenant pair, and their isolated test-store suffixes. No production credentials were copied. Vendor is a worktree-local copy, not a shared junction.

- PHP syntax and Blade compilation passed.
- Initial ProductWorkspace run: **17 passed, 222 assertions**, 13m 08s.
- Related run (ProductWorkspace, ProductNavCleanup, CateringSimplify): **28 passed, 610 assertions**, 40m 48s. This includes switch-OFF behavior, specialized roles, all retained old URLs, the existing catering price rules and atomic save/stale-edit checks.
- Browser uses its own `_pp28_ws` store; the related tests used `_pp28reg`, so browser writes could not affect their fixtures.
- First browser attempt timed out at the local login before any check ran. With a longer navigation timeout, 15/16 checks passed before an immediate post-resize BOM width check failed. Direct phone loading and a separate resize probe both measured 390/390; the script now waits for layout to settle and records geometry.
- A subsequent 28/28 browser run passed, but visual inspection found an untested issue: inherited `white-space: nowrap` clipped Cost Block help and widened desktop columns. Absolutely positioned accessibility labels inside the scrolling table could also enlarge the document. The final CSS allows row text to wrap and anchors those labels within the scroll container. Explicit desktop width and expanded-help clipping checks were added.
- **Final browser run: 34/34 passed**, no JavaScript page errors, desktop 1366x768 and phone 390x844. All three phone documents measured exactly 390px wide. Recipe/BOM note edits were posted through the real pages and read back from the isolated database; the recipe preview handled a zero override plus overhead, and house-book readonly/manual switching worked. All six final screenshots were visually inspected. After the backend regression, application changes were limited to three CSS lines and a Blade comment; the final browser run covers that layout fix.
- No full application suite or backend sabotage batch was run for this bounded form change. No financial, inventory or shared writer service code changed.

Evidence logs are in `storage/logs/product-parity-focused.*`, `product-parity-related.*`, `product-parity-browser-final.log` and `product-parity-browser/`. The final browser script is committed under `docs/reviews/`.

Visual review: [desktop and phone screenshots](../reviews/product-parity-review-2026-10-29.html).

Production moved independently to `bc9c973c` during this work (Move Table / report network bridge release). Those changes have not been overwritten. A later release must integrate the then-current production revision and verify the combined result.

## How to use

1. Open the existing product and choose Recipe, Bill of materials or Event costing.
2. Recipe/BOM references, status and notes are under **Advanced settings**. Recipe line overrides and order-type selections are under **Ingredient settings**; each BOM component has a **Material note**.
3. A blank recipe cost override uses the purchase cost; `0` explicitly means zero cost. No order type selected means all order types.
4. A Cost Block's **Pricing settings** select its rate basis, source and Making role. A linked material rate is readonly. To type a rate, choose Manual rate; to follow the house book, select that source and save. The existing book validation and audit still apply. Later book-rate changes are applied through the rate workflow, not silently on product Save.
5. Save once using the product's Save button. Validation errors keep the edit on the form. If someone changed the same section elsewhere, reload/review the current section before saving again.

## Map and release boundary

Product edit/create routes -> ProductWorkspace access/fingerprint/apply -> existing RecipeWriter, BomWriter, CateringCostBlockWriter and CateringProfileWriter -> existing product transaction. No new route, table, permission, module, feature flag or migration.

New controls appear wherever `product_screen_v2` is already on and the section is permitted by business, plan and role. Standalone screens and their save services remain available. There is no data backfill and no script updating live product records. Specialized-role access, archived/other versions, report actions and rate application remain separate.

## Separate completed security restriction

On 28 October, the public MySQL TCP 3306 UFW allow rules were removed for IPv4 and IPv6. Incoming traffic remains default-deny; SSH and Nginx rules remain. Firewall backups are at `/root/firewall-backups/product-parity-20261028/` with owner-only directory access.

Before restricting access, all 13 tenant database hosts and the app/backup connection paths were confirmed local. After the change:

- External TCP 3306 connection timed out.
- A fresh SSH connection and local SQL query succeeded.
- All 13 tenants' configured local credentials passed `SELECT 1` in a repeat check.
- Nginx, MySQL and the queue service remained active; Khatri and Kashif Kitchen login pages returned HTTP 200.

No MySQL users, passwords, tenant records or application switches were changed; no database restart or application deploy was performed for this restriction. Remote database administration now needs an SSH tunnel. The prior firewall configuration is backed up, but reopening MySQL publicly is not the normal recovery path.
