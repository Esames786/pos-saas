# Integration candidate — unified-business W2 + supplier-bill completion (22 Oct 2026)

An **isolated integration branch**, `integration/unified-w2-20261022`. It is not a merge into the release branch;
nothing is merged, deployed or activated. It exists to prove that the separately verified changes work together.

## Included, exactly

| Merge | Branch | Head | What |
|---|---|---|---|
| `f4a5c914` | `fix/reset-supplier-bill-tables-20261022` | `2f001380` (`c09d4f8d`, `f90cdd62`, `2f001380`) | reset wipes the two supplier-invoice tables; fail closed; Edge tables kept; Branch Server refusal tested; RESET-BALANCES-1 recorded |
| `ad736b6c` | `feat/unified-w2-slice-a-evidence-20261022` | `cb27c9e4` (on candidate `df524466`) | kg ↔ g by rule; one plain stock for no-variant and default-variant rows; journeys + stock-path evidence |
| `3ef30735` | `feat/w2b-quantity-rules-20261022` | `0831cd41` | quantity rules on POS sale, hold, split and bill-linked return |

Base: `5441ead2`, the unified head, which already contains production `59787ff6` and every supplier-bill commit.

On top, on this branch only:
- `tests/MySql/W2CombinedMySqlTest.php` — the parts together:
  1. a gram recipe sells whole plates from kg stock spread across both kinds of row;
  2. loose kg across both rows keeps 3 decimals, and returns say what is left;
  3. strips sell from tablets on two batches and two rows by expiry, and come back whole;
  4. a tenant set up like the live ones posts new lines while old lines and old stock-book rows stay byte-identical.

  Three sabotages, one per part, are each red.
- `docs/plans/w2-quantity-entry-points-2026-10-22.md` — where the quantity rules apply and where they do not yet.
  This is **not** "one quantity rule everywhere".
- System map rebuilt.

## Scope boundary (reviewer, 22 Oct)

**In:**
- standard-unit conversion;
- shared no-variant / default-variant stock, with genuine variants preserved;
- server-side quantity and return validation on the paths listed;
- the reset fix.

**Out:**
- decimal packs;
- gram entry at the counter;
- pack scanning on the normal POS;
- report changes;
- the other quantity entry points (see the map);
- RESET-BALANCES-1.

**No live reset. No tenant-data correction.**
