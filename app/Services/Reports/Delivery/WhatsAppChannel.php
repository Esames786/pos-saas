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
    /** The original 5-variable summary. Approved 30 Sep 2026. */
    public const TEMPLATE_SUMMARY = 'daily_sales_report';

    /** The full OVERALL + CASH FROM SALES block, as the PDF prints it. */
    public const TEMPLATE_DETAILED = 'daily_sales_report_v2';
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

        $template = (string) config('services.whatsapp.template');

        $components = [
            [
                'type' => 'body',
                'parameters' => array_map(
                    fn ($t) => ['type' => 'text', 'text' => $t],
                    $this->bodyParams($template, $delivery, $overview),
                ),
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
                    $template,
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
     * The body variables, in the order the APPROVED template declares them.
     *
     * Keyed by template name because the template is frozen the moment Meta approves it: a different
     * set of figures is a different template, not a different call. Keeping both here means the new
     * one can be submitted and reviewed while the old one keeps sending, and the switch is one line
     * in .env — no deploy, no window where reports stop.
     *
     * An unknown name throws rather than guessing a shape. Sending the wrong number of parameters
     * gets a 400 from Meta that says nothing about .env, and every report would fail at once.
     *
     * Figures and formatting match the PDF's OVERALL / CASH FROM SALES block exactly (print.blade.php
     * lines 300-320). The owner reads both; two renderings of "the same day" that disagree by a
     * rounding rule cost more trust than they save characters.
     *
     * @param  array<string, mixed> $o
     * @return list<string>
     */
    private function bodyParams(string $template, ReportDelivery $delivery, array $o): array
    {
        // Same formatters the PDF uses: money to 2dp with separators, qty with trailing zeros cut.
        $money = fn (string $k) => number_format((float) ($o[$k] ?? 0), 2);
        $qty = fn (string $k) => rtrim(rtrim(number_format((float) ($o[$k] ?? 0), 3, '.', ''), '0'), '.');

        return match ($template) {
            self::TEMPLATE_SUMMARY => [
                $delivery->businessName,
                $delivery->label,
                number_format((float) ($o['net_sales'] ?? 0), 0),
                number_format((float) ($o['orders'] ?? 0), 0),
                // UNCHANGED on purpose, and this deploy must not move it. The label here reads
                // "Cash collected" and is frozen until Meta approves a new template, so putting the
                // net figure under it would swap one wrong reading for another. TEMPLATE_DETAILED
                // carries BOTH, each under its own name — the fix belongs there, not here.
                number_format((float) ($o['cash_collected'] ?? 0), 0),
            ],
            self::TEMPLATE_DETAILED => [
                $delivery->businessName,
                $delivery->label,
                number_format((float) ($o['orders'] ?? 0), 0),
                $qty('sold_qty'),
                $qty('returned_qty'),
                $qty('net_qty'),
                $money('gross_sales'),
                // The minus signs live in the template's STATIC text, exactly as the PDF renders
                // them, so these stay plain positive numbers.
                $money('discount'),
                $money('tax'),
                $money('service_charge'),
                $money('delivery_charge'),
                $money('tips'),
                $money('grand_total'),
                $money('returns_amount'),
                $money('net_sales'),
                $money('cash_collected'),
                $money('cash_refunds'),
                $money('net_cash_from_sales'),
            ],
            default => throw new RuntimeException(
                'Unknown WhatsApp template "'.$template.'". WHATSAPP_TEMPLATE must be one of: '
                .self::TEMPLATE_SUMMARY.', '.self::TEMPLATE_DETAILED.'.'
            ),
        };
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
