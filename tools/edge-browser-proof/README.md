# Edge browser proofs (shared cashier view, both runtimes)

Playwright drives the locally installed Microsoft Edge (channel `msedge`; nothing downloaded, nothing installed system-wide) against
LOOPBACK dev instances only — never a live tenant, never the LAB appliance except after an owner-approved signed update. No PHP test
executes JavaScript, and every cashier control is JS-rendered, so these scripts are the browser half of the gates. Since Phase 3 the
ONE cashier view (`resources/views/tenant/pos/index.blade.php`) renders on the Cloud (`/pos`) and on the Branch Server
(`/edge/local/pos`), so every tool here runs in `--mode cloud` and `--mode edge` and the pair is compared.

| Tool | Purpose | Gate field |
|---|---|---|
| `shared-pos-workflows.mjs` | end-to-end cashier workflows W01–W17 on either runtime; verdict = HTTP outcome + DOM confirmation | `EDGE_BROWSER` / `ONLINE_BROWSER` |
| `pos-reference-shots.mjs` | the 12 reference states per viewport (steady-state screenshots) | input to `pixel-diff.php` |
| `pixel-diff.php` | pixel comparison of two shot folders with a status-slot mask | `ONLINE_PIXEL_REGRESSION` |
| `geometry-compare.mjs` | bounding boxes of ~34–50 controls per state on both runtimes, compared at 1 px | `EDGE_VS_ONLINE_GEOMETRY` |

Credentials ONLY through the environment (`POS_SHOT_PASS`, `POS_MGR_PASS`), read from the secret files — never typed on a logged
command line. The scripts block every non-loopback host. The old Edge-only control census script (`edge-pos-proof.mjs`) and its
fixture were retired in Phase 3 Stage B: the shared view is proven by `tests/MySql/EdgeSharedPosRegressionGateMySqlTest.php` +
`tests/Feature/Edge/EdgeSharedPosRegressionStaticGateTest.php` (server side) and by the tools above (browser side).

```
cd tools/edge-browser-proof
npm install                       # once (package only; uses channel "msedge")
```

## G2 — shared cashier view: end-to-end workflow proof, both runtimes (`shared-pos-workflows.mjs`)

Drives the SHARED cashier view (`tenant.pos.index`) through the Online workflows W01–W17 (login, shift open page, cash sale,
customer sale, hold/recall/settle, dine-in table lifecycle incl. KOT rounds / bill preview / bill requested / move, void with
approver, manual discount with approver, returns via the POS iframe + the returns list, reprint, print-job retry, quick report,
reservation, split bill, customer quick-add, shift close page, logout) on EITHER runtime and records PASS/FAIL/SKIP per workflow,
every POS HTTP exchange (method, path, status, `ok/code/message`), console errors, toasts/popups, the runtime contract and every
disabled-with-hint control. Online is the specification; datasets differ, so this is BEHAVIOUR parity, not pixel parity.

```
# credentials ONLY via the environment (read them from the secret files, never type them)
set POS_SHOT_PASS=…    cashier credential      set POS_MGR_PASS=…    approver credential (Edge: DEVMGR1; Online: manager PIN)
node shared-pos-workflows.mjs --mode edge   --base-url http://127.0.0.1:8095 --user DEVCASH1 --manager-user DEVMGR1
node shared-pos-workflows.mjs --mode online --base-url http://edgehomelab.localhost:9704 --user lab.cashier@edgehomelab.test
#   --only W03,W05   --out <dir>   --headed   --settle <ms>   --customer-query <text>
#   --action-timeout <ms> (default 30000)   --nav-timeout <ms> (default 90000)   — the dev servers share ONE MySQL with other
#   teams' phpunit gates / appliance bootstraps; a page took 137 s on 4 Oct 13:29 under that load. Every request's latency is
#   recorded (report.http[].ms / .at), so a slow run stays attributable to the box, not to the product.
```
Run the two suites ONE AFTER THE OTHER (never while `tools/edge-dev-instance/seed.sh` or a MySQL phpunit gate is running): the
04:2x failures of 4 Oct ("page.click: Timeout 20000ms" on the login / shift-open submits) were the Edge re-seed stalling the
shared MySQL while both suites ran in parallel.

