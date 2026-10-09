<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHATSAPP-USAGE-LEDGER-1 — har bheje gaye message ki aik row.
 *
 * Ab tak kahin darj nahi hota tha ke kis tenant ne kitne message bheje. `report_schedule_runs` sirf
 * "is raat whatsapp chala" likhta hai, "kitne numbers par chala" nahi — aur numbers beech me badalte
 * rehte hain (khatri 5 se 7 hue). Us se lagaya hua takhmeena 112 nikla jabke Meta ka meter 90 keh
 * raha tha. Us farq par bill bhejna 15% zyada wasool karna hota, aur sabit karne ko kuch na hota.
 *
 * MASTER par, tenant ki DB par nahi: ye platform ki billing hai (Bingoo tenant se wasool karta hai),
 * tenant ka apna hisaab nahi. Aur saaray tenants aik hi WhatsApp number se jate hain, is liye ginti
 * ka aik hi markazi register ban sakta hai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('master')->create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Kis cheez ne bheja. 'schedule' = raat ka cron, 'manual' = koi button ya dobara bhejna,
            // 'backfill' = Meta ke aankRon se peechhe se darj kiya gaya (asal row maujood hi nahi thi).
            $table->enum('source', ['schedule', 'manual', 'backfill'])->default('schedule');

            $table->string('template', 100);
            // Number poora rakha jata hai: bill par ikhtelaf ho to "kis number par gaya" ka jawab
            // dena paRta hai, aur chhupaya hua number us sawal ka jawab nahi de sakta.
            //
            // NULLABLE sirf backfill ki wajah se: Meta guzre dinon ki GINTI deta hai, ye nahi ke
            // kaun sa number tha. Wahan koi number likhna usay gharhna hota. Aage jo rows banengi
            // un par number hamesha hoga.
            $table->string('to', 20)->nullable();
            $table->string('wamid', 128)->nullable();

            // accepted = Meta ne qubool kiya. delivered = waqai pohancha. Ye DO alag cheezein hain:
            // 8/9 Oct ki raat malik ka card decline hua, humne 14 bheje, Meta ne sab "accepted" kaha
            // aur koi nahi pohancha. Bill DELIVERED par hona chahiye, warna tenant us cheez ka paisa
            // deta hai jo kabhi nahi aayi.
            $table->enum('status', ['accepted', 'sent', 'delivered', 'read', 'failed'])->default('accepted');
            $table->text('failure_reason')->nullable();

            // Rate ROW par likha jata hai, settings se nahi parha jata. Kal rate 9.85 se 12 ho jaye to
            // purane invoice hilne nahi chahiyen — wohi usool jo catering ke invoice par hai.
            $table->decimal('rate_charged', 10, 4)->default(0);
            // Hamari apni laagat (Meta + tax). Margin naapa jaye, farz na kiya jaye.
            $table->decimal('provider_cost', 10, 4)->nullable();

            // Kis din ka hisaab. business date, bhejne ka waqt nahi: khatri 00:30 PKT par bhejta hai,
            // yani UTC me pichhla din — us se mahine ki seema par ginti aage peechhe ho jati.
            $table->date('usage_date');
            $table->timestamp('sent_at');

            $table->foreignId('invoice_id')->nullable()->constrained('subscription_invoices')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'usage_date']);
            $table->index(['tenant_id', 'status']);
            $table->index('invoice_id');
            // Aik wamid aik hi dafa. Webhook aur dobara bhejna dono isi row par aane chahiyen.
            $table->unique('wamid');
        });
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('whatsapp_messages');
    }
};
