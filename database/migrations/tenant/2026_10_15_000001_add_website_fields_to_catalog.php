<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONLINE-ORDERING-1 (Phase 2a) — website ka menu MOJOODA catalog se, koi alag menu nahi.
 *
 * Malik (15 Oct): alag product/category screen nahi — "already product catalog ki screen hai". Aur:
 * "bas mera POS kharab na ho". Is liye har column ikhtiyari hai aur default wohi jo aaj hai:
 * - categories/products/combos.show_on_website = false → website par kuch khud nahi aata.
 * - modifier_groups.channel = 'pos' → har mojooda group POS par waise hi; 'website' wala group POS
 *   par kabhi nahi pohanchta (POSController use bhejta hi nahi), 'both' dono par.
 *
 * Card = aik ya kai POS products/deals jin ka `website_card_name` aur website category aik ho; har aik
 * apne `website_size_label` ke saath card ka aik size. Tasveer aur tafseel product ki apni.
 * `website_category_id` = website par kis category me dikhe (khaali = apni POS category) — POS ki
 * tarteeb chhede baghair purani website jaisi tarteeb.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('tenant');

        $schema->table('categories', function (Blueprint $table) use ($schema) {
            if (! $schema->hasColumn('categories', 'show_on_website')) {
                $table->boolean('show_on_website')->default(false);
                $table->string('website_name', 120)->nullable();
                $table->unsignedInteger('website_sort_order')->nullable();
                // Dono khaali = hamesha. to < from = aadhi raat paar (Midnight: 00:00 → 04:00).
                $table->time('website_visible_from')->nullable();
                $table->time('website_visible_to')->nullable();
            }
        });

        foreach (['products', 'combos'] as $t) {
            $schema->table($t, function (Blueprint $table) use ($schema, $t) {
                if (! $schema->hasColumn($t, 'show_on_website')) {
                    $table->boolean('show_on_website')->default(false);
                    $table->string('website_card_name', 190)->nullable();
                    $table->string('website_size_label', 80)->nullable();
                    $table->foreignId('website_category_id')->nullable()->constrained('categories')->nullOnDelete();
                    $table->string('website_tag', 20)->nullable();
                    $table->boolean('website_popular')->default(false);
                    $table->unsignedInteger('website_sort_order')->nullable();
                    // Website ki APNI tasveer aur tafseel. Product ki `image_path` POS tile par dikhti hai;
                    // website ki 150 tasveerein waha daalne se counter ki shakl badal jati — is liye alag.
                    $table->string('website_image_path', 255)->nullable();
                    $table->text('website_description')->nullable();
                }
            });
        }

        if (! $schema->hasColumn('modifier_groups', 'channel')) {
            $schema->table('modifier_groups', function (Blueprint $table) {
                // pos | website | both
                $table->string('channel', 10)->default('pos');
            });
        }

        // Deal par add-on group (aaj sirf website ke liye; POS deal flow isay nahi parhta).
        if (! $schema->hasTable('combo_modifier_group')) {
            $schema->create('combo_modifier_group', function (Blueprint $table) {
                $table->id();
                $table->foreignId('combo_id')->constrained('combos')->cascadeOnDelete();
                $table->foreignId('modifier_group_id')->constrained('modifier_groups')->cascadeOnDelete();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['combo_id', 'modifier_group_id']);
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('tenant');
        $schema->dropIfExists('combo_modifier_group');

        if ($schema->hasColumn('modifier_groups', 'channel')) {
            $schema->table('modifier_groups', fn (Blueprint $table) => $table->dropColumn('channel'));
        }
        foreach (['products', 'combos'] as $t) {
            if ($schema->hasColumn($t, 'show_on_website')) {
                $schema->table($t, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('website_category_id');
                    $table->dropColumn(['show_on_website', 'website_card_name', 'website_size_label', 'website_tag',
                        'website_popular', 'website_sort_order', 'website_image_path', 'website_description']);
                });
            }
        }
        if ($schema->hasColumn('categories', 'show_on_website')) {
            $schema->table('categories', fn (Blueprint $table) => $table->dropColumn(['show_on_website', 'website_name', 'website_sort_order', 'website_visible_from', 'website_visible_to']));
        }
    }
};
