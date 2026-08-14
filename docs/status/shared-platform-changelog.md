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

> Note: a 4th feature worktree now exists — `pos-saas-cloud` @ `feat/cloud-billing-onboarding-v1` (CLOUD-BILLING sprint, Phase 1A pushed `464ab66`, not deployed). Platform fixes are cherry-picked there too (`661cf0e` → `9f88579`). Catering has moved to `release/catering-go-live-2`.

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

Prod (`bingoopos.com`, Hostinger) is on `feat/14d-2` at the latest shared commit above. Prod stays
**cloud**, Edge/Local Mode inactive, `activation_ready=false`.
