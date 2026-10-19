<?php

namespace App\Services\Reports;

use App\Models\Master\WhatsAppMessage;
use App\Services\Reports\Delivery\ReportDelivery;
use App\Services\Reports\Delivery\ReportDispatcher;
use App\Services\Reports\Delivery\WhatsAppChannel;
use App\Services\Saas\BillingRates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REPORT-DELIVERY-V2-1 — the one "Daily report delivery" card in Report Center.
 *
 * It replaces two separate cards (a tenant-wide "Report delivery" box and a "Scheduled sales reports"
 * table) with three plain questions: how (WhatsApp / Email, each switched on with its price shown),
 * when (the moment the day's last shift closes, or a fixed time), and what (Short / Full / chosen parts).
 *
 * It stores everything where the old screens stored it, so the nightly report, both Send buttons and
 * POS Quick Report keep reading the same rows:
 *   tenants.report_channels / report_whatsapp      — unchanged shape (lists)
 *   tenants.report_whatsapp_names                  — new, number => name, read only by this card
 *   report_schedules (the first daily one)         — recipients, sections, time, and send_trigger
 *   report_schedules "Weekly summary" / "Monthly summary" — switched on/off, never deleted
 */
final class ReportDeliverySettings
{
    public const WEEKLY_NAME = 'Weekly summary';

    public const MONTHLY_NAME = 'Monthly summary';

    public const DAILY_NAME = 'Daily owner report';

    /** Plain words for each report part — what the owner sees instead of the section keys. */
    public const PARTS = [
        'overview' => ['Overview', 'total sale, orders and cash'],
        'categories' => ['Categories', 'sale by menu group'],
        'items' => ['Items', 'each dish sold'],
        'category_items' => ['Items by category', 'dishes under their menu group'],
        'deals' => ['Deals', 'deal and combo sales'],
        'waiters' => ['Waiters', 'sale per waiter'],
        'order_types' => ['Order types', 'dine in, takeaway, delivery'],
        'order_type_combos' => ['Deals by order type', 'which deals sold where'],
        'cancellations' => ['Cancellations', 'what was cancelled and by whom'],
        'detailed' => ['Every bill', 'each sale, line by line'],
        'cash_bank' => ['Cash and bank', 'money in each drawer and bank'],
        'partner_share' => ['Partner share', 'what each partner owes'],
    ];

    private const SHORT = ['overview', 'cash_bank'];

    /** "Full" leaves out the two parts that make a long report much longer without a new figure. */
    private const NOT_IN_FULL = ['detailed', 'category_items'];

    public function __construct(
        private readonly ReportScheduleService $schedules,
        private readonly ReportDispatcher $dispatcher,
        private readonly BillingRates $rates,
    ) {}

    /** @param list<string> $allowed */
    public function presetSections(string $preset, array $allowed): array
    {
        return match ($preset) {
            'short' => array_values(array_intersect(self::SHORT, $allowed)),
            'full' => array_values(array_diff($allowed, self::NOT_IN_FULL)),
            default => [],
        };
    }

    /** @param list<string> $allowed */
    public function read(array $allowed): array
    {
        $tenant = app('tenant');
        $channels = (array) ($tenant->report_channels ?: ['email']);
        $names = (array) ($tenant->report_whatsapp_names ?? []);
        $main = $this->mainSchedule();
        $weekly = $this->namedSchedule('weekly', self::WEEKLY_NAME);
        $monthly = $this->namedSchedule('monthly', self::MONTHLY_NAME);

        $sections = $main ? array_values((array) json_decode((string) $main->sections, true)) : $this->presetSections('full', $allowed);
        $preset = 'custom';
        foreach (['short', 'full'] as $p) {
            $want = $this->presetSections($p, $allowed);
            sort($want);
            $have = $sections;
            sort($have);
            if ($want === $have) {
                $preset = $p;
            }
        }

        $trigger = $main->send_trigger ?? 'time';

        return [
            'whatsapp_on' => in_array('whatsapp', $channels, true),
            'email_on' => in_array('email', $channels, true),
            'contacts' => array_map(fn ($n) => [
                'number' => (string) $n,
                'name' => (string) ($names[(string) $n] ?? ''),
                'pretty' => $this->pretty((string) $n),
            ], (array) ($tenant->report_whatsapp ?? [])),
            'emails' => $main ? array_values((array) json_decode((string) ($main->recipient_emails ?? '[]'), true)) : [],
            'owner_email' => (string) ($tenant->owner_email ?? ''),
            'when' => $trigger === 'shift_close' ? 'shift_close' : 'time',
            'send_time' => $trigger === 'shift_close' ? '02:30' : (string) ($main->send_time ?? '08:00'),
            'fallback_time' => $trigger === 'shift_close' ? (string) ($main->send_time ?? '06:00') : '06:00',
            'weekly' => (bool) ($weekly?->is_active),
            'monthly' => (bool) ($monthly?->is_active),
            'preset' => $preset,
            'sections' => $sections,
            'parts' => array_intersect_key(self::PARTS, array_flip($allowed)),
            'short_parts' => $this->presetSections('short', $allowed),
            'full_parts' => $this->presetSections('full', $allowed),
            'rate' => $this->rates->whatsappRatePkr(),
            'last_failure' => (string) ($main->last_failure ?? ''),
            'recent' => $this->recent(),
            'other_schedules' => DB::connection('tenant')->table('report_schedules')
                ->whereNotIn('id', array_filter([$main?->id, $weekly?->id, $monthly?->id]))
                ->orderBy('id')->get(),
        ];
    }

    /**
     * Save the card. Throws ValidationException with a plain-words message on anything that would
     * leave the owner believing a report will arrive when it will not.
     *
     * @param list<string> $allowed
     */
    public function save(array $data, array $allowed, ?int $userId): string
    {
        $waOn = ! empty($data['whatsapp_on']);
        $emailOn = ! empty($data['email_on']);
        if (! $waOn && ! $emailOn) {
            throw ValidationException::withMessages(['delivery' => 'Turn on WhatsApp or Email — with both off the report has nowhere to go.']);
        }

        // Pair every typed number with its name BEFORE normalising, so a name stays with its number.
        $numbers = [];
        $names = [];
        $rejected = 0;
        foreach ((array) ($data['wa_numbers'] ?? []) as $i => $raw) {
            if (trim((string) $raw) === '') {
                continue;
            }
            $one = WhatsAppChannel::normalise([(string) $raw]);
            if ($one === []) {
                $rejected++;

                continue;
            }
            $number = (string) $one[0];
            if (in_array($number, $numbers, true)) {
                continue;
            }
            $numbers[] = $number;
            $name = mb_substr(trim((string) (($data['wa_names'] ?? [])[$i] ?? '')), 0, 40);
            if ($name !== '') {
                $names[$number] = $name;
            }
        }
        if ($waOn && $numbers === []) {
            throw ValidationException::withMessages(['wa_numbers' => 'WhatsApp is on but no usable mobile number was given. Use 0300 1234567 or 92 300 1234567.']);
        }

        $emails = [];
        foreach ((array) ($data['emails'] ?? []) as $email) {
            $email = strtolower(trim((string) $email));
            if ($email === '') {
                continue;
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw ValidationException::withMessages(['emails' => '"'.$email.'" is not an email address.']);
            }
            $emails[$email] = true;
        }
        $emails = array_keys($emails);
        if ($emailOn && $emails === [] && ! filter_var((string) (app('tenant')->owner_email ?? ''), FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['emails' => 'Email is on but there is no address — add one, or set the owner email first.']);
        }

        $when = ($data['when'] ?? 'time') === 'shift_close' ? 'shift_close' : 'time';
        $time = $when === 'shift_close' ? (string) ($data['fallback_time'] ?? '06:00') : (string) ($data['send_time'] ?? '08:00');
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw ValidationException::withMessages(['send_time' => 'Pick a time like 06:00.']);
        }

        $preset = in_array($data['preset'] ?? '', ['short', 'full', 'custom'], true) ? $data['preset'] : 'full';
        $sections = $preset === 'custom'
            ? array_values(array_intersect($allowed, (array) ($data['sections'] ?? [])))
            : $this->presetSections($preset, $allowed);
        if ($sections === []) {
            throw ValidationException::withMessages(['sections' => 'Tick at least one part for the report.']);
        }

        $tenant = app('tenant');
        $tenant->report_channels = array_values(array_filter([$emailOn ? 'email' : null, $waOn ? 'whatsapp' : null]));
        $tenant->report_whatsapp = $numbers;
        $tenant->report_whatsapp_names = $names === [] ? null : $names;
        $tenant->save();

        $c = DB::connection('tenant');
        $common = [
            'sections' => json_encode($sections),
            'recipient_emails' => $emails === [] ? null : json_encode($emails),
            'updated_at' => now(),
        ];

        $c->transaction(function () use ($c, $common, $when, $time, $userId) {
            $main = $this->mainSchedule();
            $row = $common + ['send_time' => $time, 'send_trigger' => $when, 'is_active' => true];
            if ($main) {
                $c->table('report_schedules')->where('id', $main->id)->update($row);
            } else {
                $c->table('report_schedules')->insert($row + [
                    'name' => self::DAILY_NAME, 'frequency' => 'daily', 'delivery_format' => 'a4_pdf',
                    'created_by_user_id' => $userId, 'created_at' => now(),
                ]);
            }
        });

        $this->toggleSummary('weekly', self::WEEKLY_NAME, ! empty($data['weekly']), $common, $time, $userId);
        $this->toggleSummary('monthly', self::MONTHLY_NAME, ! empty($data['monthly']), $common, $time, $userId);

        $how = implode(' and ', array_filter([$waOn ? 'WhatsApp to '.count($numbers).' number'.(count($numbers) === 1 ? '' : 's') : null, $emailOn ? 'Email' : null]));
        $note = 'Saved. The daily report goes by '.$how.', '
            .($when === 'shift_close' ? 'when the day\'s last shift closes (or at '.$time.' if no shift is closed by then).' : 'every day at '.$time.'.');
        if ($rejected > 0) {
            $note .= ' '.$rejected.' entr'.($rejected === 1 ? 'y was' : 'ies were').' not a mobile number and '.($rejected === 1 ? 'was' : 'were').' left out.';
        }

        return $note;
    }

    /**
     * "Send test": today's figures so far, to one saved number or to everyone on the card. Returns the
     * message for the operator; throws on a failure so they see it.
     */
    public function sendTest(?string $onlyNumber, array $allowed): string
    {
        $tenant = app('tenant');
        $today = now($this->schedules->timezone())->toDateString();
        $state = $this->read($allowed);
        $delivery = new ReportDelivery(
            businessName: (string) $tenant->business_name,
            label: $today.' (test — today so far)',
            filters: app(SalesReportEngine::class)->normalizeFilters(['date_from' => $today, 'date_to' => $today]),
            sections: $state['sections'] ?: ['overview'],
            format: 'a4_pdf',
            fileName: 'sales-report-test-'.$today.'.pdf',
        );

        if ($onlyNumber !== null) {
            $one = WhatsAppChannel::normalise([$onlyNumber]);
            if ($one === [] || ! in_array((string) $one[0], array_column($state['contacts'], 'number'), true)) {
                throw ValidationException::withMessages(['wa_numbers' => 'Save the number first, then send it a test.']);
            }
            $this->dispatcher->send('whatsapp', $delivery, [(string) $one[0]]);
            $who = collect($state['contacts'])->firstWhere('number', (string) $one[0]);

            return 'Test sent to '.(($who['name'] ?? '') !== '' ? $who['name'].' (' : '').$this->pretty((string) $one[0]).(($who['name'] ?? '') !== '' ? ')' : '')
                .' — charged as 1 message.';
        }

        $sent = [];
        if ($state['whatsapp_on'] && $state['contacts'] !== []) {
            $this->dispatcher->send('whatsapp', $delivery, array_column($state['contacts'], 'number'));
            $sent[] = 'WhatsApp to '.count($state['contacts']).' number'.(count($state['contacts']) === 1 ? '' : 's');
        }
        if ($state['email_on']) {
            $to = $state['emails'] ?: array_filter([$state['owner_email']]);
            $this->dispatcher->send('email', $delivery, array_values($to));
            $sent[] = 'Email to '.count($to).' address'.(count($to) === 1 ? '' : 'es');
        }

        return $sent === [] ? 'Nothing is switched on to send a test to.' : 'Test sent: '.implode(' and ', $sent).'.';
    }

    /**
     * The last deliveries, newest first: when, which service, what happened, and why it went.
     * WhatsApp shows per-number results — a half-failed send used to look identical to a good one.
     */
    public function recent(int $limit = 8): array
    {
        $c = DB::connection('tenant');
        $tz = $this->schedules->timezone();
        $hasVia = \Illuminate\Support\Facades\Schema::connection('tenant')->hasColumn('report_schedule_runs', 'via');
        $runs = $c->table('report_schedule_runs as r')
            ->join('report_schedules as s', 's.id', '=', 'r.report_schedule_id')
            ->orderByDesc('r.id')->limit($limit)
            ->get(array_merge(['r.channel', 'r.period_key', 'r.created_at', 's.name'], $hasVia ? ['r.via'] : []));

        $names = (array) (app('tenant')->report_whatsapp_names ?? []);
        $tenantId = (int) app('tenant')->id;

        return $runs->map(function ($r) use ($tz, $names, $tenantId) {
            $at = Carbon::parse($r->created_at, config('app.timezone'));
            $result = 'Sent';
            $bad = false;
            if ($r->channel === 'whatsapp' && $tenantId > 0) {
                try {
                    $rows = WhatsAppMessage::query()->where('tenant_id', $tenantId)
                        ->whereBetween('sent_at', [$at->copy()->subMinute(), $at->copy()->addMinutes(10)])
                        ->get(['to', 'status', 'failure_reason']);
                    $failed = $rows->where('status', 'failed');
                    if ($rows->isNotEmpty()) {
                        $result = ($rows->count() - $failed->count()).' of '.$rows->count().' numbers sent';
                        if ($failed->isNotEmpty()) {
                            $bad = true;
                            $result .= ' — failed: '.$failed->map(fn ($f) => ($names[(string) $f->to] ?? $this->pretty((string) $f->to)))->implode(', ');
                        }
                    }
                } catch (\Throwable) {
                    // the usage register is billing's; the history line simply says "Sent"
                }
            }

            return [
                'when' => $at->copy()->setTimezone($tz)->format('d M, h:i A'),
                'day' => (string) $r->period_key,
                'service' => $r->channel === 'whatsapp' ? 'WhatsApp' : 'Email',
                'result' => $result,
                'bad' => $bad,
                'why' => match ($r->via ?? null) {
                    'shift_close' => 'Last shift closed',
                    'time' => 'Set time',
                    default => 'Set time',
                },
                'report' => (string) $r->name,
            ];
        })->all();
    }

    public function pretty(string $number): string
    {
        return strlen($number) === 12 ? '+92 '.substr($number, 2, 3).' '.substr($number, 5) : $number;
    }

    private function mainSchedule(): ?object
    {
        $c = DB::connection('tenant');

        return $c->table('report_schedules')->where('frequency', 'daily')->where('is_active', true)->orderBy('id')->first()
            ?? $c->table('report_schedules')->where('frequency', 'daily')->orderBy('id')->first();
    }

    private function namedSchedule(string $frequency, string $name): ?object
    {
        return DB::connection('tenant')->table('report_schedules')
            ->where('frequency', $frequency)->where('name', $name)->orderBy('id')->first();
    }

    private function toggleSummary(string $frequency, string $name, bool $on, array $common, string $time, ?int $userId): void
    {
        $c = DB::connection('tenant');
        $row = $this->namedSchedule($frequency, $name);

        if (! $on) {
            if ($row) {
                $c->table('report_schedules')->where('id', $row->id)->update(['is_active' => false, 'updated_at' => now()]);
            }

            return;
        }

        $values = $common + ['send_time' => $time, 'is_active' => true];
        if ($row) {
            $c->table('report_schedules')->where('id', $row->id)->update($values);

            return;
        }

        $c->table('report_schedules')->insert($values + [
            'name' => $name,
            'frequency' => $frequency,
            'weekday' => $frequency === 'weekly' ? 1 : null,
            'day_of_month' => $frequency === 'monthly' ? 1 : null,
            'delivery_format' => 'a4_pdf',
            'created_by_user_id' => $userId,
            'created_at' => now(),
        ]);
    }
}
