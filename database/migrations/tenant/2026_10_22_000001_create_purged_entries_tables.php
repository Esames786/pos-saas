<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VOID-PURGE-1 (22 Oct) — ghalat entry jo apni tareekh par void ho chuki, Owner use hamesha ke liye hata sakta hai.
 *
 *   • purged_entries — har hatayi gayi entry ki chhupi copy: kis ne, kab, kyun, kahan se, aur har mitayi gayi satar
 *     (dono hisse) JSON me. Sirf Owner ka "Deleted entries" log ise dikhata hai; koi code ise mitata nahi.
 *   • purged_numbers — hataye gaye number (JE-…, manual journal, EXP-…) taake agli entry wahi number dobara na le.
 *
 * Dono naye aur additive; koi purani satar nahi chhuti. Code tab tak purge se inkaar karta hai jab tak ye na hon.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('tenant');

        if (! $schema->hasTable('purged_entries')) {
            $schema->create('purged_entries', function (Blueprint $t) {
                $t->id();
                $t->string('entity_type', 40);
                $t->unsignedBigInteger('entity_id');
                $t->string('document_no', 100)->nullable();
                $t->date('document_date')->nullable();
                $t->decimal('amount', 15, 2)->default(0);
                $t->string('reason', 500);
                $t->longText('snapshot');
                $t->unsignedBigInteger('purged_by_user_id')->nullable();
                $t->timestamp('purged_at')->nullable();
                $t->string('ip', 64)->nullable();
                $t->timestamps();
                $t->index(['entity_type', 'entity_id']);
            });
        }

        if (! $schema->hasTable('purged_numbers')) {
            $schema->create('purged_numbers', function (Blueprint $t) {
                $t->id();
                $t->string('kind', 30);
                $t->string('number', 100);
                $t->unsignedBigInteger('purged_entry_id')->nullable();
                $t->timestamps();
                $t->unique(['kind', 'number']);
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('tenant');
        $schema->dropIfExists('purged_numbers');
        $schema->dropIfExists('purged_entries');
    }
};
