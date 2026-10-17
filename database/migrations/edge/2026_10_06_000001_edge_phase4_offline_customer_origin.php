<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE PHASE 4 — OFFLINE ADD CUSTOMER: EDGE-ONLY columns (database/migrations/edge; never a Cloud tenant DB).
 *
 *   customers.edge_origin / edge_created_at          'cloud' (synced from the Cloud book) | 'local' (added at this till and
 *                                                    not yet seen back from the Cloud). The refresh applier NEVER deactivates
 *                                                    or overwrites a 'local' row that the Cloud export does not carry; the sale
 *                                                    envelope carries a 'local' customer as a `new_customer` intent.
 *   customer_addresses.edge_origin                   same marker for an address typed at the till.
 *   edge_local_meta.cloud_capabilities_seen (+_at)   what the Cloud advertised on the last acknowledged heartbeat
 *                                                    (`capabilities`, JSON). The appliance emits the `new_customer` envelope
 *                                                    kind ONLY while `customer_create` is advertised true; absent = false.
 *
 * Additive, idempotent (hasColumn guards) — safe for the forward-only appliance schema upgrader.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasColumn('customers', 'edge_origin')) {
            $schema->table('customers', function (Blueprint $t) {
                $t->string('edge_origin', 10)->default('cloud')->after('status');
                $t->timestamp('edge_created_at')->nullable()->after('edge_origin');
            });
        }
        if (! $schema->hasColumn('customer_addresses', 'edge_origin')) {
            $schema->table('customer_addresses', function (Blueprint $t) {
                $t->string('edge_origin', 10)->default('cloud')->after('is_default');
            });
        }
        if (! $schema->hasColumn('edge_local_meta', 'cloud_capabilities_seen')) {
            $schema->table('edge_local_meta', function (Blueprint $t) {
                $t->text('cloud_capabilities_seen')->nullable();
                $t->timestamp('cloud_capabilities_seen_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasColumn('edge_local_meta', 'cloud_capabilities_seen')) {
            $schema->table('edge_local_meta', fn (Blueprint $t) => $t->dropColumn(['cloud_capabilities_seen', 'cloud_capabilities_seen_at']));
        }
        if ($schema->hasColumn('customer_addresses', 'edge_origin')) {
            $schema->table('customer_addresses', fn (Blueprint $t) => $t->dropColumn('edge_origin'));
        }
        if ($schema->hasColumn('customers', 'edge_origin')) {
            $schema->table('customers', fn (Blueprint $t) => $t->dropColumn(['edge_origin', 'edge_created_at']));
        }
    }
};
