<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WEBSITE-I18N-GEO-1 P3 — what a per-branch signup bought, and where the business is.
 *
 * subscriptions: the market's pricing model, currency, branches bought, extra terminals, and a copy
 * of the quote at signup (so a later price change never touches an existing customer). All null on
 * every existing row = a bundle subscription, read exactly as before.
 *
 * tenants: the owner's language (emails) and the business's timezone (the first branch's clock —
 * before this every workspace started on Asia/Karachi, so a Saudi shop's business day and shift
 * reports would have run three hours off). Null on existing rows = Asia/Karachi, as before.
 */
return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        $db = Schema::connection('master');

        $db->table('subscriptions', function (Blueprint $table) use ($db) {
            if (! $db->hasColumn('subscriptions', 'pricing_model')) {
                $table->enum('pricing_model', ['bundle', 'per_branch'])->nullable()->after('status');
            }
            if (! $db->hasColumn('subscriptions', 'currency_code')) {
                $table->char('currency_code', 3)->nullable()->after('pricing_model');
            }
            if (! $db->hasColumn('subscriptions', 'branches_purchased')) {
                $table->unsignedSmallInteger('branches_purchased')->nullable()->after('currency_code');
            }
            if (! $db->hasColumn('subscriptions', 'extra_terminals')) {
                $table->unsignedSmallInteger('extra_terminals')->default(0)->after('branches_purchased');
            }
            if (! $db->hasColumn('subscriptions', 'price_snapshot')) {
                $table->json('price_snapshot')->nullable()->after('extra_terminals');
            }
        });

        $db->table('tenants', function (Blueprint $table) use ($db) {
            if (! $db->hasColumn('tenants', 'locale')) {
                $table->string('locale', 8)->nullable()->after('currency_code');
            }
            if (! $db->hasColumn('tenants', 'timezone')) {
                $table->string('timezone', 64)->nullable()->after('locale');
            }
        });
    }

    public function down(): void
    {
        $db = Schema::connection('master');
        foreach (['pricing_model', 'currency_code', 'branches_purchased', 'extra_terminals', 'price_snapshot'] as $col) {
            if ($db->hasColumn('subscriptions', $col)) {
                $db->table('subscriptions', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
        foreach (['locale', 'timezone'] as $col) {
            if ($db->hasColumn('tenants', $col)) {
                $db->table('tenants', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
