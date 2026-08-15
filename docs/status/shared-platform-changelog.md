# Shared Platform Changelog (post branch-split)

Running log of **platform-level changes made after the 2026-08-13 worktree split** and shared to
**all three branches** so every session knows what is already in its tree.

- Integration/reference: `pos-saas` @ `feat/14d-2-plan-upgrade-requests`
- Edge session: `pos-saas-edge` @ `feat/edge-config-refresh-v1`
- Catering session: `pos-saas-catering` @ `feat/catering-events-v1`

**Rule (from `docs/development/parallel-workstreams.md`):** a genuinely platform-level fix is committed
on `feat/14d-2`, deployed, then **cherry-picked into both feature branches** (same reviewed commit).
Feature-specific work stays on its own branch. This file records the cherry-picked platform commits.

> When you finish a platform-level change on any branch: cherry-pick it to the other two and **add a row
> here** (then cherry-pick this file too), so no session is surprised by a change it didn't make.

## Shared commits since the split

| Date | What | `feat/14d-2` | edge | catering | Prod? |
|---|---|---|---|---|---|
| 08-13 | Fix 500 on 4 finance statement pages (Blade `@endforeach` compile bug) | `221aba9` | `247adb6` | `8d87db9` | ✅ deployed |
| 08-13 | POS: stop network/reprint buttons printing the previous customer's bill (`_lastSaleId` lock) | `c8ac1b8` | `cabc0a4` | `c6b519e` | ✅ deployed |
| 08-14 | Receipt preview: stop Qty/Rate/Amount colliding (column widths only, font untouched) | `73e6f7a` | `d37a1a7` | `653292c` | ✅ deployed |
| 08-14 | Fix 403 on cached unnamed routes (root `/`) + exempt `tenant.api.server-time` poll | `0facf21` | `6a691ba` | `626fa82` | ✅ deployed |
| 08-14 | POS: vehicle# on takeaway, delivery-charge restored on recall, cancel-KOT heading at category size (no other font/size touched) | `661cf0e` | `259c8cf` | `0a135ce` | ✅ deployed |
| 08-14 | POS Hold: a held delivery order now saves its delivery charge (was dropped to 0) + takeaway vehicle#; recall list re-exposes both so a recalled order comes back whole (guard test) | `67efbc1` | `59de856` | `3eb2a1b` | ✅ deployed |

> Note: a 4th feature worktree now exists — `pos-saas-cloud` @ `feat/cloud-billing-onboarding-v1` (CLOUD-BILLING sprint, Phase 1A pushed `464ab66`, not deployed). Platform fixes are cherry-picked there too (`661cf0e` → `9f88579`, `67efbc1` → `8cea1e1`). Catering has moved to `release/catering-go-live-2`.

## CATERING-GO-LIVE-2 — canonical integration (2026-08-14)

- **Certified candidate**: `release/catering-go-live-2` @ `acd313c` (gates: MySQL 340/2073, Catering
  43/395, fast 186/31856, zero skips/exclusions). Platform then advanced with the two POS fixes above;
  the candidate was reconciled to `3eb2a1b` (both cherry-picks patch-id-identical to `661cf0e`/`67efbc1`).
- **Canonical integration merge**: `3eb2a1b` merged into `feat/14d-2-plan-upgrade-requests` → **`1e07356`**.
  Tree integrity proven: Catering content byte-identical to the certified candidate (only this changelog
  differs); the newer shared POS fixes are byte-identical to `adae153`'s (nothing reverted).
- **Re-certified on the merge tree**: complete MySQL **344/2087 OK (zero skips/exclusions)**, Catering
  **43/395 OK**, fast **186/31856 OK**, lint/Pint/`git diff --check`/route:cache/config:cache/view:cache
  all clean.
- **Entitlement contract**: Catering is globally REGISTERED but entitlement-gated —
  `MasterSeeder::ROLLOUT_GATED_MODULE_KEYS` keeps every public/Enterprise plan catering-free on deploy;
  proven on a fresh-provision tenant AND a real pre-Catering tenant snapshot upgrade (data intact,
  fail-closed). First client: private plan `catering-client-1` grant procedure in
  `docs/releases/catering-go-live-2.md` (rehearsed locally on cateringdemo).
- **Thermal printing remains ENGLISH-ONLY** (no Urdu thermal certification claimed); Urdu/bilingual is
  A4/browser only.
- **Production deployment HEAD**: _pending — to be recorded at deploy time (deploy.sh, exact `1e07356`)._

## Docs shared (not code)

- `docs/status/platform-checkpoint-2026-08-13.md` — authoritative state at the split.
- `docs/development/parallel-workstreams.md` — per-session start guard + shared-core + hotfix policy.
- `docs/plans/cloud-onboarding-billing-email-2026-08-14.md` — research + plan for self-signup / billing /
  payment / email (scheduled for next week; **not started**).

## What's NOT done yet (planned)

- **Self-signup billing gaps** (see the plan doc): yearly-plan selection is cosmetic only; no payment
  account details shown to customers; no invoice auto-created at signup; email is `MAIL_MAILER=log`
  (nothing sent). Owner-confirmed decisions and phased plan are in
  `docs/plans/cloud-onboarding-billing-email-2026-08-14.md`.
- **Edge next:** `EDGE-CONFIG-REFRESH-1` (Edge session).
- **Catering next:** `BINGOO-CATERING-PREFLIGHT-1` (Catering session).

## Production HEAD

Prod (`bingoopos.com`, Hostinger) is on `feat/14d-2` at `67efbc1` (Hold delivery-charge/vehicle recall fix). Prod stays
**cloud**, Edge/Local Mode inactive, `activation_ready=false`.

## PLATFORM-ENTITLEMENT-BOUNDARY-1 (2026-08-15)

| Date | What | `feat/14d-2` | edge | catering | Prod? |
|---|---|---|---|---|---|
| 08-15 | Dashboard is always-allowed (was owned by the `reports` module, so restricted plans hit "Module Not Available" on login); sidebar module gates on Sales/Reports; Printing split so KOT Routing + Layouts require pos\|restaurant while Printers/Jobs/Agents stay shared; Customers + Payment Methods reachable from POS **or** Catering (route-enforced, not menu-only); compiled-Blade PHP lint gate | `1da0bc4` | _pending cherry-pick_ | _pending cherry-pick_ | ✅ deployed `51367cc` |

**Why it matters beyond Catering:** `deploy.sh` grants the Owner every `tenant.*`
permission regardless of plan, so `@can` alone was never an entitlement decision.
Any sidebar section without a module gate leaked on *every* restricted plan.

**New rule — `view:cache` is NOT proof.** It compiles Blade to PHP but never
validates the PHP it emits; it reported success on two views that could not
parse and 500'd in production. `tests/Unit/CompiledBladeSyntaxTest` now compiles
all views and runs `php -l` on the generated PHP. Treat that gate — not
`view:cache` — as the GREEN signal.

**Deliberately NOT changed:** ERP Extensions keeps its plan-code allowlist.
Gating it on the `erp_extensions` module would strip the menu from `standard`
and `finance_erp` plans, which have no module row. It already fails closed for
Catering-only tenants.

**Follow-up (not in this sprint):** `PLATFORM-OWNER-ENTITLEMENT-PERMISSIONS-1` —
make Owner permission sync entitlement-aware instead of granting all `tenant.*`.
