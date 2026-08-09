<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SALES REPORT CENTER (spec AA/AB) — scheduled owner reports. Email-only, recipient = tenants
 * .owner_email (master), tenant/branch timezone, IDEMPOTENT per (schedule, reporting period):
 * report_schedule_runs' unique key is what makes a scheduler retry unable to email twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('report_schedules')) {
            Schema::connection('tenant')->create('report_schedules', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->json('sections');                 // e.g. ["overview","order_types","categories","waiters","payments","cash_bank"]
                $table->string('frequency', 10);          // daily | weekly | monthly
                $table->unsignedTinyInteger('weekday')->nullable();   // 1-7 (weekly)
                $table->unsignedTinyInteger('day_of_month')->nullable(); // 1-31, month-end-safe (monthly)
                $table->string('send_time', 5);           // HH:MM (branch/tenant timezone)
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_run_at')->nullable();
                $table->timestamp('last_success_at')->nullable();
                $table->string('last_failure', 500)->nullable();
                $table->timestamp('next_run_at')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::connection('tenant')->hasTable('report_schedule_runs')) {
            Schema::connection('tenant')->create('report_schedule_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('report_schedule_id')->constrained('report_schedules')->cascadeOnDelete();
                $table->string('period_key', 20);         // daily: Y-m-d | weekly: oW | monthly: Y-m
                $table->string('status', 20)->default('sent'); // sent | failed
                $table->string('detail', 500)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->unique(['report_schedule_id', 'period_key'], 'rsr_schedule_period_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('report_schedule_runs');
        Schema::connection('tenant')->dropIfExists('report_schedules');
    }
};
