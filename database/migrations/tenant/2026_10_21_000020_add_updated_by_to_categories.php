<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CATEGORY-PARENT-GUARD-1 (21 Oct) — "kis ne badla" category par.
 *
 * Khatri ki saari main categories ek dopahar haath se apni hi sub-categories ke andar daal di gayin aur POS ke tabs
 * gayab ho gaye; kis user ne kiya, ye bataane wala koi record nahi tha. Ab Create / Edit Category is me logged-in user
 * rakhta hai aur Edit page "Last changed by" dikhata hai.
 *
 * Nullable, koi FK nahi (user hatne par category ki save kabhi na ruke), purani rows khaali rehti hain — koi backfill nahi.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('tenant');

        if ($schema->hasTable('categories') && ! $schema->hasColumn('categories', 'updated_by')) {
            $schema->table('categories', function (Blueprint $table) {
                $table->unsignedBigInteger('updated_by')->nullable()->after('is_active');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('tenant');

        if ($schema->hasColumn('categories', 'updated_by')) {
            $schema->table('categories', fn (Blueprint $table) => $table->dropColumn('updated_by'));
        }
    }
};
