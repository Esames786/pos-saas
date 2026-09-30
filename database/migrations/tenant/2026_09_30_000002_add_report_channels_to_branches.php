<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHATSAPP-REPORT-CHANNEL-1 — where a report goes is a BRANCH decision, with the tenant as fallback.
 *
 * A two-branch tenant wants each branch's figures on that branch's own number; a single-branch tenant
 * wants to set it once and forget it. NULL here means "ask the tenant", so nothing has to be filled in
 * per branch unless somebody actually wants it different.
 *
 * `branches.phone` is deliberately NOT reused: that is the branch's contact number and is not
 * necessarily on WhatsApp. Conflating them would silently send sales figures to a landline.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('tenant');

        if (! $schema->hasTable('branches')) {
            return;
        }

        $schema->table('branches', function (Blueprint $table) use ($schema) {
            if (! $schema->hasColumn('branches', 'report_channels')) {
                $table->json('report_channels')->nullable()->after('email');
            }
            if (! $schema->hasColumn('branches', 'report_whatsapp')) {
                $table->json('report_whatsapp')->nullable()->after('report_channels');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('tenant');

        if (! $schema->hasTable('branches')) {
            return;
        }

        $schema->table('branches', function (Blueprint $table) use ($schema) {
            foreach (['report_channels', 'report_whatsapp'] as $column) {
                if ($schema->hasColumn('branches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
