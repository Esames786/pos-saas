<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Patch printers
        $printerColumns = Schema::connection('tenant')->getColumnListing('printers');

        Schema::connection('tenant')->table('printers', function (Blueprint $table) use ($printerColumns) {
            if (!in_array('agent_enabled', $printerColumns, true)) {
                $table->boolean('agent_enabled')->default(true);
            }
            if (!in_array('last_seen_at', $printerColumns, true)) {
                $table->timestamp('last_seen_at')->nullable();
            }
            if (!in_array('last_error', $printerColumns, true)) {
                $table->text('last_error')->nullable();
            }
        });

        // Print agents
        if (!Schema::connection('tenant')->hasTable('print_agents')) {
            Schema::connection('tenant')->create('print_agents', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('agent_code', 80)->unique();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('terminal_id')->nullable()->index();
                $table->string('token_hash');
                $table->string('device_name')->nullable();
                $table->string('device_os')->nullable();
                $table->string('local_ip')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_seen_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->index(['branch_id', 'terminal_id', 'is_active']);
            });
        }

        // Patch print_jobs
        $jobColumns = Schema::connection('tenant')->getColumnListing('print_jobs');

        Schema::connection('tenant')->table('print_jobs', function (Blueprint $table) use ($jobColumns) {
            if (!in_array('claimed_by_agent_id', $jobColumns, true)) {
                $table->unsignedBigInteger('claimed_by_agent_id')->nullable()->index();
            }
            if (!in_array('claimed_at', $jobColumns, true)) {
                $table->timestamp('claimed_at')->nullable();
            }
            if (!in_array('raw_payload', $jobColumns, true)) {
                $table->longText('raw_payload')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasTable('print_jobs')) {
            $jobColumns = Schema::connection('tenant')->getColumnListing('print_jobs');
            Schema::connection('tenant')->table('print_jobs', function (Blueprint $table) use ($jobColumns) {
                foreach (['raw_payload', 'claimed_at', 'claimed_by_agent_id'] as $col) {
                    if (in_array($col, $jobColumns, true)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        Schema::connection('tenant')->dropIfExists('print_agents');

        if (Schema::connection('tenant')->hasTable('printers')) {
            $printerColumns = Schema::connection('tenant')->getColumnListing('printers');
            Schema::connection('tenant')->table('printers', function (Blueprint $table) use ($printerColumns) {
                foreach (['last_error', 'last_seen_at', 'agent_enabled'] as $col) {
                    if (in_array($col, $printerColumns, true)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
