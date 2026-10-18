# Bingoo POS SaaS — rules for every session

## 1. System map first (owner's standing order, 2026-10-19)

Before planning or changing anything, look at the **system map** — the bird's-eye view of the whole system:

- Open `docs/system-map/index.html` (no server needed) or the published copy
  https://claude.ai/artifact/3aVXbRq9PAEoWn1ivwMnKs
- It shows every table, its foreign keys and `_id` links, which routes/screens touch it, every worktree's
  work against the production branch, and the newest plans in `docs/plans/`.
- Every plan must list its **impact** from the map (tables, screens, tests) before any code is written.

**Keep it current.** After any change to migrations, routes, models, controllers or services — and at the
end of any piece of work — rebuild it and commit the result with that work:

```bash
php tools/system-map/build.php --tenant-db=<a freshly migrated tenant TEST db> --master-db=<the master TEST db>
# e.g. after a MySQL test run: --tenant-db=pos_test_tenant_xxx --master-db=pos_test_master_xxx
```

It writes `docs/system-map/system-map.json` and `docs/system-map/index.html`. It only reads; never point it at
production. If you can republish the artifact link above, do so; otherwise the committed `index.html` is the
source of truth.

## 2. Where things are

- Production branch: `feat/14d-2-plan-upgrade-requests` (not `main`). The checkout at `D:/laragon2/www/pos-saas`
  is production's copy — do new work in its own worktree and branch, merge back with `Merge <NAME>: …`.
- Plans and decisions: `docs/plans/<topic>-<date>.md`.
