<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// EDGE-LOCAL-RUNTIME-1 (Section E): the entire Cloud scheduler is registered ONLY on a Cloud
// runtime. A packaged Branch Server appliance must never auto-run Cloud scheduled tasks
// (subscription expiry, demo reset, multi-tenant backup). isCloudSafe() is the non-throwing,
// config-load-safe mode check; on a branch_server it is false, so none of these are scheduled. The
// underlying commands are also default-denied by the console boundary (EdgeConsoleBoundary).
if (\App\Support\EdgeRuntime::isCloudSafe()) {
    // Daily SaaS subscription expiry sweep (active past current_period_ends_at → past_due).
    // Requires OS cron on the server: * * * * * php /path/to/artisan schedule:run
    Schedule::command('saas:subscriptions-expire')->dailyAt('00:10');

    // WEBSITE-I18N-GEO-1 P4: the public website's country database (DB-IP Lite), refreshed monthly.
    Schedule::command('geoip:update')->monthlyOn(4, '03:40')->withoutOverlapping();

    // Nightly public-demo reset (15D-8): restore the five industry demos to clean sample data.
    // Registered only; it does nothing until OS cron runs `php artisan schedule:run`.
    if (config('saas.demos.enabled', true)) {
        Schedule::command('demo:reset-all --yes')
            ->dailyAt(config('saas.demos.reset_daily_at', '04:00'))
            ->withoutOverlapping();
    }

    // PROD-READINESS-1: nightly multi-tenant backup with retention prune. Gated by
    // BACKUP_SCHEDULE_ENABLED so local/dev machines never run it; set it to true in
    // the production .env (mysqldump binaries + disk space required). Runs at 02:00,
    // before the 04:00 demo reset. See docs/ops/BACKUP_AND_RESTORE_RUNBOOK.md —
    // local-only backups are NOT enough; sync them offsite.
    if (config('backup.schedule_enabled', false)) {
        Schedule::command('tenants:backup --prune')->dailyAt('02:00')->withoutOverlapping();

        // TENANT-AUTO-BACKUP-1: per-tenant scheduled backups. Runs every few minutes and only acts
        // when a tenant's configured HH:MM slot (in its own timezone) is due; the unique slot claim
        // makes each fire once per day. Retention prune is per-tenant, scheduled-type only.
        Schedule::command('tenants:auto-backup')->everyFiveMinutes()->withoutOverlapping();
    }

    // SALES REPORT CENTER — scheduled owner report emails. Safe at any cadence: each schedule's
    // reporting period is claimed idempotently (unique schedule+period), so retries/overlaps can
    // never double-send. Cloud-only (Edge CLI boundary default-denies the command anyway).
    Schedule::command('reports:dispatch-scheduled')->everyFifteenMinutes()->withoutOverlapping();

    // ONLINE-ORDERING-1: confirm na hue website orders (sirf Draft) client ki setting ke mutabiq cancel.
    // Setting OFF (default) ho to kuch nahi karta. Held order kabhi nahi chhuta.
    Schedule::command('online-ordering:auto-cancel')->everyMinute()->withoutOverlapping();

    // BILLING-TENANT-CYCLE-1 — WhatsApp ki ALAG invoice ab nahi banti.
    //
    // Pehle yahan roz `billing:whatsapp-invoice` chalti thi aur calendar mahine ki apni `addon`
    // invoice kholti thi. Malik ne kaha: alag invoice ki zaroorat nahi — usage usi mahana invoice me
    // aaye jo tenant ke apne din banti hai, breakup ke saath. Us ka kaam ab
    // `billing:generate-monthly-invoices` karti hai: wo jo usage kisi invoice se bandhi nahi, sab
    // apne andar le leti hai.
    //
    // Usage ki rows phir bhi HAR raat likhi jati hain (WhatsAppChannel khud likhta hai) — wo rukti
    // nahi, bas kisi invoice se nahi bandhtin jab tak cycle ka din na aaye. Dashboard ka running
    // khana wohi khuli rows ginta hai, is liye wo cycle ke din apne aap sifar ho jata hai.

    // ONLINE-ORDERING-1 (Phase 4b) — website orders ka 1%, usi usage invoice me. WhatsApp ke baad, taake
    // aik raat me invoice dono hisson ke saath bane. Khule mahine roz dobara bante hain (cancel/return khud
    // theek); band mahina nahi hilta.
    Schedule::command('billing:website-commission --yes')
        ->timezone('Asia/Karachi')->dailyAt('03:40')->withoutOverlapping();

    // SAAS-BILLING-AUTO-1 — har tenant ka mahana invoice us ke APNE din par. Roz chalti hai aur sirf
    // un subscriptions ko dekhti hai jin ka invoice_day aaj hai (khatri 15, kashifkitchen 20,
    // kashiffood 25, tawakal 10). Is se pehle koi generator tha hi nahi, yani 105,000/mah ka hisaab
    // har mahine haath se banta — aur jo cheez haath se banti hai wo kisi mahine bhool jati hai.
    //
    // 06:00 Karachi: din shuru hone par bana ho, raat ke kisi lamhe me nahi. Dobara banne ka khatra
    // nahi — aik tenant ke aik mahine ka aik hi invoice banta hai.
    Schedule::command('billing:generate-monthly-invoices --yes')
        ->timezone('Asia/Karachi')->dailyAt('06:00')->withoutOverlapping();    // PRINT-AUTOCLOSE-STUCK-1 — jo parchi ek ghante se atki rahe, khud band ho jaye.
    //
    // Zaroorat: The Kashif Foods par teen Report Center ki parchiyan galti se DOOSRI branch
    // ki printer par bhej di gayi thin. Wahan se pahunch hi nahi sakti thin, aur defer
    // jaan-boojh kar "kabhi haar na maano" par chalta hai — to har ~45 second par agent 16
    // second us gum printer par atka rehta aur usi dauran banne wali har receipt/KOT 12-17
    // second intezar karti. Ek parchi do din se ghoom rahi thi.
    //
    // Hadd 60 MINUTE hai, 30 nahi — Pakistan me bijli chali jaye to printer/PC der tak band
    // reh sakta hai aur parchi ko us se bach jana chahiye. Us se purani parchi bemani ho
    // chuki hoti hai; phir bhi wo GUM nahi hoti — `cancelled` ho kar sabab ke saath screen
    // par rehti hai aur Retry se wapas queue me aa jaati hai.
    //
    // Har 15 minute par chalna mehfooz hai: kaam idempotent hai (jo band ho gayi wo phir
    // `queued|failed` nahi rehti, is liye dobara nahi uthti).
    Schedule::command('printing:autoclose-stuck')->everyFifteenMinutes()->withoutOverlapping();

    // CATERING-SLICE-3 — upcoming-event reminders (D-7/D-3/D-1/same-day). Safe at any
    // cadence: each (event, offset) is claimed idempotently before sending, so retries
    // and overlaps never double-send. Only tenants entitled to the catering module run.
    Schedule::command('catering:dispatch-event-reminders')->everyFifteenMinutes()->withoutOverlapping();
}
