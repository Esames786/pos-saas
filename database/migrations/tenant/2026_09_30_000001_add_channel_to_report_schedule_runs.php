<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHATSAPP-REPORT-CHANNEL-1 — the period claim becomes PER CHANNEL.
 *
 * `runDue()` claims a period by inserting into report_schedule_runs, and on failure DELETES that claim
 * so the next tick retries. With one channel that is exactly right: it is what stops a retry from
 * sending the same report twice.
 *
 * With two channels it inverts. Email goes out, WhatsApp fails, the catch deletes the shared claim —
 * and the next tick sends the EMAIL again. The owner gets two copies a day, while `last_failure` talks
 * about WhatsApp: two facts that are very hard to connect.
 *
 * So the claim is keyed on the channel as well. Each channel claims its own period, and a channel that
 * fails frees only its own. Existing rows are email — the only channel that has ever run.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('tenant');

        if (! $schema->hasTable('report_schedule_runs')) {
            return;
        }

        if (! $schema->hasColumn('report_schedule_runs', 'channel')) {
            $schema->table('report_schedule_runs', function (Blueprint $table) {
                $table->string('channel', 20)->default('email')->after('report_schedule_id');
            });
        }

        $indexes = $this->indexes($schema);

        // ORDER MATTERS — and this is not a preference, MySQL refuses the other way round.
        //
        // report_schedule_id carries a foreign key, and MySQL insists on an index for it. The old
        // unique was providing that index, so dropping it first is rejected outright:
        //     1553 Cannot drop index 'rsr_schedule_period_unique': needed in a foreign key constraint
        //
        // The new unique leads with the same column, so it can take that duty over. Create it FIRST;
        // only then is the old one free to go.
        if (! in_array('rsr_schedule_period_channel_unique', $indexes, true)) {
            $schema->table('report_schedule_runs', function (Blueprint $table) {
                $table->unique(
                    ['report_schedule_id', 'period_key', 'channel'],
                    'rsr_schedule_period_channel_unique'
                );
            });
        }

        if (in_array('rsr_schedule_period_unique', $indexes, true)) {
            $schema->table('report_schedule_runs', function (Blueprint $table) {
                $table->dropUnique('rsr_schedule_period_unique');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('tenant');

        if (! $schema->hasTable('report_schedule_runs')) {
            return;
        }

        $indexes = $this->indexes($schema);

        // Same rule in reverse: restore the narrower unique before removing the wider one, or the
        // foreign key is left without an index. Only email rows can exist on the way back, so the
        // narrower unique cannot collide.
        if (! in_array('rsr_schedule_period_unique', $indexes, true)) {
            $schema->table('report_schedule_runs', function (Blueprint $table) {
                $table->unique(['report_schedule_id', 'period_key'], 'rsr_schedule_period_unique');
            });
        }

        if (in_array('rsr_schedule_period_channel_unique', $indexes, true)) {
            $schema->table('report_schedule_runs', function (Blueprint $table) {
                $table->dropUnique('rsr_schedule_period_channel_unique');
            });
        }

        if ($schema->hasColumn('report_schedule_runs', 'channel')) {
            $schema->table('report_schedule_runs', function (Blueprint $table) {
                $table->dropColumn('channel');
            });
        }
    }

    /** @return list<string> */
    private function indexes($schema): array
    {
        $table = $schema->getConnection()->getTablePrefix().'report_schedule_runs';

        return collect($schema->getConnection()->select("SHOW INDEX FROM `{$table}`"))
            ->pluck('Key_name')->unique()->values()->all();
    }
};
