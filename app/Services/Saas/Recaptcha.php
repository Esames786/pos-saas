<?php

namespace App\Services\Saas;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RECAPTCHA-TRIAL-1 — Google reCAPTCHA v2 ("I'm not a robot" checkbox) on the public Start Trial form.
 *
 * Off until BOTH keys are configured: no box on the page and no check on the server, so local
 * development and the test suite never talk to Google.
 *
 * On, it fails CLOSED for the visitor: a missing token, a "no" from Google, or Google not answering
 * all refuse the signup (the visitor ticks the box again), logged with Google's reason. The one
 * exception is a wrong SECRET key ("invalid-input-secret") — our mistake, not the visitor's — which
 * lets the signup through and logs an ERROR, so a bad key can never shut the front door.
 */
class Recaptcha
{
    public static function siteKey(): ?string
    {
        return self::enabled() ? trim((string) config('saas.recaptcha.site_key')) : null;
    }

    public static function enabled(): bool
    {
        return trim((string) config('saas.recaptcha.site_key')) !== ''
            && trim((string) config('saas.recaptcha.secret_key')) !== '';
    }

    public function passes(?string $token, ?string $ip): bool
    {
        if ($token === null || trim($token) === '') {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(8)->post((string) config('saas.recaptcha.verify_url'), [
                'secret' => trim((string) config('saas.recaptcha.secret_key')),
                'response' => $token,
                'remoteip' => $ip,
            ]);
        } catch (Throwable $e) {
            Log::warning('reCAPTCHA: Google could not be reached — signup refused', ['error' => $e->getMessage()]);

            return false;
        }

        $body = (array) $response->json();
        if (($body['success'] ?? false) === true) {
            return true;
        }

        $codes = (array) ($body['error-codes'] ?? []);
        if (array_intersect($codes, ['invalid-input-secret', 'missing-input-secret'])) {
            // OUR key is wrong, not the visitor's tick: never lock customers out over our own
            // configuration. The signup goes through without the robot check, loudly, until it is fixed.
            Log::error('reCAPTCHA: the server SECRET key is rejected by Google — signup ALLOWED without the robot check. Fix SAAS_RECAPTCHA_SECRET_KEY.', [
                'error_codes' => $codes,
            ]);

            return true;
        }

        Log::warning('reCAPTCHA: check failed — signup refused', [
            'status' => $response->status(),
            'error_codes' => $codes,
        ]);

        return false;
    }
}
