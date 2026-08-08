<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE-LOCAL-POS-1 (H10) — EDGE-ONLY operational stock persistence.
 *
 * Lives in database/migrations/edge (NOT database/migrations/tenant), so Cloud tenant databases NEVER
 * receive these tables; only the appliance's guarded `edge:local:db-init` applies them to the Edge-local DB.
 *
 * Design (locked): the Branch Server's selling stock is OPERATIONAL QUANTITY ONLY, held in dedicated tables —
 * never the official Cloud `stock_balances`/`stock_ledgers`, whose schema carries valuation (`average_cost`,
 * `unit_cost`, `total_cost`, FEFO batches). Recording "cost unknown/not authoritative" as an official 0 cost
 * would corrupt the official ledger's meaning; these tables therefore carry NO valuation columns at all.
 * Cloud recomputes authoritative FEFO/COGS when local sales sync.
 *
 *   - edge_operational_stock_baselines — the ACCEPTED selling-stock authority for one appliance generation.
 *     Bound to branch/device/activation_epoch + generation/revision + content hash. Ordinary bootstrap does
 *     NOT create selling authority; a baseline must be explicitly accepted, and it can NOT be replaced while
 *     unsynced local operational activity exists (replacement fence — prevents a newer snapshot from erasing
 *     already-consumed quantity and overselling). B1→B2 cutover belongs to the future sync/reconciliation.
 *   - edge_operational_stock_balances  — quantity per product/variant under the accepted baseline
 *     (balance_key = "{baseline_id}-{product_id}-{variant_id|0}", unique — same convention as the official
 *     stock_balances key so nullable variants cannot create duplicate rows).
 *   - edge_operational_stock_movements — append-only movement log. Sale relationships use CANONICAL
 *     identities (sale_uuid / line_uuid), never cross-system numeric ids, so future sync/audit can resolve
 *     them on Cloud regardless of divergent primary keys.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        // AE12 safe-retry: an interrupted run must be re-runnable (Laravel records the migration only on
        // completion, so each create is guarded).
        if (! Schema::connection($this->connection)->hasTable('edge_operational_stock_baselines')) {
            $this->createBaselines();
        }
        if (! Schema::connection($this->connection)->hasTable('edge_operational_stock_balances')) {
            $this->createBalances();
        }
        if (! Schema::connection($this->connection)->hasTable('edge_operational_stock_movements')) {
            $this->createMovements();
        }
    }

    private function createBaselines(): void
    {
        Schema::connection($this->connection)->create('edge_operational_stock_baselines', function (Blueprint $table) {
            $table->id();
            $table->char('baseline_uuid', 26)->unique();            // immutable baseline identity (ULID)
            $table->unsignedBigInteger('branch_id');
            $table->string('device_uuid', 64);
            $table->unsignedBigInteger('activation_epoch');
            $table->unsignedInteger('generation')->default(1);      // baseline generation under this epoch
            $table->string('source_revision', 128)->nullable();     // config revision the snapshot came from
            $table->string('content_hash', 128);                    // hash of the baseline payload
            $table->string('status', 20)->default('accepted');
            $table->timestamp('accepted_at');
            $table->timestamps();

            $table->index(['branch_id', 'activation_epoch'], 'eosb_branch_epoch_idx');
        });

    }

    private function createBalances(): void
    {
        Schema::connection($this->connection)->create('edge_operational_stock_balances', function (Blueprint $table) {
            $table->id();
            $table->string('balance_key', 64)->unique();            // "{baseline_id}-{product_id}-{variant|0}"
            $table->foreignId('baseline_id')->constrained('edge_operational_stock_baselines')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->decimal('quantity_on_hand', 14, 3)->default(0); // OPERATIONAL quantity — no valuation, ever
            $table->timestamps();

            $table->index(['baseline_id', 'product_id'], 'eosbal_baseline_product_idx');
        });

    }

    private function createMovements(): void
    {
        Schema::connection($this->connection)->create('edge_operational_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->char('movement_uuid', 26)->unique();            // immutable movement identity (ULID)
            $table->foreignId('baseline_id')->constrained('edge_operational_stock_baselines')->cascadeOnDelete();
            $table->char('sale_uuid', 26)->nullable();              // canonical sale identity (cross-system)
            $table->char('line_uuid', 26)->nullable();              // canonical originating sale-line identity
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->string('movement_type', 40);                    // sale / recipe_consumption / modifier_consumption
            $table->string('direction', 10);                        // out (baseline import seeds balances directly)
            $table->decimal('quantity', 14, 3);
            $table->decimal('balance_after', 14, 3);
            $table->unsignedBigInteger('activation_epoch');
            $table->timestamps();

            $table->index(['sale_uuid'], 'eosm_sale_uuid_idx');
            $table->index(['baseline_id', 'product_id'], 'eosm_baseline_product_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('edge_operational_stock_movements');
        Schema::connection($this->connection)->dropIfExists('edge_operational_stock_balances');
        Schema::connection($this->connection)->dropIfExists('edge_operational_stock_baselines');
    }
};
