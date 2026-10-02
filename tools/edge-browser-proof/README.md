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
```

Output: `C:\Users\Dell\BingooEdgeLab\evidence\phase2\step7-workflows\<mode>\<Wnn>-<workflow>-<step>.png` + `report.json`.
It CREATES disposable sales, held orders, table sessions, a return and shifts — run it only against the dev Edge instance
(`bingoo_edge_devtest_local`) and a disposable dev Cloud clone; non-loopback hosts are refused. Report:
`docs/status/edge-w-g2-workflow-proof-report.md`.
