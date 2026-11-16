<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONTACT-FORM-1 (schema only) — where a public "Contact Sales" enquiry is kept.
 *
 * Ye migration JAAN BOOJH KAR akeli deploy hoti hai: table pehle, likhne wala code agle deploy me.
 * Maalik ka usool hai — 20 Oct ko ek hi deploy me column aur us ka writer gaye thay aur hold ek
 * second ke liye nakaam ho gaya tha. Is deploy ke baad ye table khaali rehti hai aur koi ise
 * chhoota bhi nahi; agla deploy form kholta hai.
 *
 * Enquiry email se bhi jati hai (sales@), magar email ek raasta hai — record nahi. Mail server ka
 * ek bura din ek lead ko hamesha ke liye nahi kha sakta, is liye enquiry pehle yahan likhi jati hai
 * aur phir bheji jati hai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_enquiries', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('email');
            $table->string('phone', 50)->nullable();
            $table->string('business_name')->nullable();
            // Jis cheez ke baare me baat karni hai — safhe ke cards wohi hain (demo, enterprise,
            // migration, training, custom). Aage naye mauzu aa sakte hain, is liye enum nahi.
            $table->string('topic', 60)->nullable();
            $table->text('message');

            // Kis zaban ka safha tha, aur agar visitor kisi plan ke link se aaya to kaun sa plan.
            $table->string('locale', 10)->default('en');
            $table->string('plan_hint', 100)->nullable();

            // Spam dekhne ke liye — kis ne bheja aur kahan se.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            // 'new' aata hai, 'handled' Master panel me nishaan lagane par.
            $table->string('status', 20)->default('new');
            $table->timestamp('handled_at')->nullable();
            $table->string('handled_by', 255)->nullable();

            // Mail qatar me kab daali gayi. NULL ka matlab: record to hai, email nahi gayi —
            // yehi wo soorat hai jis ke liye ye table bani hai.
            $table->timestamp('notified_at')->nullable();

            $table->timestamps();

            // Panel list nayi enquiry pehle dikhata hai, aur status se chhantta hai.
            $table->index(['status', 'created_at']);
            // "Is bande ne pehle bhi likha tha?" — aur ek hi email se aane wale spam ko dekhne ke liye.
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_enquiries');
    }
};
