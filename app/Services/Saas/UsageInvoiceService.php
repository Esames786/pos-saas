<?php

namespace App\Services\Saas;

use App\Models\Master\SubscriptionInvoice;
use App\Models\Master\SubscriptionInvoiceLine;
use App\Models\Master\Tenant;
use App\Models\Master\WebsiteOrderCharge;
use App\Services\Reports\SalesReportEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ONLINE-ORDERING-1 (Phase 4b) — har tenant ki mahana usage invoice: aik invoice, har service ki har din
 * ki aik line (WhatsApp messages; website orders ka 1%).
 *
 * Do usool:
 *  - Total hamesha SUM(lines), har dafa dobara nikala jata hai — joRa nahi jata. Command aadhe me ruke ya
 *    dobara chale, total phir bhi un lines ke barabar jo waqai invoice par hain.
 *  - Har builder sirf APNI line_type chhuta hai. Pehle `billing:whatsapp-invoice` total ko seedha WhatsApp
 *    rows se likhta tha; aik invoice me do service hon to wo commission mita deta.
 */
class UsageInvoiceService
{
    public function __construct(private readonly SubscriptionBillingService $billing) {}

    /** Us mahine ki usage invoice — khuli ya band. Purane command wali shart (draft / issued). */
    public function findInvoice(int $tenantId, CarbonImmutable $monthStart): ?SubscriptionInvoice
    {
        return SubscriptionInvoice::where('tenant_id', $tenantId)
            ->where('invoice_type', 'addon')
            ->where('period_start', $monthStart->toDateString())
            ->whereIn('status', ['draft', 'issued'])
            ->orderBy('id')
            ->first();
    }

    /** Mahine ki invoice dhoondo; na mile to `draft` kholo (mahina chal raha hai, raqam abhi barhegi). */
    public function openInvoice(Tenant $tenant, CarbonImmutable $monthStart): SubscriptionInvoice
    {
        return $this->findInvoice($tenant->id, $monthStart) ?? $this->billing->createInvoice($tenant, [
            'invoice_type' => 'addon',
            'status' => 'draft',
            'subtotal' => 0,
            'period_start' => $monthStart->toDateString(),
            'period_end' => $monthStart->endOfMonth()->toDateString(),
            'notes' => 'Usage — '.$monthStart->format('F Y').': WhatsApp messages aur website orders. '
                .'Mahine ke dauran roz barhti hai; mahina khatam hone par band hoti hai.',
        ]);
    }

    /**
     * Mahina band (invoice draft nahi rahi) to us ke din nahi hilte. Kisi bhi status ki addon invoice
     * dekhi jati hai — paid ya partially paid mahina bhi band hai.
     */
    public function monthIsClosed(int $tenantId, CarbonImmutable $monthStart): bool
    {
        return SubscriptionInvoice::where('tenant_id', $tenantId)
            ->where('invoice_type', 'addon')
            ->where('period_start', $monthStart->toDateString())
            ->whereNotIn('status', ['draft', 'void'])
            ->exists();
    }

    /** WhatsApp ki lines: invoice se juRe messages, din-wise. */
    public function rebuildWhatsAppLines(SubscriptionInvoice $invoice): void
    {
        $days = DB::connection('master')->table('whatsapp_messages')
            ->where('invoice_id', $invoice->id)
            ->groupBy('usage_date')
            ->orderBy('usage_date')
            ->selectRaw('usage_date, COUNT(*) as n, SUM(rate_charged) as amount, MIN(rate_charged) as lo, MAX(rate_charged) as hi')
            ->get();

        $this->replaceLines($invoice, SubscriptionInvoiceLine::TYPE_WHATSAPP, $days->map(function ($d) {
            $sameRate = (float) $d->lo === (float) $d->hi;

            return [
                'usage_date' => substr((string) $d->usage_date, 0, 10),
                'description' => $d->n.' '.($d->n == 1 ? 'message' : 'messages')
                    .($sameRate ? ' × '.$this->num((float) $d->lo) : ''),
                'quantity' => (int) $d->n,
                'base_amount' => null,
                'rate' => $sameRate ? (float) $d->lo : null,
                'rate_unit' => 'per_message',
                'amount' => (float) $d->amount,
            ];
        }));
    }

