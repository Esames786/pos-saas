<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRIMARY-BUSINESS-1 — what kind of business a tenant IS, set in Bingoo Master (owner's order, 19 Oct).
 *
 * The plan says what a tenant BOUGHT (modules). This says who the tenant IS — restaurant, catering,
 * medical store, mart, industry — and the screens follow it (product types, the top button, …).
 * It is never guessed from modules: existing tenants get it from the explicit list below, which the
 * owner approved; a tenant not on the list stays NULL and every screen behaves exactly as before.
 *
 * `features` holds per-tenant switches the owner flips in Master (e.g. the new product screen), so a
 * new screen can go live for a demo while live customers keep theirs until the owner says so.
 *
 * `tenant_setting_changes` records every change: who, when, from what, to what.
 */
return new class extends Migration
{
    private const PRIMARY = [
        'khatribiryani'     => 'restaurant',
        'kashiffood'        => 'restaurant',
        'tawakalkashif'     => 'restaurant',
        'mohsin'            => 'restaurant',
        'demo'              => 'restaurant',
        'restaurantdemo'    => 'restaurant',
        'restaurantprodemo' => 'restaurant',
        'enterprisedemo'    => 'restaurant',
        'kashifkitchen'     => 'catering',
        'pharmacydemo'      => 'pharmacy',
        'retaildemo'        => 'mart',
        'inventorydemo'     => 'mart',
        'financedemo'       => 'industry',
    ];

    public function up(): void
    {
        $schema = Schema::connection('master');

        if (! $schema->hasColumn('tenants', 'primary_business')) {
            $schema->table('tenants', function (Blueprint $table) {
                $table->string('primary_business', 30)->nullable()->after('business_name');
            });
        }
        if (! $schema->hasColumn('tenants', 'features')) {
            $schema->table('tenants', function (Blueprint $table) {
                $table->json('features')->nullable()->after('primary_business');
            });
        }
        if (! $schema->hasTable('tenant_setting_changes')) {
            $schema->create('tenant_setting_changes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('setting', 60);
                $table->text('old_value')->nullable();
                $table->text('new_value')->nullable();
                $table->unsignedBigInteger('changed_by_central_user_id')->nullable();
                $table->string('note', 255)->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'setting']);
            });
        }

        $db = DB::connection('master');
        foreach (self::PRIMARY as $code => $business) {
            $db->table('tenants')->where('tenant_code', $code)->whereNull('primary_business')
                ->update(['primary_business' => $business]);
        }
        // The new product screen starts ON only for demo tenants; live customers keep today's form.
        $db->table('tenants')->where('is_demo', 1)->whereNull('features')
            ->update(['features' => json_encode(['product_screen_v2' => true])]);
    }

    public function down(): void
    {
        $schema = Schema::connection('master');
        $schema->dropIfExists('tenant_setting_changes');
        foreach (['features', 'primary_business'] as $col) {
            if ($schema->hasColumn('tenants', $col)) {
                $schema->table('tenants', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
