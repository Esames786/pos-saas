<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRANCH-OPERATING-MODE-1: Branch Edge (Local POS) lifecycle.
 *
 * Safe 5-state lifecycle (not a raw two-state toggle): the cloud only locks a
 * branch's sale mutations when local_edge_status is 'active' (or 'closing').
 * 'pending' setup must NOT interrupt cloud sales. Every existing/new branch
 * defaults to cloud/inactive — zero behavior change until explicitly activated.
 * Design: docs/audits/offline-pos-architecture-2026-07.md
 */
return new class extends Migration
{
    private const COLUMNS = [
        'sales_operating_mode',
        'local_edge_status',
        'local_edge_activated_at',
        'local_edge_suspended_at',
        'local_edge_status_reason',
    ];

    public function up(): void
    {
        Schema::connection('tenant')->table('branches', function (Blueprint $table) {
            $sm = Schema::connection('tenant');

            if (! $sm->hasColumn('branches', 'sales_operating_mode')) {
                // cloud | local_edge
                $table->string('sales_operating_mode', 20)->default('cloud')->after('allow_negative_stock');
            }
            if (! $sm->hasColumn('branches', 'local_edge_status')) {
                // inactive | pending | active | closing | suspended
                $table->string('local_edge_status', 20)->default('inactive')->after('sales_operating_mode');
            }
            if (! $sm->hasColumn('branches', 'local_edge_activated_at')) {
                $table->timestamp('local_edge_activated_at')->nullable();
            }
            if (! $sm->hasColumn('branches', 'local_edge_suspended_at')) {
                $table->timestamp('local_edge_suspended_at')->nullable();
            }
            if (! $sm->hasColumn('branches', 'local_edge_status_reason')) {
                $table->string('local_edge_status_reason', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('branches', function (Blueprint $table) {
            $sm = Schema::connection('tenant');
            foreach (self::COLUMNS as $column) {
                if ($sm->hasColumn('branches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
