<?php

namespace App\Services\Reports\Delivery;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * WHATSAPP-REPORT-CHANNEL-1 — the whole of the WhatsApp Cloud API, as far as we need it.
 *
 * One POST. No SDK, no package, no BSP: a Business Solution Provider wanted PKR 14,000 a month to
 * wrap this call in a dashboard we would never open.
 */
class WhatsAppClient
{
    /**
     * Bhejta hai aur Meta ka `wamid` wapis karta hai.
     *
     * Pehle ye void tha. wamid is liye darkar hai ke usage ki row usi se webhook ke jawab (delivered
     * / read / failed) se juRti hai — us ke baghair "pohancha ya nahi" ka jawab kabhi nahi milta,
     * aur bill "accepted" par banta rehta.
     */
    public function sendTemplate(string $to, string $template, string $language, array $components): ?string
    {
        $token = (string) config('services.whatsapp.token');
        $phoneId = (string) config('services.whatsapp.phone_number_id');

        if ($token === '' || $phoneId === '') {
            throw new RuntimeException('WhatsApp is not configured (token / phone number id missing).');
        }

        $response = Http::withToken($token)
            ->timeout(20)
            // Retry only what retrying can fix: a network blip, or Meta being briefly down.
            // A 4xx is a verdict on THIS request — a wrong number stays wrong — so repeating it
            // only doubles the wait before the real reason surfaces, and makes one bad entry look
            // like two in the logs.
            ->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false)
            ->post(sprintf(
                '%s/%s/%s/messages',
                rtrim((string) config('services.whatsapp.base_url'), '/'),
                (string) config('services.whatsapp.version'),
                $phoneId,
            ), [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'template',
                'template' => [
                    'name' => $template,
                    'language' => ['code' => $language],
                    'components' => $components,
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException($this->reason($response->status(), (string) $response->body()));
        }

        return $response->json('messages.0.id');
    }

    /**
     * Meta's error body echoes the whole request — including the Authorization header on some error
     * shapes. Logging it raw would put a permanent token into last_failure and the log file, where it
     * would sit readable by anyone who can see either. So only the message and codes come out.
     */
    private function reason(int $status, string $body): string
    {
        $decoded = json_decode($body, true);
        $error = is_array($decoded) ? ($decoded['error'] ?? []) : [];

        $parts = array_filter([
            'HTTP '.$status,
            $error['message'] ?? null,
            isset($error['code']) ? 'code '.$error['code'] : null,
            isset($error['error_subcode']) ? 'subcode '.$error['error_subcode'] : null,
        ]);

        return 'WhatsApp send failed: '.implode(' · ', $parts ?: ['HTTP '.$status]);
    }
}
