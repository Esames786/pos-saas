<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONLINE-ORDERING-1 (Phase 5) — domain ki qism.
 *
 * `app` = POS/admin (`*.bingoopos.com`, aaj ke saare domain). `website` = client ka apna domain (jaise
 * kashiffood.com) jo SIRF us ki ordering website kholta hai — login, POS, admin us par kabhi nahi.
 * Default `app`, is liye har maujooda row waisi hi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('master')->hasColumn('tenant_domains', 'kind')) {
            Schema::connection('master')->table('tenant_domains', function (Blueprint $table) {
                $table->enum('kind', ['app', 'website'])->default('app')->after('domain');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('master')->hasColumn('tenant_domains', 'kind')) {
            Schema::connection('master')->table('tenant_domains', function (Blueprint $table) {
                $table->dropColumn('kind');
            });
        }
    }
};