    /** Website ki lines: invoice se juRe orders aur returns, din-wise. */
    public function rebuildWebsiteLines(SubscriptionInvoice $invoice): void
    {
        $days = DB::connection('master')->table('website_order_charges')
            ->where('invoice_id', $invoice->id)
            ->groupBy('business_date')
            ->orderBy('business_date')
            ->selectRaw("business_date, SUM(kind = 'sale') as orders, SUM(kind = 'return') as returns,
                         SUM(net_amount) as base, SUM(amount) as amount, MIN(rate_percent) as lo, MAX(rate_percent) as hi")
            ->get();

        $this->replaceLines($invoice, SubscriptionInvoiceLine::TYPE_WEBSITE, $days->map(function ($d) {
            $sameRate = (float) $d->lo === (float) $d->hi;
            $parts = [(int) $d->orders.' '.((int) $d->orders === 1 ? 'order' : 'orders')];
            if ((int) $d->returns > 0) {
                $parts[] = (int) $d->returns.' '.((int) $d->returns === 1 ? 'return' : 'returns');
            }
            $parts[] = 'net sale '.number_format((float) $d->base, 2);
            if ($sameRate) {
                $parts[] = $this->num((float) $d->lo).'%';
            }

            return [
                'usage_date' => substr((string) $d->business_date, 0, 10),
                'description' => implode(' · ', $parts),
                'quantity' => (int) $d->orders,
                'base_amount' => (float) $d->base,
                'rate' => $sameRate ? (float) $d->lo : null,
                'rate_unit' => 'percent',
                'amount' => (float) $d->amount,
            ];
        }));
    }

    /**
     * Invoice = us ke record ka nateeja. Dono service ki lines apne record se dobara (WhatsApp: juRe messages;
     * website: juRa record), phir total. Jo bhi command chale, invoice kabhi aadhi nahi banti — warna website
     * wala command kisi purani WhatsApp invoice ka total, jis ki lines abhi bani hi nahi, 0 likh deta.
     */
    public function refresh(SubscriptionInvoice $invoice): void
    {
        $this->rebuildWhatsAppLines($invoice);
        $this->rebuildWebsiteLines($invoice);
        $this->refreshTotal($invoice);
    }

    /** Total = SUM(lines), 2 decimal par. Discount/tax wohi formula jo `createInvoice` ka hai. */
    private function refreshTotal(SubscriptionInvoice $invoice): void
    {
        $subtotal = round((float) SubscriptionInvoiceLine::where('invoice_id', $invoice->id)->sum('amount'), 2);
        $total = max($subtotal - (float) $invoice->discount_amount + (float) $invoice->tax_amount, 0);

        $invoice->update([
            'subtotal' => $subtotal,
            'total_amount' => $total,
            'balance_amount' => $total - (float) $invoice->paid_amount,
        ]);
    }

    /**
     * Tenant ke website orders ko master ke record me utaro (tenant pehle se activate ho). Raqam wohi jo
     * sales report ka "net sale" hai: Paid / Partially Returned / Returned orders apne business din par,
     * returns apne din par minus. Band mahine nahi chhue jate; un ke din par nayi row aaye to sirf gini
     * jati hai (`late`), likhi nahi jati.
     *
     * @return array{rows: Collection, late: int}
     */
    public function websiteChargesFor(Tenant $tenant, CarbonImmutable $from, CarbonImmutable $to, float $percent): array
    {
        $day = 'COALESCE(o.business_date, DATE(o.sale_date))';
        $sales = DB::connection('tenant')->table('sales_orders as o')
            ->where('o.order_source', 'website')
            ->whereIn('o.status', SalesReportEngine::POPULATION)
            ->whereRaw("{$day} between ? and ?", [$from->toDateString(), $to->toDateString()])
            ->selectRaw("o.id, o.sale_no, o.grand_total, {$day} as day")
            ->get()
            ->map(fn ($r) => ['kind' => 'sale', 'source_id' => (int) $r->id, 'sale_no' => $r->sale_no,
                'business_date' => substr((string) $r->day, 0, 10), 'net_amount' => (float) $r->grand_total]);

        $rday = 'COALESCE(r.business_date, DATE(r.return_date))';
        $returns = DB::connection('tenant')->table('sales_returns as r')
            ->join('sales_orders as o', 'o.id', '=', 'r.sales_order_id')
            ->where('o.order_source', 'website')
            ->where('r.status', 'posted')
            ->whereRaw("{$rday} between ? and ?", [$from->toDateString(), $to->toDateString()])
            ->selectRaw("r.id, o.sale_no, r.grand_total, {$rday} as day")
            ->get()
            ->map(fn ($r) => ['kind' => 'return', 'source_id' => (int) $r->id, 'sale_no' => $r->sale_no,
                'business_date' => substr((string) $r->day, 0, 10), 'net_amount' => -1 * (float) $r->grand_total]);

        $closed = [];
        $late = 0;
        $rows = $sales->concat($returns)->filter(function ($r) use ($tenant, &$closed, &$late) {
            $ym = substr($r['business_date'], 0, 7);
            $closed[$ym] ??= $this->monthIsClosed($tenant->id, CarbonImmutable::parse($ym.'-01'));
            if ($closed[$ym]) {
                $late += WebsiteOrderCharge::where('tenant_id', $tenant->id)->where('kind', $r['kind'])
                    ->where('source_id', $r['source_id'])->exists() ? 0 : 1;

                return false;
            }

            return true;
        })->map(fn ($r) => $r + [
            'rate_percent' => $percent,
            'amount' => round($r['net_amount'] * $percent / 100, 4),
        ])->values();

        return ['rows' => $rows, 'late' => $late];
    }

    /**
     * Khule mahinon ka record dobara likho aur har mahine ki invoice par website ki lines. Jo record band
     * mahine ki invoice se juRa hai wo nahi hilta.
     *
     * @param  Collection<int, array<string, mixed>>  $rows  `websiteChargesFor()` se
     * @return list<SubscriptionInvoice> jin invoices ki lines bani
     */
    public function writeWebsiteCharges(Tenant $tenant, CarbonImmutable $from, CarbonImmutable $to, Collection $rows): array
    {
        return DB::connection('master')->transaction(function () use ($tenant, $from, $to, $rows) {
            // Khule din: purana record mita kar naya. Band invoice wala record (aur us ke din) nahi chhua jata.
            WebsiteOrderCharge::where('tenant_id', $tenant->id)
                ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
                ->where(fn ($q) => $q->whereNull('invoice_id')
                    ->orWhereIn('invoice_id', SubscriptionInvoice::where('tenant_id', $tenant->id)->where('status', 'draft')->select('id')))
                ->delete();

            $touched = [];
            foreach ($rows->groupBy(fn ($r) => substr($r['business_date'], 0, 7)) as $ym => $monthRows) {
                $invoice = $this->openInvoice($tenant, CarbonImmutable::parse($ym.'-01'));
                foreach ($monthRows as $r) {
                    WebsiteOrderCharge::updateOrCreate(
                        ['tenant_id' => $tenant->id, 'kind' => $r['kind'], 'source_id' => $r['source_id']],
                        ['sale_no' => $r['sale_no'], 'business_date' => $r['business_date'], 'net_amount' => $r['net_amount'],
                            'rate_percent' => $r['rate_percent'], 'amount' => $r['amount'], 'invoice_id' => $invoice->id],
                    );
                }
                $touched[$invoice->id] = $invoice;
            }

            // Jin khuli invoices ka website record ab khaali ho gaya (order cancel hua) un ki lines bhi saaf.
            $drafts = SubscriptionInvoice::where('tenant_id', $tenant->id)->where('invoice_type', 'addon')->where('status', 'draft')
                ->whereBetween('period_start', [$from->startOfMonth()->toDateString(), $to->toDateString()])
                ->get();
            foreach ($drafts as $draft) {
                $touched[$draft->id] ??= $draft;
            }

            foreach ($touched as $invoice) {
                $this->refresh($invoice);
            }

            return array_values($touched);
        });
    }

    /** @param  Collection<int, array<string, mixed>>  $lines */
    private function replaceLines(SubscriptionInvoice $invoice, string $type, Collection $lines): void
    {
        SubscriptionInvoiceLine::where('invoice_id', $invoice->id)->where('line_type', $type)->delete();
        foreach ($lines as $line) {
            SubscriptionInvoiceLine::create($line + ['invoice_id' => $invoice->id, 'line_type' => $type]);
        }
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
    }
}
