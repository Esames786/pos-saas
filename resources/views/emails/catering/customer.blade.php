<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; margin: 0; padding: 24px; background: #f7f7f8;">
<div style="max-width: 640px; margin: 0 auto; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
    <div style="background: #111827; color: #ffffff; padding: 18px 24px;">
        <div style="font-size: 18px; font-weight: bold;">{{ $businessName }}</div>
        <div style="font-size: 12px; color: #9ca3af;">Catering &amp; Events</div>
    </div>
    <div style="padding: 24px;">
        <p style="margin-top: 0;">Dear {{ $event->customer_name }},</p>

        @switch($emailType)
            @case('booking_confirmed')
                <p>Your booking has been <strong>confirmed</strong>. We look forward to serving you.</p>
                @break
            @case('quotation_sent')
                <p>Please find your catering quotation below. Reply to this email to confirm or discuss any changes.</p>
                @break
            @case('quotation_revised')
                <p>Your quotation has been <strong>revised</strong>. The updated version is summarised below and replaces the previous one.</p>
                @break
            @case('advance_received')
                <p>Thank you — we have received your advance of <strong>{{ number_format((float) ($context['advance_amount'] ?? 0), 2) }}</strong>.</p>
                @break
            @case('event_reminder')
                <p>A friendly reminder about your upcoming event.</p>
                @break
            @case('final_invoice')
                <p>Thank you for celebrating with us. Your final invoice <strong>{{ $context['invoice_no'] ?? '' }}</strong> is summarised below.</p>
                @break
        @endswitch

        <table style="width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 14px;">
            <tr><td style="padding: 6px 0; color: #6b7280; width: 160px;">Event #</td><td style="padding: 6px 0;"><strong>{{ $event->event_no }}</strong></td></tr>
            @if($event->event_type)<tr><td style="padding: 6px 0; color: #6b7280;">Event</td><td style="padding: 6px 0;">{{ $event->event_type }}</td></tr>@endif
            <tr><td style="padding: 6px 0; color: #6b7280;">Date</td><td style="padding: 6px 0;">{{ $event->event_date->format('l, d F Y') }}@if($event->service_time) — {{ \Carbon\Carbon::parse($event->service_time)->format('g:i A') }}@endif</td></tr>
            @if($event->venue)<tr><td style="padding: 6px 0; color: #6b7280;">Venue</td><td style="padding: 6px 0;">{{ $event->venue }}</td></tr>@endif
            <tr><td style="padding: 6px 0; color: #6b7280;">Guests (PAX)</td><td style="padding: 6px 0;">{{ number_format($event->pax) }}</td></tr>
        </table>

        @if($estimate && $estimate->lines->isNotEmpty())
            <table style="width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 16px;">
                <thead>
                    <tr style="background: #f3f4f6;">
                        <th style="text-align: left; padding: 8px; border-bottom: 1px solid #e5e7eb;">Item</th>
                        <th style="text-align: right; padding: 8px; border-bottom: 1px solid #e5e7eb;">Qty</th>
                        <th style="text-align: right; padding: 8px; border-bottom: 1px solid #e5e7eb;">Rate</th>
                        <th style="text-align: right; padding: 8px; border-bottom: 1px solid #e5e7eb;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($estimate->lines as $line)
                    <tr>
                        <td style="padding: 6px 8px; border-bottom: 1px solid #f3f4f6;">
                            {{ $line->item_name }}
                            @if($line->item_name_ur)<div dir="rtl" lang="ur" style="color: #6b7280;">{{ $line->item_name_ur }}</div>@endif
                        </td>
                        <td style="padding: 6px 8px; text-align: right; border-bottom: 1px solid #f3f4f6;">{{ rtrim(rtrim(number_format($line->quantity, 3), '0'), '.') }} {{ $line->unit_code }}</td>
                        <td style="padding: 6px 8px; text-align: right; border-bottom: 1px solid #f3f4f6;">{{ number_format($line->rate, 2) }}</td>
                        <td style="padding: 6px 8px; text-align: right; border-bottom: 1px solid #f3f4f6;">{{ number_format($line->amount, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr><td colspan="3" style="padding: 6px 8px; text-align: right; color: #6b7280;">Subtotal</td><td style="padding: 6px 8px; text-align: right;">{{ number_format($estimate->subtotal, 2) }}</td></tr>
                    @if($estimate->service_charge_amount > 0)
                        <tr><td colspan="3" style="padding: 6px 8px; text-align: right; color: #6b7280;">Service Charges</td><td style="padding: 6px 8px; text-align: right;">{{ number_format($estimate->service_charge_amount, 2) }}</td></tr>
                    @endif
                    @if($estimate->other_charge_amount > 0)
                        <tr><td colspan="3" style="padding: 6px 8px; text-align: right; color: #6b7280;">{{ $estimate->other_charge_label ?? 'Other Charges' }}</td><td style="padding: 6px 8px; text-align: right;">{{ number_format($estimate->other_charge_amount, 2) }}</td></tr>
                    @endif
                    @if($estimate->discount_amount > 0)
                        <tr><td colspan="3" style="padding: 6px 8px; text-align: right; color: #6b7280;">Discount</td><td style="padding: 6px 8px; text-align: right;">-{{ number_format($estimate->discount_amount, 2) }}</td></tr>
                    @endif
                    @if($estimate->tax_amount > 0)
                        <tr><td colspan="3" style="padding: 6px 8px; text-align: right; color: #6b7280;">Tax</td><td style="padding: 6px 8px; text-align: right;">{{ number_format($estimate->tax_amount, 2) }}</td></tr>
                    @endif
                    <tr><td colspan="3" style="padding: 8px; text-align: right; font-weight: bold; border-top: 2px solid #111827;">Net Total</td><td style="padding: 8px; text-align: right; font-weight: bold; border-top: 2px solid #111827;">{{ number_format($estimate->grand_total, 2) }}</td></tr>
                    @if(($context['advance_total'] ?? 0) > 0)
                        <tr><td colspan="3" style="padding: 6px 8px; text-align: right; color: #6b7280;">Advance Received</td><td style="padding: 6px 8px; text-align: right;">{{ number_format((float) $context['advance_total'], 2) }}</td></tr>
                        <tr><td colspan="3" style="padding: 6px 8px; text-align: right; font-weight: bold;">Balance</td><td style="padding: 6px 8px; text-align: right; font-weight: bold;">{{ number_format($estimate->grand_total - (float) $context['advance_total'], 2) }}</td></tr>
                    @endif
                </tfoot>
            </table>
        @endif

        <p style="color: #6b7280; font-size: 13px;">If anything looks incorrect, simply reply to this email.</p>
        <p style="margin-bottom: 0;">Warm regards,<br><strong>{{ $businessName }}</strong></p>
    </div>
</div>
</body>
</html>
