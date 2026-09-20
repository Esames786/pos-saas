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
