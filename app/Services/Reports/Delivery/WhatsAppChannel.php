<?php

namespace App\Services\Reports\Delivery;

use App\Models\Master\Tenant;
use App\Services\Reports\SalesReportEngine;
use App\Services\Reports\Sharing\ReportShareLinkService;
use RuntimeException;

/**
 * WHATSAPP-REPORT-CHANNEL-1 — the report as a WhatsApp message.
 *
 * The message carries a SUMMARY, not the report: net sales, orders, cash, and a link. An A4 PDF on a
 * phone is unreadable without pinching, and the owner wants one number at a glance. The detail sits
 * behind a link that expires.
 *
 * The template is approved and therefore FROZEN — name, language and the five body variables cannot
 * drift without a new review. Changing the text here without re-submitting it to Meta does not produce
 * a different message; it produces a rejected send.
 */
final class WhatsAppChannel implements ReportChannel
{
    public function __construct(
        private readonly WhatsAppClient $client,
        private readonly SalesReportEngine $engine,
        private readonly ReportShareLinkService $links,
    ) {}

    public function key(): string
    {
        return 'whatsapp';
    }

    public function send(ReportDelivery $delivery, array $recipients): void
    {
        $numbers = self::normalise($recipients);

        if ($numbers === []) {
            throw new RuntimeException('No valid WhatsApp number is configured for this report.');
        }

        if (! app()->bound('tenant')) {
            throw new RuntimeException('WhatsApp report needs a tenant context.');
        }

        /** @var Tenant $tenant */
        $tenant = app('tenant');
        $overview = $this->engine->overview($delivery->filters);
        $url = $this->links->create($tenant, $delivery->filters, $delivery->sections, $delivery->label);

        $components = [
            [
                'type' => 'body',
                'parameters' => array_map(fn ($t) => ['type' => 'text', 'text' => $t], [
                    $delivery->businessName,
                    $delivery->label,
                    number_format((float) ($overview['net_sales'] ?? 0), 0),
                    number_format((float) ($overview['orders'] ?? 0), 0),
                    number_format((float) ($overview['cash_collected'] ?? 0), 0),
                ]),
            ],
            [
                // The approved button is a Dynamic URL: Meta appends this to the fixed base, so only
                // the token travels here, never the whole address.
                'type' => 'button',
                'sub_type' => 'url',
                'index' => '0',
                'parameters' => [['type' => 'text', 'text' => basename($url)]],
            ],
        ];

        $failures = [];

        foreach ($numbers as $number) {
            try {
                $this->client->sendTemplate(
                    $number,
                    (string) config('services.whatsapp.template'),
                    (string) config('services.whatsapp.language'),
                    $components,
                );
            } catch (\Throwable $e) {
                // One bad number must not stop the rest. A wrong digit in one owner's entry would
                // otherwise silently cost everyone else on the list their report.
                $failures[] = $this->mask($number).': '.$e->getMessage();
            }
        }

        if ($failures !== [] && count($failures) === count($numbers)) {
            throw new RuntimeException('WhatsApp: '.implode(' | ', $failures));
        }
    }

    /**
     * PUBLIC and static on purpose: the settings screen normalises with the EXACT same rule when it
     * saves. Two copies would drift, and the day they did a number would save happily and then never
     * receive anything — with nothing on any screen to show why.
     *
     * Meta wants `923001234567` — no plus, no leading zero, no spaces or dashes. People write
     * `0300-1234567`. Without this the number looks right in the settings screen and the message just
     * never arrives, which is the hardest kind of failure to notice.
     *
     * @param  list<string> $recipients
     * @return list<string>
     */
    public static function normalise(array $recipients): array
    {
        $clean = [];

        foreach ($recipients as $raw) {
            $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';

            if (str_starts_with($digits, '0')) {
                $digits = '92'.ltrim($digits, '0');
            }

            // 92 + 10 digits. Anything shorter or longer is not a Pakistani mobile and is dropped
            // rather than sent — a half-typed number would otherwise reach a stranger.
            if (preg_match('/^92\d{10}$/', $digits)) {
                $clean[$digits] = true;
            }
        }

        // strval() is not cosmetic: the numbers are array KEYS above (to dedupe), and PHP turns a
        // numeric string key into an int. They would then be stored as JSON integers and handed to
        // Meta as integers, where a string is expected.
        return array_map('strval', array_keys($clean));
    }

    /** Never put a full customer number into a failure string that lands in last_failure. */
    private function mask(string $number): string
    {
        return substr($number, 0, 5).'•••'.substr($number, -3);
    }
}
