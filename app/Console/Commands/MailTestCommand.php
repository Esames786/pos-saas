<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * PROD-READINESS-1 — verify the mail pipeline without exposing secrets.
 *
 *   php artisan mail:test ops@example.com
 */
class MailTestCommand extends Command
{
    protected $signature = 'mail:test {recipient : Email address to send the test message to}';

    protected $description = 'Send a simple test email to verify MAIL_* configuration';

    public function handle(): int
    {
        $recipient = (string) $this->argument('recipient');

        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid recipient email address.');

            return self::FAILURE;
        }

        $mailer = config('mail.default');
        $host   = config('mail.mailers.smtp.host');
        $from   = config('mail.from.address');

        $this->line("Mailer: <info>{$mailer}</info>" . ($mailer === 'smtp' ? " (host: {$host})" : ''));
        $this->line("From:   <info>{$from}</info>");

        if ($mailer === 'log') {
            $this->warn('MAIL_MAILER=log — the message will be written to storage/logs, NOT actually sent.');
        }

        try {
            Mail::raw(
                "This is a Bingoo POS mail configuration test sent at " . now()->toDateTimeString() . " (" . config('app.url') . ").\n\n"
                . "If you received this, outgoing email works.",
                function ($message) use ($recipient) {
                    $message->to($recipient)->subject('Bingoo POS — mail configuration test');
                }
            );
        } catch (\Throwable $e) {
            // Never echo the exception verbatim — SMTP DSNs can embed credentials.
            $this->error('Send FAILED: ' . preg_replace('/\/\/.*?@/', '//***@', $e->getMessage()));

            return self::FAILURE;
        }

        $this->info('Test email dispatched successfully to ' . $recipient . '.');
        $this->line($mailer === 'log'
            ? 'Check storage/logs/laravel-*.log for the message body.'
            : 'Check the recipient inbox (and spam folder).');

        return self::SUCCESS;
    }
}
