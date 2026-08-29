<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRINTER-HEALTH-1: live reachability on the Printers screen, and a small command queue so the
 * operator can Test / Reboot a printer from the browser (the agent runs it on the LAN).
 * Purely additive — existing printing is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('printers', function (Blueprint $table) {
            $table->boolean('last_ping_ok')->nullable()->after('last_error');
            $table->unsignedInteger('last_ping_ms')->nullable()->after('last_ping_ok');
            $table->timestamp('last_ping_at')->nullable()->after('last_ping_ms');
        });

        Schema::connection('tenant')->create('print_agent_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('printer_id')->constrained('printers')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();     // scope to the agent's branch
            $table->string('type', 30);                              // ping | reboot  (soft_reset rides print_jobs)
            $table->string('status', 20)->default('queued');         // queued | running | done | failed
            $table->string('result', 500)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->unsignedBigInteger('claimed_by_agent_id')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'branch_id']);
            $table->index(['printer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('print_agent_commands');
        Schema::connection('tenant')->table('printers', function (Blueprint $table) {
            $table->dropColumn(['last_ping_ok', 'last_ping_ms', 'last_ping_at']);
        });
    }
};
