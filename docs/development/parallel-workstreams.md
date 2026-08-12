# Parallel Development Workstreams

As of **2026-08-13** the platform is developed in **two independent Git worktrees** plus the
original integration repository. This exists so two concurrent development sessions never
run `git checkout` in the same directory and clobber each other's working tree, `.env`,
`vendor`, test databases, or Edge operational data.

## Layout

| Directory | Branch | Role |
|---|---|---|
| `pos-saas` | `feat/14d-2-plan-upgrade-requests` | Integration / reference / hotfix coordination / history. **No day-to-day feature work.** |
| `pos-saas-edge` | `feat/edge-config-refresh-v1` | **Session A — Edge.** Next: `EDGE-CONFIG-REFRESH-1` → `EDGE-COMPATIBILITY-CONTRACT-1` → `OFFLINE-SYNC-ENGINE-1`. |
| `pos-saas-catering` | `feat/catering-events-v1` | **Session B — Catering.** Next: `BINGOO-CATERING-PREFLIGHT-1` (Cloud V1). |

Common ancestor: the 2026-08-13 documentation checkpoint commit (code = `c4fc021`).
The two feature branches are **siblings**, not stacked.

## Every session MUST start by verifying where it is

```
pwd
git branch --show-current
git status --short
git rev-parse --short HEAD
```

Expected:

- Edge session → `.../pos-saas-edge` on `feat/edge-config-refresh-v1`
- Catering session → `.../pos-saas-catering` on `feat/catering-events-v1`

**If the path or branch is wrong: STOP.** Do not `git checkout` the other workstream's
branch in a shared directory — switch to the correct worktree directory instead.

## Isolation rules

- Each worktree has its **own** `.env`, `vendor/`, `node_modules/`, and test databases.
  Never point one worktree at another's database or at production.
- Edge operational/local MariaDB, bootstrap artifacts, and binding metadata live **only**
  in `pos-saas-edge`. Catering must never reuse an Edge operational DB.
- Do not run `composer update` / `npm upgrade` during normal feature work — install from
  the committed lock files.

## Shared-core change policy

This is a mature production platform. The following are **shared authorities** — changing
their semantics is a platform-level act and requires regression proof that existing (and
Catering-disabled) tenants behave identically:

`InventoryService`, `SalesService`, Journal/GL posting, return/refund accounting, payment
posting, shift closing, tenant activation/context, `BranchOperatingModeService`, `PrintJob`
physical authority, permission middleware.

Preferred Catering architecture: **a new Catering service that calls the stable shared
service** — not catering `if/else` branches sprinkled through stable POS controllers.

## Hotfix policy

A real production POS bug is fixed **once**, on a dedicated `hotfix/<issue>` branch cut from
the current stable platform checkpoint, tested and deployed, then the **same reviewed
commit** is cherry-picked into both feature branches. Never fix the same core bug twice
independently.

## No routine cross-branch merges

No daily `edge → catering` or `catering → edge` whole-branch merges. Only reviewed,
genuinely platform-level commits cross workstreams. Keep feature history independent until
integration.
