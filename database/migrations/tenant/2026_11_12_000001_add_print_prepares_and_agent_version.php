<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRINT-PREPARE-1 + AGENT-UPGRADE-1 (11 Nov) — sirf dhaancha. Isay likhne wala code ALAG deploy me jata hai
 * (20 Oct ka sabaq: column aur us ka writer ek hi deploy me ho to beech ke ek second me writer gir jata hai).
 *
 * 1. `print_agents.agent_version` — agent har heartbeat par apna version bataye, taake Print Agents screen par
 *    nazar aaye ke kis PC par kaun sa agent hai. Aaj tak ye sirf pairing par aata tha aur kahin rakha nahi jata tha.
 *
 * 2. `print_prepares` — "screen khulte hi parcha tayyar": agent PDF bana kar apne paas rakhta hai, CHHAPTA NAHI.
 *    Ye `print_jobs` me jaan-boojh kar nahi: purana agent sirf `print_status = 'queued'` leta hai, Print Jobs
 *    screen aur reports asli parchon ki hain, aur ye rows roz saaf hoti hain.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sm = Schema::connection('tenant');

        if (! $sm->hasColumn('print_agents', 'agent_version')) {
            $sm->table('print_agents', function (Blueprint $table) {
                $table->string('agent_version', 50)->nullable();
            });
        }

        if (! $sm->hasTable('print_prepares')) {
            $sm->create('print_prepares', function (Blueprint $table) {
                $table->id();
                // Agent tak raasta: wohi printer jo safhe par pehle se chuna hai (us ka agent PDF banata hai).
                $table->foreignId('printer_id')->constrained('printers')->cascadeOnDelete();
                $table->unsignedBigInteger('branch_id')->nullable();
                // kind:reference:lang — ek document ki ek zubaan. Nayi tayyari aaye to agent isi ki purani PDF mitata hai.
                $table->string('doc_key', 120);
                // sha256(html bina "chhapne ke waqt" + kaghaz) — Send isi se milata hai; badla hua order kabhi purani PDF se nahi.
                $table->char('fingerprint', 64)->unique('print_prepares_fingerprint_unique');
                $table->string('paper', 40);
                $table->longText('html');
                $table->string('status', 20)->default('queued');   // queued | ready | failed
                $table->unsignedBigInteger('claimed_by_agent_id')->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('ready_at')->nullable();
                $table->string('error', 500)->nullable();
                $table->timestamps();

                $table->index(['status', 'printer_id'], 'print_prepares_status_printer_idx');
                $table->index('doc_key', 'print_prepares_doc_key_idx');
                $table->index('created_at', 'print_prepares_created_idx');
            });
        }
    }

    public function down(): void
    {
        $sm = Schema::connection('tenant');
        $sm->dropIfExists('print_prepares');
        if ($sm->hasColumn('print_agents', 'agent_version')) {
            $sm->table('print_agents', fn (Blueprint $table) => $table->dropColumn('agent_version'));
        }
    }
};
