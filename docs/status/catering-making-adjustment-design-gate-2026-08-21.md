# Making Adjustment (Phase E) — stopped at the design gate, 21 Aug 2026

**Status: NOT BUILT — deliberately.** The operator-completion sprint was told to
implement bulk Making adjustment only if "Making" can be identified without
fuzzy label text, and to stop this subpart otherwise. It stops here.

## What the domain actually has

A charge block is `catering_product_cost_blocks` with `block_type = 'charge'`,
a free-text `label`, and a `charge_basis` (`per_unit` | `lump_sum`). That is the
whole taxonomy. On Kashif's live data the same shape carries *Making*,
*Packing*, *Waiter*, *Decoration* and *Live Counter Setup*, distinguished by
nothing but what somebody typed in the label box.

A bulk adjustment keyed on `label LIKE '%making%'` would:

- miss `Mkg`, `Making Charges`, Urdu labels, and every future spelling;
- hit a hypothetical "Cake Making Kit" material-adjacent charge it must not touch;
- silently change money on whichever side of that guess is wrong.

That is exactly the class of defect the rest of the costing design exists to
prevent, so label-matching is not an acceptable identity.

## Minimum additive design (for a later tranche)

1. **`charge_role` column** on `catering_product_cost_blocks` *and* the
   line snapshot table: nullable string enum, initially just `'making'`.
   Additive migration, default NULL, no backfill — existing blocks stay
   unclassified until a person says otherwise.
2. **Operator classification, not migration guesswork:** the Cost Block edit
   screen offers "This charge is the Making charge" (one per dish enforced
   server-side). Snapshotting copies the role onto estimate-line blocks the
   same way `commercial_rate_source` is copied today.
3. **Adjustment flow reuses the Rate Impact architecture verbatim:**
   preview (affected dishes, current → new Making, old → new Calculated Rate,
   difference; eligible draft snapshots) → selective apply to products /
   drafts → Create-Revision-&-Apply for Sent → Final immutable → audited in
   `catering_commercial_rate_applications` with a `making_*` action. No stock,
   no GL, ever.
4. **Estimated effort** once approved: one migration, one screen control, one
   service that is mostly `CateringCommercialRateImpactService` with the
   material-rate lookup swapped for the role lookup, and its test file.

Nothing in the current release blocks on this; dishes' Making charges remain
individually editable per dish exactly as before.
