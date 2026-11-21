<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OFFLINE EDGE — ONLINE POS PARITY: table reservations, offline (EDGE-ONLY table).
 *
 * The online POS stores a reservation as columns ON `restaurant_tables` (a CONFIG table the appliance
 * re-derives from the Cloud bootstrap and deliberately EXCLUDES from backup). Storing offline reservation
 * state there would lose it on a config refresh and on replacement-box recovery. So the Branch Server keeps
 * reservations in this dedicated OPERATIONAL table — same operator behavior, offline-correct persistence:
 * it is captured by the encrypted backup and survives fresh-DB recovery, and it never fights the config
 * refresh over the shared table row.
 *
 * Cross-system identity: the reserved customer is carried by canonical `customer_uuid` (Cloud numeric ids
 * are not stable for operationally-created customers), plus a name/phone snapshot for walk-ins.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('edge_local_table_reservations')) {
            return;
        }
        Schema::connection($this->connection)->create('edge_local_table_reservations', function (Blueprint $table) {
            $table->id();
            $table->char('reservation_uuid', 26)->unique();          // canonical reservation identity (ULID)
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('restaurant_table_id');
            // Soft customer reference (no FK, like the online reserved_customer_id): the numeric id rides the
            // held sale on the SAME box; the uuid lets a recovered box re-resolve it; name/phone cover walk-ins.
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->char('customer_uuid', 26)->nullable();           // canonical customer identity (cross-system)
            $table->string('customer_name')->nullable();             // snapshot (walk-in or display)
            $table->string('customer_phone', 40)->nullable();
            $table->dateTime('reserved_for')->nullable();            // the booking time (informational)
            $table->text('note')->nullable();
            $table->unsignedBigInteger('reserved_by_user_id')->nullable();
            $table->string('status', 20)->default('active');         // active | seated | cancelled
            $table->unsignedBigInteger('restaurant_table_session_id')->nullable(); // set when the table is opened
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('seated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // At most one ACTIVE reservation per table is enforced in-service under a table row lock (the
            // proven Edge concurrency pattern); this index serves the lookup.
            $table->index(['restaurant_table_id', 'status'], 'eltr_table_status_idx');
            $table->index(['branch_id', 'status'], 'eltr_branch_status_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('edge_local_table_reservations');
    }
};
