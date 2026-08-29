<?php

namespace Tests\Unit\Tenant;

use App\Mail\SalesReportMail;
use Tests\TestCase;

class SalesReportMailPdfTest extends TestCase
{
    public function test_a4_report_mail_attaches_pdf_and_names_selected_sections(): void
    {
        $pdf = '%PDF-1.7 test';
        $mail = new SalesReportMail(
            'Khatri Biryani',
            '2026-08-26 to 2026-08-26',
            [],
            $pdf,
            'sales-report-2026-08-26.pdf',
            ['overview', 'categories', 'cash_bank'],
        );

        $mail->assertHasAttachedData($pdf, 'sales-report-2026-08-26.pdf', ['mime' => 'application/pdf']);
        $mail->assertSeeInHtml('complete A4 Sales Report Centre report');
        $mail->assertSeeInHtml('Cash Bank');
    }
}
