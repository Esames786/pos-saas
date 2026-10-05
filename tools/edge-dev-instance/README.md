# Dev Edge instance (W7 target for browser proofs; W1–W5 development target)

Serves THIS worktree as a `branch_server` on `http://127.0.0.1:8095` against a disposable, production-shaped LOCAL database.
It exists so that cashier-parity work can be browser-proven without touching the P5C LAB appliance (which is updated only by
an owner-approved signed release) or any tenant.

```
tools/edge-dev-instance/seed.sh      # (re)creates bingoo_edge_devtest_local: real tenant+edge migrations + production-shaped menu
tools/edge-dev-instance/serve.sh     # php artisan serve --env=edgedev on 127.0.0.1:8095 (creates .env.edgedev with a fresh dev key)
tools/edge-dev-instance/serve.sh status | stop
```

Seeded operators (DEV ONLY — these credentials exist nowhere else):

| employee code | role | credential | notes |
|---|---|---|---|
| `DEVCASH1` | cashier, pinned to Counter 1, blind count | `CashierPass1` | Online cashier permission set + returns + quick report |
| `DEVMGR1` | manager | `MgrPass1` | + void/approve (`tenant.pos.void-kot-item`), change terminal, view amounts, supplier finance, journal, purchase returns |

Menu shape: parent/child categories, plain items, a weighted item (kg), variant items (Half/Full, Small/Large) with barcodes,
modifier groups (required single-select spice level; optional multi-select extras with a linked product), two deals, tea/naan
sundries; two floors of tables, three waiters, void reasons (one needing approval), own + aggregator delivery channels with
riders, three customers with saved addresses, a network KOT printer and a network receipt printer pointed at the LAB
FakePrinter (127.0.0.1:9100), cash + card + bank-transfer payment methods, two terminals. Stock baseline accepted for everything.

Then run the browser proof: `cd tools/edge-browser-proof && set EDGE_PROOF_PASS_ENV=EDGE_DEV_CASHIER_PASS && set EDGE_DEV_CASHIER_PASS=CashierPass1 && node edge-pos-proof.mjs --base-url http://127.0.0.1:8095 --user DEVCASH1`.

Safety: the seed refuses any database other than `bingoo_edge_devtest_local`; the runtime refuses non-`bingoo_edge_*` names
(EdgeLocalDatabase); no Cloud pairing, no outbox sender, no print worker run — sales made here stay local and are disposable.

## Second instance "edgeclone" — PAIRED to the dev Cloud clone (same dataset as Online) — `serve-clone.sh`

Phase 3 (4 Oct 2026, Team Q): the dev instance above carries a dev-seeded menu while the disposable Online clone
(`Start-DevCloud.ps1 -Port 9704 -MasterDb pos_devonline_master_edge`, tenant `edgehomelab.localhost`) carries the LAB menu, so
paired Edge-vs-Online geometry/pixel comparisons showed dataset-driven differences. `serve-clone.sh` runs a SECOND instance of
THIS worktree on `http://127.0.0.1:8096` (DB `bingoo_edge_devclone_local`, env `.env.edgeclone`, git-ignored) that is paired to
the clone Cloud through the PRODUCT flow and bootstrapped from it, so both runtimes render the identical dataset.
Report: `docs/status/edge-phase3-samedata-comparison.md`.

```
tools/edge-dev-instance/serve-clone.sh init                      # .env.edgeclone: fresh key, EDGE_CLOUD_ALLOW_HTTP, enrolment PUBLIC key (secrets\enroll.public)
tools/edge-dev-instance/serve-clone.sh artisan edge:local:db-init
# clone Cloud side (C:\Users\Dell\BingooEdgeLab\scripts\devclone-cloud.php — never the LAB DBs):
#   php devclone-cloud.php revoke-device <inherited LAB device uuid>   (a cloned tenant carries the LAB device → DEVICE_CONFLICT otherwise)
#   php devclone-cloud.php pair-code <codefile>                        (15-minute one-time code, written to a file, never printed)
tools/edge-dev-instance/serve-clone.sh artisan edge:local:pair --cloud-url=http://localhost:9704 --code-file=<codefile> --env-file=.env.edgeclone --device-name=team-q-devclone-8096
tools/edge-dev-instance/serve-clone.sh artisan edge:local:bootstrap-pull
#   php devclone-cloud.php branch-mode cloud                           (the pairing saga flips branches.sales_operating_mode to local_edge while pending)
#   php devclone-cloud.php assertion <file> 1 ; serve-clone.sh artisan edge:local:enroll <file> --credential-file=<tmp copy of secrets\devclone-cashier.pass>
tools/edge-dev-instance/serve-clone.sh start | stop | status
tools/edge-dev-instance/serve-clone.sh worker start | stop            # authority heartbeat worker — renews the CLONE's branch lease;
#   after `worker stop`: php devclone-cloud.php release-lease "<reason>"  or the clone Cloud fences itself (409 BRANCH_LOCAL_EDGE_ACTIVE) at lease expiry
```

Operators on the clone instance are the clone tenant's own users (`LAB2C5D` cashier, `LABMF2DE` approver) with Edge credentials
set by `edge:local:enroll` — they live ONLY in `C:\Users\Dell\BingooEdgeLab\secrets\devclone-cashier.pass` / `devclone-approver.pass`
(read into `POS_SHOT_PASS` from the file, never typed). The pairing code / assertions / credential files are one-time and consumed.
The clone Cloud's central domain is `localhost` (`http://localhost:9704/api/edge/...`); the tenant UI is `http://edgehomelab.localhost:9704`.
The instance is a STANDBY appliance (mutations refused; the status slot reads STANDBY) — exactly what render comparisons need.

Same-dataset comparison (geometry + pixels) — see the report for the exact commands and the evidence under
`C:\Users\Dell\BingooEdgeLab\evidence\phase3\geometry-samedata` and `pixel-samedata`.
