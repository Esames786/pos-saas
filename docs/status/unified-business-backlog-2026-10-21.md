# Unified business — what is left and what is known (21 Oct 2026)

Kept honest on purpose: nothing here is "all green". Status words: implemented / tested / merged / deployed / activated.

## Next, in order (owner, 21 Oct)

1. **Supplier net bill** — SUPPLIER-NET-BILL-1: implemented + tested on the branch (backend: gross, net, paid, return
   before bill, free goods, credit on an open bill moved only with an authorized tick + audit row, credit on a paid bill
   blocked, two-process race; UI: page-render tests + a Chrome walkthrough with screenshots); not merged, not deployed.
   Bills cannot be voided in this system today, so "a netted bill voided" does not apply.
   Review (21 Oct) gaps closed on the branch: GL inside the transaction (failure saves nothing, retry works), partial
   deduction, blocked net invoice no longer pushes a full invoice; purchasing UI in English with one set of terms and
   a "How to use this screen" guide. Open owner decisions: an authorized "reopen a paid invoice" path (not built —
   blocked as the owner ruled); keep or drop the small Urdu-script helper lines. Not done in this step: one
   simple-purchase workspace and a shorter sidebar (W4).
2. **Units / packs completed end to end (W2)** — the release protected units; it did NOT finish the quantity system.
   Still to prove or build, through purchase → stock → counter sale → return → report:
   - box → strip → tablet and kg → gram by one rule everywhere (`StandardUnits` exists, wired nowhere yet);
   - a 1.250 kg counter sale: input, stock out, price, return and report all right — not just "the box takes 1.250";
   - decimal pack sizes for kg / litre items (needs new decimal columns beside the whole-number ones, all readers);
   - legacy lines read by their own meaning (purchase lines hold base units; counter sale lines hold packs).
   The loose-weight counter is NOT released before these.

   **Slice A (22 Oct, implemented + tested on the branch; not merged, not deployed)** —
   `docs/plans/units-packs-w2-slice-a-2026-10-22.md`:
   - kg/g, L/ml, m/cm convert without a row (`UnitConversionService` → `StandardUnits`);
   - the POS no longer reads an unconvertible recipe line in the wrong unit;
   - stock with no variant and on the default variant is one stock for a stock-out (the old vs new GRN screen trap);
   - journeys proven over HTTP in `UnitsAndPacksJourneyMySqlTest`: medicine 200 → 170 → 180, carton 48 → 45 → 46 at
     the carton's own price, loose rice 25 → 24.750 → 24.850, gram recipe on kg stock, a packet with no rule refused,
     mixed old/new stock.

   Production census: nothing on a live tenant changes. Still open:
   - decimal pack sizes;
   - grams typed at the counter;
   - a server rule for parts on counted items (census first);
   - the supplier return reusing the GRN line's own pack;
   - the POS payload's stock figures: the product figure counts only no-variant rows, and each variant only its own
     rows (display; not changed).
3. **W3** one product screen — recipe / catering setup inside the existing form; Add = Edit; hidden fields never reset.
4. **W4** simple purchase — one entry: receipt, the actual bill, optional payment; PO optional; stock never doubled.
5. **W5 → W6/W7** — mart counter; reports / prints; whole demo journeys; then an approved pilot tenant.

## Known reds (not caused by this work, not hidden)

| Test | Since | Status |
|---|---|---|
| `SalesAnalyticsMySqlTest::test_jin_dinon_sale_nahi_hui_wo_bhi_qatar_me_hain` | failed every evening 19–24 UTC | root cause: the fixture used UTC dates while the report (rightly) uses the shop's business day. Fixture fixed to the same clock + a regression test pinned at 21:30 UTC — `b38ce2f5` on the branch: 17/17 at 07:45 UTC, and putting `now()` back turns the pinned test red. Not merged. |
| `Unit\CateringClientFeedbackUiRegressionTest::test_the_event_screen_starts_with_the_sidebar_collapsed` | before 21 Oct | fails the same on production `025728d8`; not investigated |
| `Unit\Printing\EscPosReportPayloadTest::test_it_renders_the_requested_sections_with_two_decimal_money` | before 21 Oct | same |
| `Unit\Tenant\DeliveryRiderReassignmentRegressionTest::test_reassignment_is_routed_guarded_audited_and_visible` | before 21 Oct | same |

## Known data differences (read-only; NO adjustment or repair approved)

Cash / bank stored balance vs opening + its own movements (`ub_reconcile.php`, 21 Oct, unchanged by the release):
kashiffood CASH-MAIN 18,840 · kashifkitchen CASH-MAIN 502,500 · khatribiryani CASH-MAIN 350. A separate READ-ONLY
investigation may explain them; any correction needs its own approval.

Other open owner items: khatribiryani item #60 is 2 units low (old GRN void); branch tax answers; Master password;
the published system-map link no longer opens (the committed `docs/system-map/index.html` is current).
