<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Services\Reports\ReportScheduleService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Idempotently configures one tenant's daily previous-business-day A4 owner report. */
class ConfigureScheduledSalesReportCommand extends Command
{
    private const DEFAULT_SECTIONS = [
        'overview', 'categories', 'items', 'waiters', 'order_types',
        'order_type_combos', 'cancellations', 'cash_bank',
    ];

    protected $signature = 'reports:configure-daily-a4
        {tenant : Tenant code}
        {--name=Daily Owner A4 Sales Report : Stable schedule name used for idempotent updates}
        {--time=00:30 : Send time in the tenant branch timezone}
        {--owner-email= : Update the tenant default owner email}
        {--recipient=* : Explicit recipient; repeat for multiple recipients}';

    protected $description = 'Create or update a tenant daily A4 Sales Report Centre email schedule without sending immediately.';

    public function handle(TenancyManager $tenancy, ReportScheduleService $schedules): int
    {
        $tenant = Tenant::where('tenant_code', (string) $this->argument('tenant'))->first();
        if (! $tenant) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }

        $ownerEmail = strtolower(trim((string) $this->option('owner-email')));
        if ($ownerEmail !== '') {
            if (filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) === false) {
                $this->error('Invalid --owner-email.');

                return self::FAILURE;
            }
        }

        $recipients = array_values(array_unique(array_map(
            fn ($email) => strtolower(trim((string) $email)),
            (array) $this->option('recipient'),
        )));
        if ($recipients === []) {
            $recipients = array_filter([$ownerEmail !== '' ? $ownerEmail : (string) $tenant->owner_email]);
        }
        foreach ($recipients as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $this->error("Invalid recipient: {$email}");

                return self::FAILURE;
            }
        }
        if ($recipients === []) {
            $this->error('At least one recipient or an owner email is required.');

            return self::FAILURE;
        }

        $time = (string) $this->option('time');
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $this->error('--time must use 24-hour HH:MM format.');

            return self::FAILURE;
        }

        $tenancy->activate($tenant->fresh());
        if (! Schema::connection('tenant')->hasColumns('report_schedules', ['recipient_emails', 'delivery_format'])) {
            $this->error('Run tenant migrations before configuring A4 report delivery.');

            return self::FAILURE;
        }
        if ($ownerEmail !== '') {
            $tenant->update(['owner_email' => $ownerEmail]);
        }

        $name = (string) $this->option('name');
        $existing = DB::connection('tenant')->table('report_schedules')->where('name', $name)->first();
        $values = [
            'sections' => json_encode(self::DEFAULT_SECTIONS),
            'recipient_emails' => json_encode($recipients),
            'delivery_format' => 'a4_pdf',
            'frequency' => 'daily',
            'weekday' => null,
            'day_of_month' => null,
            'send_time' => $time,
            'is_active' => true,
            'last_failure' => null,
            'updated_at' => now(),
        ];
        if ($existing) {
            DB::connection('tenant')->table('report_schedules')->where('id', $existing->id)->update($values);
        } else {
            DB::connection('tenant')->table('report_schedules')->insert($values + [
                'name' => $name,
                'created_by_user_id' => null,
                'created_at' => now(),
            ]);
        }

        $this->info("Configured {$tenant->tenant_code}: daily {$time} ({$schedules->timezone()})");
        $this->line('Recipients: '.implode(', ', $recipients));
        $this->line('Period: previous business date; format: A4 PDF; no email sent by this command.');

        return self::SUCCESS;
    }
}
