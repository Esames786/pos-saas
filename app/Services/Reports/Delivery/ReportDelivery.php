<?php

namespace App\Services\Reports\Delivery;

/**
 * WHATSAPP-REPORT-CHANNEL-1 — one report, on its way somewhere.
 *
 * The three places that send a report (POS Quick Report, Report Center, the nightly cron) each built
 * their own mail and called Mail::to() themselves. That was fine while email was the only channel; the
 * moment a second one exists it means the same decision is made in three places, and the first one
 * anybody forgets to update is the one that keeps emailing while the others move on.
 *
 * So the callers now describe WHAT is being sent, and the dispatcher decides HOW.
 *
 * Recipients are deliberately NOT here: the three callers resolve them differently today (the cron has
 * the schedule's own list, Quick Report borrows the first schedule's, Report Center takes the owner),
 * and collapsing those into one rule would change who gets what. Each caller keeps its own answer.
 */
final class ReportDelivery
{
    /**
     * @param array<string, mixed> $filters  normalised engine filters
     * @param list<string>         $sections which sections the reader asked for
     * @param string               $format   'csv' | 'a4_pdf'
     */
    public function __construct(
        public readonly string $businessName,
        public readonly string $label,
        public readonly array $filters,
        public readonly array $sections,
        public readonly string $format = 'csv',
        public readonly ?string $fileName = null,
    ) {}
}
