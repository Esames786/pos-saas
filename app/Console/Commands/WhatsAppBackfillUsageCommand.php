<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Models\Master\WhatsAppMessage;
use App\Services\Tenancy\TenancyManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * WHATSAPP-USAGE-LEDGER-1 — guzre dinon ka register Meta ke apne aankRon se bharta hai.
 *
 * Pehla raasta ye tha ke `report_schedule_runs` ki raaton ko mojooda numbers se zarb de dein. Wo
 * takhmeena 112 nikla jabke Meta ka meter 90 keh raha tha — kyunke numbers beech me badle (khatri 5
 * se 7 hue) aur `report_schedule_runs` sirf "is raat chala" likhta hai, "kitne par chala" nahi. Us
 * 15% ke farq par bill bhejna tenant se zyada wasool karna hota, aur sabit karne ko kuch na hota.
 *
 * To ginti Meta se li jati hai, apne andaazay se nahi. Meta HALF_HOUR ke buckets deta hai, aur har
 * tenant ka cron apne alag waqt par chalta hai (khatri 00:30 PKT = 19:30 UTC, kashiffood 02:30 PKT =
 * 21:30 UTC) — is liye har bucket apne aap aik tenant par chala jata hai.
 *
 * Jo bucket kisi tenant ke waqt se mel na khaye wo CHHOD diya jata hai aur alag se ginwaya jata hai.
 * Un me mere apne test aur dobara bheje gaye message hain; unhe kisi tenant ke sar daalna us tenant
 * se wo paisa lena hota jo us ne kabhi istemal nahi kiya.
 */
class WhatsAppBackfillUsageCommand extends Command
{
    protected $signature = 'whatsapp:backfill-usage
        {--from= : Shuru ki tareekh (YYYY-MM-DD). Default: 30 din pehle}
        {--to= : Aakhri tareekh (YYYY-MM-DD). Default: aaj}
        {--yes : Likho. Is ke baghair sirf dikhata hai, kuch likhta nahi}';

    protected $description = 'Meta ke half-hourly aankRon se guzre dinon ka WhatsApp usage register bharta hai (default: dry run).';

