<?php

namespace App\Services\OnlineOrdering;

use App\Models\Master\WhatsAppMessage;
use App\Models\Tenant\Branch;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\WebSetting;
use App\Services\Reports\Delivery\WhatsAppClient;
use App\Services\Saas\BillingRates;
use App\Services\Tenancy\TenancyManager;
use App\Support\TenantClock;
use Illuminate\Support\Facades\DB;

/**
 * ONLINE-ORDERING-1 (Phase 4) — customer ko WhatsApp: order mila (+ Confirm link), confirmed, cancelled.
 *
 * Malik: WhatsApp ikhtiyari service hai — client har status ka message khud on/off kare. Har message
 * usi register (`whatsapp_messages`, source `website`) me usi rate par likha jata hai — bill par WhatsApp
 * ki line, 1% commission se alag.
 *
 * Kabhi order ko nahi rokta: WhatsApp na chale (token nahi, template abhi approve nahi, number ghalat) to
 * register me "failed" likh kar aage. Aur cashier/customer ko intezar nahi karwata: commit ke baad, jawab
 * bhej dene ke baad bhejta hai. Har message aik dafa (web_meta.wa me likha jata hai).
 */
class WebsiteOrderNotifier
{
    public const TEMPLATE_RECEIVED = 'web_order_received';

    public const TEMPLATE_CONFIRMED = 'web_order_confirmed';

    public const TEMPLATE_CANCELLED = 'web_order_cancelled';

    public function __construct(
        private readonly WhatsAppClient $client,
        private readonly BillingRates $rates,
        private readonly TenantClock $clock,
    ) {}

    /**
     * Commit ke baad, aur web request me jawab bhej dene ke BAAD. Tenant saath le jata hai — console
     * (auto-cancel kai tenants par chalta hai) me foran, apne tenant ke saath.
     */
    public static function later(string $event, int $saleId): void
    {
        if (! app()->bound('tenant')) {
            return;
        }
        $tenant = app('tenant');
        $run = function () use ($tenant, $event, $saleId) {
            try {
                app(TenancyManager::class)->activate($tenant);
                app(self::class)->send($event, $saleId);
            } catch (\Throwable $e) {
                report($e);
            }
        };

        DB::connection('tenant')->afterCommit(function () use ($run) {
            app()->runningInConsole() ? $run() : app()->terminating($run);
        });
    }

    public function send(string $event, int $saleId): void
    {
        $sale = SalesOrder::find($saleId);
        // Cashier ne recall par phone ka khana khali chhora ho to customer ka apna number.
        $customerPhone = $sale ? ($sale->customer_phone ?: $sale->customer?->phone) : null;
        if (! $sale || $sale->order_source !== 'website' || ! $customerPhone) {
            return;
        }
        $settings = WebSetting::tenantDefault();
        $wa = data_get($sale->web_meta, 'wa', []);
        if (isset($wa[$event])) {
            return;   // aik dafa
        }

        $tenant = app('tenant');
        $business = (string) $tenant->business_name;
        $name = trim((string) $sale->customer_name) ?: 'there';
        $phone = $settings->contact_phone ?: 'the restaurant';
        $total = number_format((float) $sale->grand_total, 0);

        [$enabled, $template, $components] = match ($event) {
            'received' => [$settings->wa_notify_received, self::template('received'), [
                $this->body([$name, $business, $sale->sale_no, $total]),
                ['type' => 'button', 'sub_type' => 'url', 'index' => '0',
                    'parameters' => [['type' => 'text', 'text' => self::linkCode($tenant->tenant_code, (string) $sale->web_token)]]],
            ]],
            'confirmed' => [$settings->wa_notify_confirmed, self::template('confirmed'), [
                $this->body([$name, $sale->sale_no, $business, (string) (data_get($sale->web_meta, 'eta_minutes') ?: $settings->default_eta_minutes), $phone]),
            ]],
            'cancelled' => [$settings->wa_notify_cancelled, self::template('cancelled'), [
                $this->body([$name, $sale->sale_no, $business, $phone]),
            ]],
            default => [false, null, []],
        };
        if (! $enabled || ! $template) {
            return;
        }
        // WhatsApp lagaya hi nahi (token / number nahi) to bhejne ki koshish hi nahi hui — na row, na bill.
        if (! config('services.whatsapp.token') || ! config('services.whatsapp.phone_number_id')) {
            return;
        }

        $to = self::international((string) $customerPhone);
        $row = [
            'tenant_id' => $tenant->id, 'source' => 'website', 'template' => $template, 'to' => $to,
            'usage_date' => now($this->clock->businessTimezone(Branch::find($sale->branch_id)))->toDateString(),
            'sent_at' => now(),
            'rate_charged' => $this->rates->whatsappRatePkr(),
            'provider_cost' => $this->rates->whatsappCostPkr(),
        ];

        try {
            $wamid = $this->client->sendTemplate($to, $template, 'en', $components);
            WhatsAppMessage::create($row + ['wamid' => $wamid, 'status' => 'accepted']);
            $mark = ['wamid' => $wamid, 'at' => now()->toIso8601String()];
        } catch (\Throwable $e) {
            WhatsAppMessage::create($row + ['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 500)]);
            $mark = ['failed' => true, 'at' => now()->toIso8601String()];
        }

        // Query builder se — model event dobara na chale.
        $meta = $sale->web_meta ?: [];
        $meta['wa'] = ($meta['wa'] ?? []) + [$event => $mark];
        SalesOrder::whereKey($sale->id)->update(['web_meta' => json_encode($meta)]);
    }

    /** Meta par approve hua naam (config `services.whatsapp.web_templates`), warna yahan wala. */
    public static function template(string $event): string
    {
        $default = ['received' => self::TEMPLATE_RECEIVED, 'confirmed' => self::TEMPLATE_CONFIRMED, 'cancelled' => self::TEMPLATE_CANCELLED][$event];

        return (string) (config('services.whatsapp.web_templates.'.$event) ?: $default);
    }

    /** Button ka hissa: `bingoopos.com/o/{tenant}.{token}` — central link tenant tak le jata hai. */
    public static function linkCode(string $tenantCode, string $token): string
    {
        return $tenantCode.'.'.$token;
    }

    /** 0300 1234567 → 923001234567 (Meta ko country code chahiye). */
    public static function international(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($d, '0')) {
            return '92'.substr($d, 1);
        }

        return strlen($d) === 10 && str_starts_with($d, '3') ? '92'.$d : $d;
    }

    private function body(array $values): array
    {
        return ['type' => 'body', 'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], $values)];
    }
}