Output: `<out>\<Wnn>-<workflow>-<step>.png` + `report.json` (default `…\evidence\phase2\step7-workflows\<mode>`; the Phase 3 gate runs
live in `C:\Users\Dell\BingooEdgeLab\evidence\phase3\proof-final\{edge,online}`).
It CREATES disposable sales, held orders, table sessions, a return and shifts — run it only against the dev Edge instance
(`bingoo_edge_devtest_local`) and a disposable dev Cloud clone; non-loopback hosts are refused. Report:
`docs/status/edge-w-g2-workflow-proof-report.md` (§"Harness v2" = the verdict rules below).

Verdict rules (harness v2, 4 Oct 2026) — every verdict is the actual HTTP outcome PLUS a DOM confirmation, never a heuristic:
- KOT (W06 rounds 1/2, W07): the KOT POST is matched from the runtime's own route template (`kotQueue` → Edge
  `/sales/{sale}/kot`, Online `/printing/jobs/kot/{sale}`), armed BEFORE the Hold click (auto-KOT terminals fire it without a
  prompt) and the fact is read from the response's `jobs` (Edge `{jobs, message, reminder}`, Online `{jobs, reminder}`):
  PASS = 2xx and at least one job.
- W06 settle after the merge: Continue Table on the merged session → "Open Orders Found" → pick the check → Review & Pay →
  cash → complete, repeated while the session lists held checks; PASS when the session tile is gone and the moved-to table
  offers Open Table again.
- W07: the voided line is checked BY PRODUCT NAME (gone), the other line must remain; the re-hold's saved `lines` must equal
  the remaining cart lines (Edge answers `void_print_jobs`, Online `cancel_kot_jobs`).
- W13: after the reserve POST the view re-fetches the board; the harness polls (up to 10 s) for the tile's status chip
  "Reserved" + the `[data-reservation-details]` control, and after the unreserve for Open Table + Reserve again.
- Classic (navigating) forms — W01 login, W02 shift open, W16 shift close — are submitted through `submitForm()`: the button is
  clicked with `noWaitAfter` and the harness then waits for the POST itself (the HTTP fact: status + redirect location + ms, up to
  60 s) and for the page that follows. Playwright's implicit "wait for the navigation" inside `click()` is bounded by the action
  timeout and reported a slow POST as "page.click: Timeout 20000ms exceeded" — a harness artefact, not a product fact.
- W02 is IDEMPOTENT: the verdict reads the shift-status JSON for the page's terminal (HTTP) AND the badge (DOM). A shift already
  open (an earlier run / an operator) is recorded as `facts.shift_preexisting` and PASSES — the view hides the "Open shift" link in
  that state by design (checked), and the separate page is only probed for reachability. Otherwise: badge "No open shift" → link →
  page 200 → POST 302 off the open page → shift-status open=true AND badge "Shift open".
- W14 / W16 submit buttons are located inside the SPLIT form (`form[action*="split-bill"]`) / the CLOSE form
  (`form[action*="/close"]`) — on Online those pages render inside `layouts.app`, whose hidden chrome forms come first in the DOM.
- W16 pre-step "settle THIS RUN's open work" (the shared ShiftService refuses to close while held orders / open tables exist):
  every held order THIS RUN created (`runHeld`: W06 ×2, W07, W08, W14 + its split part) is recalled from the Held list → Review &
  Pay → cash → complete, W07's KOT order is cancelled from the held list (reason + approver prompt), and every table session THIS
  RUN opened (`runSessions`) that is still open with nothing on it is closed from the board. Held orders / sessions the run did
  NOT create are LEFT UNTOUCHED (the Online clone cannot be reset; they are somebody else's work) and reported as
  `facts.w16_open_work.leftovers`; if the close is then refused by the shared rule ONLY because of such leftovers, the verdict is
  FAIL with `classification: PRE-EXISTING-DATA` and the leftovers named — never a crash. Each action is a step with its HTTP
  outcome. Then the close page is submitted (`submitForm`) and the verdict needs shift-status open=false AND badge "No open shift".
  Nothing touches the database directly.
- Authority lease: a 409 `BRANCH_LOCAL_EDGE_ACTIVE` from the clone (its lease held by a paired appliance — another team's run)
  is counted in `facts.authority_fenced` and printed in the final summary (`authority_fenced_409s`): wait 2–3 min and re-run.
