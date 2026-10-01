<?php

namespace App\Services\Reports\Delivery;

/** WHATSAPP-REPORT-CHANNEL-1 — one way of getting a report to a person. */
interface ReportChannel
{
    /** Stored in report_schedule_runs.channel and in report_channels settings. */
    public function key(): string;

    /**
     * Deliver, or throw. Throwing is how a channel reports failure: the scheduler frees that
     * channel's claim and retries it on the next tick, leaving the others alone.
     *
     * @param list<string> $recipients emails, phone numbers — whatever this channel addresses
     */
    public function send(ReportDelivery $delivery, array $recipients): void;
}
