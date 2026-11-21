<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE 0.9 CONTINUITY — WHICH takeover an adoption decision belongs to (owner, 21 Nov evening: an optional, default-OFF automatic
 * takeover calls the SAME EdgeAuthorityService::takeOver with by = 'auto-takeover'). Adoption behaves identically for a manual and
 * an automatic takeover (it is keyed on LOCAL_ACTIVE + the takeover instant); the audit records who/what took over:
 *
 *   edge_mirror_adoptions.takeover_by / takeover_mode   per adopted or skipped item (manual | auto);
 *   edge_mirror_meta.adoption_takeover_by               on the segment decision (incl. a refused stale / untrusted mirror).
 *
 * EDGE-ONLY, additive, nullable, idempotent.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        if ($s->hasTable('edge_mirror_adoptions') && ! $s->hasColumn('edge_mirror_adoptions', 'takeover_by')) {
            $s->table('edge_mirror_adoptions', function (Blueprint $table) {
                $table->string('takeover_by', 100)->nullable();
                $table->string('takeover_mode', 10)->nullable();   // manual | auto
            });
        }
        if ($s->hasTable('edge_mirror_meta') && ! $s->hasColumn('edge_mirror_meta', 'adoption_takeover_by')) {
            $s->table('edge_mirror_meta', function (Blueprint $table) {
                $table->string('adoption_takeover_by', 100)->nullable();
            });
        }
    }

    public function down(): void
    {
        $s = Schema::connection($this->connection);
        if ($s->hasColumn('edge_mirror_adoptions', 'takeover_by')) {
            $s->table('edge_mirror_adoptions', fn (Blueprint $table) => $table->dropColumn(['takeover_by', 'takeover_mode']));
        }
        if ($s->hasColumn('edge_mirror_meta', 'adoption_takeover_by')) {
            $s->table('edge_mirror_meta', fn (Blueprint $table) => $table->dropColumn('adoption_takeover_by'));
        }
    }
};
