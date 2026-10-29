<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Master\CompanyAccount;
use App\Models\Master\CompanyEntry;
use App\Models\Master\CompanyPartner;
use App\Services\Saas\CompanyHisab;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * MASTER-HISAB-1 — Master → Hisab: Bingoo ka apna paisa, aik safhe par. Plan: docs/plans/master-hisab-2026-10-29.md.
 *
 * Aik safha, aik modal (Add = Edit). Save / delete fetch se JSON lete hain, taake ghalti par form ka likha hua
 * na mite; JSON na maanga ho to wapas safhe par.
 */
class HisabController extends Controller
{
    public function __construct(private CompanyHisab $hisab)
    {
    }

    public function index(Request $request)
    {
        $month = $request->filled('month') && preg_match('/^\d{4}-\d{2}$/', (string) $request->input('month'))
            ? CarbonImmutable::parse($request->input('month').'-01')
            : CarbonImmutable::now()->startOfMonth();
        $from = $month->toDateString();
        $to = $month->endOfMonth()->toDateString();

        $accounts = CompanyAccount::query()->with('partner')->orderBy('sort_order')->orderBy('id')->get();
        $partners = CompanyPartner::query()->orderBy('id')->get();
        $balances = $this->hisab->balances();

        $accountId = $request->integer('account') ?: null;
        $account = $accountId ? $accounts->firstWhere('id', $accountId) : null;
        $showDeleted = $request->boolean('deleted');

        $entries = CompanyEntry::query()
            ->with(['lines.account', 'lines.toAccount', 'tenant', 'creator', 'updater', 'logs'])
            ->whereBetween('entry_date', [$from, $to])
            ->when(! $showDeleted, fn ($q) => $q->whereNull('deleted_at'))
            ->when($account, fn ($q) => $q->whereHas('lines', fn ($l) => $l->where('account_id', $account->id)->orWhere('to_account_id', $account->id)))
            ->orderBy('entry_date')->orderBy('id')
            ->get();

        return view('central.hisab.index', [
            'month'          => $month,
            'prevMonth'      => $month->subMonth()->format('Y-m'),
            'nextMonth'      => $month->addMonth()->format('Y-m'),
            'accounts'       => $accounts,
            'partners'       => $partners,
            'balances'       => $balances,
            'monthBalances'  => $this->hisab->balances($to),
            'period'         => $this->hisab->period($from, $to),
            'settlement'     => $this->hisab->settlement($to),
            'trend'          => $this->hisab->trend($month),
            'whatsapp'       => $this->hisab->whatsapp($from, $to),
            'entries'        => $entries,
            'fingerprints'   => $entries->mapWithKeys(fn ($e) => [$e->id => $this->hisab->fingerprint($e)]),
            'account'        => $account,
            'statement'      => $account ? $this->hisab->statement($account->id, $from, $to) : null,
            'showDeleted'    => $showDeleted,
            'deletedCount'   => CompanyEntry::query()->whereBetween('entry_date', [$from, $to])->whereNotNull('deleted_at')->count(),
            'tenants'        => DB::connection('master')->table('tenants')->orderBy('business_name')->get(['id', 'business_name', 'tenant_code']),
            'userNames'      => DB::connection('master')->table('central_users')->pluck('name', 'id'),
            'categories'     => CompanyEntry::CATEGORIES,
        ]);
    }

    public function storeEntry(Request $request): JsonResponse|RedirectResponse
    {
        return $this->saveEntry($request, null);
    }

    public function updateEntry(Request $request, CompanyEntry $entry): JsonResponse|RedirectResponse
    {
        return $this->saveEntry($request, $entry);
    }

    public function destroyEntry(Request $request, CompanyEntry $entry): JsonResponse|RedirectResponse
    {
        $this->hisab->remove($entry, (string) $request->input('reason', ''), auth('central')->id());
        $message = "{$entry->entry_no} hata di gayi.";

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'message' => $message])
            : redirect()->route('central.hisab.index', ['month' => $entry->entry_date->format('Y-m')])->with('status', $message);
    }

    public function updateSetup(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'partners'                 => ['array'],
            'partners.*.key'           => ['required', 'string', 'max:20'],
            'partners.*.id'            => ['nullable', 'integer'],
            'partners.*.name'          => ['required', 'string', 'max:100'],
            'partners.*.share_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'partners.*.is_active'     => ['nullable', 'boolean'],
            'accounts'                 => ['array'],
            'accounts.*.id'            => ['nullable', 'integer'],
            'accounts.*.name'          => ['required', 'string', 'max:100'],
            'accounts.*.partner_key'   => ['nullable', 'string', 'max:20'],
            'accounts.*.is_active'     => ['nullable', 'boolean'],
        ], [
            'partners.*.name.required'          => 'Owner ka naam likhein.',
            'partners.*.share_percent.required' => 'Owner ka hissa (%) likhein.',
            'accounts.*.name.required'          => 'Khate ka naam likhein.',
        ]);

        $this->hisab->saveSetup(array_values($data['partners'] ?? []), array_values($data['accounts'] ?? []));

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'message' => 'Owners aur khate save ho gaye.'])
            : redirect()->route('central.hisab.index')->with('status', 'Owners aur khate save ho gaye.');
    }

    private function saveEntry(Request $request, ?CompanyEntry $entry): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'type'                  => ['required', 'in:'.implode(',', CompanyEntry::TYPES)],
            'entry_date'            => ['required', 'date_format:Y-m-d'],
            'tenant_id'             => ['nullable', 'integer', 'exists:master.tenants,id'],
            'note'                  => ['nullable', 'string', 'max:2000'],
            'usd_amount'            => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'bill_amount'           => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'bank_fee'              => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'message_count'         => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'lines'                 => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.account_id'    => ['nullable', 'integer'],
            'lines.*.to_account_id' => ['nullable', 'integer'],
            'lines.*.category'      => ['nullable', 'string', 'max:40'],
            'lines.*.amount'        => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            '_was'                  => ['nullable', 'string'],
        ], [
            'entry_date.required' => 'Tareekh chunein.',
            'lines.required'      => 'Kam se kam ek raqam likhein.',
        ]);

        $result = $this->hisab->save($data, $entry, auth('central')->id());
        $saved = $result['entry'];
        $message = ($entry ? 'Badal di gayi: ' : 'Darj ho gayi: ').$saved->entry_no.' — Rs '.number_format($saved->total(), 2);
        if ($result['rate_updated'] !== null) {
            $message .= '. Billing rates: hamara WhatsApp kharcha ab Rs '.rtrim(rtrim(number_format($result['rate_updated'], 4), '0'), '.').' fi message.';
        }

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'message' => $message, 'month' => $saved->entry_date->format('Y-m')])
            : redirect()->route('central.hisab.index', ['month' => $saved->entry_date->format('Y-m')])->with('status', $message);
    }
}
