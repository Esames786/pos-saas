<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('printers', function (Blueprint $table) {
            $table->boolean('supports_reminder')->default(false)->after('print_role');
        });

        Schema::connection('tenant')->table('category_printer_mappings', function (Blueprint $table) {
            $table->dropUnique('cat_printer_branch_cat_role_unique');
            $table->string('order_type', 30)->default('all')->after('print_role');
        });

        DB::connection('tenant')->table('category_printer_mappings')
            ->whereNull('order_type')
            ->orWhere('order_type', '')
            ->update(['order_type' => 'all']);

        $this->widenDocumentEnums();

        Schema::connection('tenant')->table('category_printer_mappings', function (Blueprint $table) {
            $table->unique(
                ['branch_id', 'category_id', 'printer_id', 'print_role', 'order_type'],
                'cpm_route_printer_unique'
            );
        });

        Schema::connection('tenant')->table('receipt_layout_settings', function (Blueprint $table) {
            $table->boolean('show_order_time')->default(true)->after('show_order_no');
            $table->boolean('show_updated_time')->default(true)->after('show_order_time');
            $table->boolean('show_print_time')->default(true)->after('show_updated_time');
        });

        Schema::connection('tenant')->table('print_jobs', function (Blueprint $table) {
            $table->string('logical_key', 191)->nullable()->unique()->after('job_no');
            $table->unsignedInteger('copy_no')->default(1)->after('logical_key');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('print_jobs', function (Blueprint $table) {
            $table->dropUnique(['logical_key']);
            $table->dropColumn(['logical_key', 'copy_no']);
        });

        Schema::connection('tenant')->table('receipt_layout_settings', function (Blueprint $table) {
            $table->dropColumn(['show_order_time', 'show_updated_time', 'show_print_time']);
        });

        Schema::connection('tenant')->table('category_printer_mappings', function (Blueprint $table) {
            $table->dropUnique('cpm_route_printer_unique');
            $table->dropColumn('order_type');
            $table->unique(['branch_id', 'category_id', 'print_role'], 'cat_printer_branch_cat_role_unique');
        });

        Schema::connection('tenant')->table('printers', function (Blueprint $table) {
            $table->dropColumn('supports_reminder');
        });

        $this->restoreDocumentEnums();
    }

    private function widenDocumentEnums(): void
    {
        if (DB::connection('tenant')->getDriverName() !== 'mysql') {
            return;
        }

        DB::connection('tenant')->statement(
            "ALTER TABLE category_printer_mappings MODIFY print_role VARCHAR(30) NOT NULL DEFAULT 'kot'"
        );
        DB::connection('tenant')->statement(
            "ALTER TABLE receipt_layout_settings MODIFY document_type VARCHAR(30) NOT NULL DEFAULT 'receipt'"
        );
        DB::connection('tenant')->statement(
            "ALTER TABLE print_jobs MODIFY document_type VARCHAR(30) NOT NULL"
        );
    }

    private function restoreDocumentEnums(): void
    {
        if (DB::connection('tenant')->getDriverName() !== 'mysql') {
            return;
        }

        DB::connection('tenant')->statement(
            "ALTER TABLE category_printer_mappings MODIFY print_role ENUM('kot','receipt') NOT NULL DEFAULT 'kot'"
        );
        DB::connection('tenant')->statement(
            "ALTER TABLE receipt_layout_settings MODIFY document_type ENUM('receipt','kot') NOT NULL DEFAULT 'receipt'"
        );
        DB::connection('tenant')->statement(
            "ALTER TABLE print_jobs MODIFY document_type ENUM('receipt','kot') NOT NULL"
        );
    }
};
