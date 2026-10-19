<?php

namespace App\Services\Reports\Sharing;

use App\Models\Master\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WHATSAPP-REPORT-CHANNEL-1 (qadam 4) — the short-lived link a report message carries.
 *
 * The message itself carries only a summary: business, date, net sales, orders, cash. The full report
 * sits behind this link, which is the whole reason the message is safe to send at all — a WhatsApp
 * message can be forwarded, screenshotted, or simply read over someone's shoulder.
 *
 * Two things make the link safe rather than merely obscure:
 *
 *  - the token is random, not derived from anything guessable (no tenant id, no date);
 *  - it EXPIRES. A shop's takings are not something that should stay readable in an old chat.
 */
class ReportShareLinkService
{
    /** How long a report link stays open. Long enough to read the next morning, short enough to rot. */
    public const LIFETIME_HOURS = 48;

    /**
     * Mint a link for this report.
     *
     * @param  array<string, mixed> $filters
     * @param  list<string>         $sections
     * @return string the full URL that goes in the message
     */
    public function create(Tenant $tenant, array $filters, array $sections, string $label): string
    {
        $token = Str::lower(Str::random(32));

        DB::connection('master')->table('report_share_links')->insert([
            'token' => $token,
            'tenant_id' => $tenant->id,
            'filters' => json_encode($filters),
            'sections' => json_encode(array_values($sections)),
            'label' => mb_substr($label, 0, 120),
            'expires_at' => now()->addHours(self::LIFETIME_HOURS),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return rtrim((string) config('app.url'), '/').'/r/'.$token;
    }

    /**
     * Find a live link, or null.
     *
     * Expiry is checked HERE rather than left to a cleanup job: a row that outlives its welcome because
     * nobody ran the sweeper must still refuse to open.
     */
    public function resolve(string $token): ?object
    {
        $row = DB::connection('master')->table('report_share_links')->where('token', $token)->first();

        if (! $row) {
            return null;
        }

        if (Carbon::parse($row->expires_at)->isPast()) {
            return null;
        }

        return $row;
    }

    /** Record that someone opened it — so an unexpected reader leaves a trace. */
    public function markOpened(string $token): void
    {
        DB::connection('master')->table('report_share_links')->where('token', $token)->update([
            'opens' => DB::raw('opens + 1'),
            'first_opened_at' => DB::raw('COALESCE(first_opened_at, NOW())'),
            'updated_at' => now(),
        ]);
    }
}
