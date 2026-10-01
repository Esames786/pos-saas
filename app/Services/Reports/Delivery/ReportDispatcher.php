<?php

namespace App\Services\Reports\Delivery;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * WHATSAPP-REPORT-CHANNEL-1 — the ONE door every report leaves by.
 *
 * Three places send a report: the POS Quick Report button, the Report Center button, and the nightly
 * cron. Each built its own mail and called Mail::to() itself. With one channel that was merely
 * repetitive; with two it is a trap — the path somebody forgets keeps emailing while the others move
 * to WhatsApp, and the owner gets some reports one way and some the other with no way to tell why.
 *
 * So they all come through here. A guard asserts there is no report-sending Mail::to() left outside
 * this class, because "we updated all three" is exactly the kind of claim that is true until it isn't.
 *
 * WHO gets it is still the caller's business. The three resolve recipients differently today — the
 * cron uses the schedule's own list, Quick Report borrows the first schedule's, Report Center takes the
 * tenant owner — and folding those into one rule would quietly change who receives what. That is a
 * decision for its own sprint, not a side effect of this one.
 */
final class ReportDispatcher
{
    /** @var array<string, ReportChannel> */
    private array $channels;

    public function __construct(EmailChannel $email)
    {
        $this->channels = [$email->key() => $email];
    }

    /**
     * Channels this branch (or, failing that, this tenant) delivers on.
     *
     * NULL at branch level means "ask the tenant" — so a single-branch tenant sets it once and a
     * two-branch tenant can give each branch its own answer. The default is ['email'], which is what
     * every tenant does today: a tenant nobody has touched keeps behaving exactly as it does now.
     *
     * Unknown channel names are dropped rather than thrown on. A settings row naming a channel this
     * build does not have must not take the nightly report down with it.
     *
     * @return list<string>
     */
    public function channelsFor(?int $branchId = null): array
    {
        $configured = $this->branchChannels($branchId) ?? $this->tenantChannels() ?? ['email'];

        $known = array_values(array_filter(
            array_map(fn ($c) => strtolower(trim((string) $c)), $configured),
            fn ($c) => isset($this->channels[$c]),
        ));

        return $known ?: ['email'];
    }

    /**
     * Send one report on one channel. Throws on failure — the scheduler turns that into a per-channel
     * retry, and the buttons turn it into a message the operator can read.
     *
     * @param list<string> $recipients
     */
    public function send(string $channel, ReportDelivery $delivery, array $recipients): void
    {
        if (! isset($this->channels[$channel])) {
            throw new RuntimeException('Unknown report channel: '.$channel);
        }

        $this->channels[$channel]->send($delivery, $recipients);
    }

    /** @return list<string>|null */
    private function branchChannels(?int $branchId): ?array
    {
        if (! $branchId) {
            return null;
        }

        try {
            $raw = DB::connection('tenant')->table('branches')->where('id', $branchId)->value('report_channels');
        } catch (\Throwable) {
            return null; // column not migrated on this tenant yet — fall through to the tenant default
        }

        return $this->decode($raw);
    }

    /** @return list<string>|null */
    private function tenantChannels(): ?array
    {
        if (! app()->bound('tenant')) {
            return null;
        }

        return $this->decode(app('tenant')->report_channels ?? null);
    }

    /** @return list<string>|null */
    private function decode(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $value = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($value) && $value !== [] ? array_values($value) : null;
    }
}
