# Edge cashier browser proof (W7)

JS-executing proof of the Edge cashier page in a real browser (Playwright driving the locally installed Microsoft Edge or
Chrome — nothing is downloaded, nothing is installed system-wide). It exists because no PHP test executes JavaScript and
every Edge control is JS-rendered (audit E-15, 20 Sep 2026).

```
cd tools/edge-browser-proof
npm install                       # once (package only; uses channel "msedge" / "chrome")
set EDGE_PROOF_PASS_ENV=EDGE_LAB_CASHIER_PASS
set EDGE_LAB_CASHIER_PASS=…       # never typed on a command line that is logged
node edge-pos-proof.mjs --base-url https://desktop-0024epm.local:8443 --user LAB2C5D --ignore-tls
```

Output: `evidence/<timestamp>/pos-main-<viewport>.png`, `dialog-<name>.png`, `report.json` (DOM census with
denominators, viewport overflow, console errors). The script never completes a sale, never prints, never touches a
live tenant. Run it against the LAB appliance (after an owner-approved signed update) or a dev Edge instance only.

The census rows come from `tests/Fixtures/edge/online-pos-control-census.json`; the PHP gate
`EdgeCashierControlCensusHttpMySqlTest` proves the same rows against the server-rendered page — this script proves them
against the live DOM, dialog by dialog.