    public function handle(TenancyManager $tenancy): int
    {
        if (! Schema::connection('master')->hasTable('whatsapp_messages')) {
            $this->error('whatsapp_messages table maujood nahi — pehle migrate karein.');

            return self::FAILURE;
        }

        $from = CarbonImmutable::parse($this->option('from') ?: now()->subDays(30)->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($this->option('to') ?: now()->toDateString())->endOfDay();
        $write = (bool) $this->option('yes');

        // ── 1. Har tenant ka UTC bucket nikalo ────────────────────────────────────────────────
        $slots = $this->tenantSlots($tenancy);
        if ($slots === []) {
            $this->error('Kisi tenant ka WhatsApp wala active schedule nahi mila.');

            return self::FAILURE;
        }

        $this->info('Tenant ke waqt (UTC bucket → tenant):');
        foreach ($slots as $utc => $t) {
            $this->line(sprintf('  %-6s  %s', $utc, $t['code']));
        }
        $this->newLine();

        // ── 2. Meta se aankRe lo ──────────────────────────────────────────────────────────────
        $points = $this->analytics($from, $to);
        if ($points === null) {
            return self::FAILURE;
        }

        // ── 3. Bucket → tenant ────────────────────────────────────────────────────────────────
        $perTenant = [];
        $orphans = [];

        foreach ($points as $p) {
            $sent = (int) ($p['sent'] ?? 0);
            $delivered = (int) ($p['delivered'] ?? 0);
            if ($sent === 0 && $delivered === 0) {
                continue;
            }

            $at = CarbonImmutable::createFromTimestampUTC((int) $p['start']);
            $key = $at->format('H:i');

            if (! isset($slots[$key])) {
                $orphans[] = ['at' => $at, 'sent' => $sent, 'delivered' => $delivered];

                continue;
            }

            $t = $slots[$key];
            // usage_date = tenant ke apne waqt ka din. khatri 00:30 PKT par bhejta hai, yani UTC me
            // pichhla din — seedha UTC lenay se mahine ki seema par ginti khisak jati.
            $usageDate = $at->setTimezone($t['tz'])->toDateString();
            $perTenant[$t['code']][$usageDate] = [
                'sent' => $sent,
                'delivered' => $delivered,
                'tenant_id' => $t['id'],
                'at' => $at,
            ];
        }

        // ── 4. Dikhao ─────────────────────────────────────────────────────────────────────────
        $rate = (float) config('services.whatsapp.rate_pkr');
        $cost = (float) config('services.whatsapp.cost_pkr');
        $grandBillable = 0;
        $grandAmount = 0.0;

        foreach ($perTenant as $code => $days) {
            ksort($days);
            $this->info("=== {$code} ===");
            $tSent = $tDel = 0;
            foreach ($days as $date => $d) {
                $tSent += $d['sent'];
                $tDel += $d['delivered'];
                $lost = $d['sent'] - $d['delivered'];
                $this->line(sprintf(
                    '  %s  bheje=%-3d pohanche=%-3d %s  PKR %s',
                    $date, $d['sent'], $d['delivered'],
                    $lost > 0 ? "(nakaam {$lost})" : '            ',
                    number_format($d['delivered'] * $rate, 2),
                ));
            }
            $amount = $tDel * $rate;
            $grandBillable += $tDel;
            $grandAmount += $amount;
            $this->line(sprintf(
                '  --- bheje %d, pohanche %d, nakaam %d → qabil-e-wasooli PKR %s (laagat %s)',
                $tSent, $tDel, $tSent - $tDel,
                number_format($amount, 2), number_format($tDel * $cost, 2),
            ));
            $this->newLine();
        }

        if ($orphans !== []) {
            $this->warn('Ye buckets kisi tenant ke waqt se mel nahi khate — CHHOD diye gaye:');
            foreach ($orphans as $o) {
                $this->line(sprintf(
                    '  %s UTC  bheje=%d pohanche=%d',
                    $o['at']->format('m-d H:i'), $o['sent'], $o['delivered'],
                ));
            }
            $this->line('  (mere apne test aur dobara bheje gaye message — kisi tenant ke sar nahi daale ja sakte)');
            $this->newLine();
        }

        $this->info(sprintf(
            'KUL qabil-e-wasooli: %d messages × PKR %s = PKR %s',
            $grandBillable, number_format($rate, 2), number_format($grandAmount, 2),
        ));

        if (! $write) {
            $this->newLine();
            $this->warn('Ye sirf dikhaya gaya hai. Likhne ke liye --yes lagayein.');

            return self::SUCCESS;
        }

        // ── 5. Likho ──────────────────────────────────────────────────────────────────────────
        $made = 0;
        DB::connection('master')->transaction(function () use ($perTenant, $rate, $cost, &$made) {
            foreach ($perTenant as $days) {
                foreach ($days as $date => $d) {
                    // Pehle se backfill ho chuka hai to dobara mat karo — warna har chalane par
                    // ginti dugni hoti jayegi aur bill us hisaab se barhta jayega.
                    $already = WhatsAppMessage::where('tenant_id', $d['tenant_id'])
                        ->where('usage_date', $date)
                        ->where('source', 'backfill')
                        ->count();
                    if ($already > 0) {
                        continue;
                    }

                    for ($i = 0; $i < $d['sent']; $i++) {
                        WhatsAppMessage::create([
                            'tenant_id' => $d['tenant_id'],
                            'source' => 'backfill',
                            'template' => 'backfill',
                            'to' => null, // Meta ginti deta hai, number nahi — gharhna nahi hai
                            'wamid' => null,
                            'status' => $i < $d['delivered'] ? 'delivered' : 'failed',
                            'failure_reason' => $i < $d['delivered'] ? null : 'Meta ke aankRon me pohancha nahi (backfill)',
                            'rate_charged' => $rate,
                            'provider_cost' => $cost,
                            'usage_date' => $date,
                            'sent_at' => $d['at'],
                        ]);
                        $made++;
                    }
                }
            }
        });

        $this->info("{$made} rows likhi gayin.");

        return self::SUCCESS;
    }

    /** @return array<string, array{id:int, code:string, tz:string}> UTC "H:i" → tenant */
    private function tenantSlots(TenancyManager $tenancy): array
    {
        $slots = [];

        foreach (Tenant::where('status', 'active')->get() as $tenant) {
            $channels = (array) ($tenant->report_channels ?? []);
            if (! in_array('whatsapp', array_map('strtolower', $channels), true)) {
                continue;
            }

            try {
                $tenancy->activate($tenant);
            } catch (\Throwable) {
                continue;
            }
            if (! Schema::connection('tenant')->hasTable('report_schedules')) {
                continue;
            }

            $tz = $tenant->timezone ?: config('app.timezone');

            foreach (DB::connection('tenant')->table('report_schedules')->where('is_active', true)->get() as $s) {
                $utc = CarbonImmutable::parse('today '.$s->send_time, $tz)->setTimezone('UTC')->format('H:i');
                $slots[$utc] = ['id' => $tenant->id, 'code' => $tenant->tenant_code, 'tz' => $tz];
            }
        }

        return $slots;
    }

    /** @return list<array<string, mixed>>|null */
    private function analytics(CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        $waba = (string) config('services.whatsapp.waba_id');
        if ($waba === '') {
            $this->error('WHATSAPP_WABA_ID set nahi hai.');

            return null;
        }

        $resp = Http::withToken((string) config('services.whatsapp.token'))
            ->timeout(30)
            ->get(sprintf(
                '%s/%s/%s',
                rtrim((string) config('services.whatsapp.base_url'), '/'),
                config('services.whatsapp.version'),
                $waba,
            ), [
                'fields' => sprintf(
                    'analytics.start(%d).end(%d).granularity(HALF_HOUR)',
                    $from->getTimestamp(), $to->getTimestamp(),
                ),
            ]);

        if ($resp->failed()) {
            // Jawab me Meta poori darkhwast lauta deta hai, token samet — sirf paigham nikalo.
            $this->error('Meta analytics: '.($resp->json('error.message') ?? 'HTTP '.$resp->status()));

            return null;
        }

        return $resp->json('analytics.data_points') ?? [];
    }
}
